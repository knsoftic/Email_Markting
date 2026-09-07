<?php

namespace Tests\Feature\Smtp;

use App\Models\SmtpAccount;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Smtp\MailerFactory;
use App\Services\Smtp\SendFailure;
use App\Services\Smtp\SmtpSender;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;
use Throwable;

/** A transport that does exactly what the test tells it to. */
class ScriptedTransport implements TransportInterface
{
    public int $sends = 0;

    public function __construct(protected ?Throwable $error = null) {}

    public function send(RawMessage $message, $envelope = null): ?SentMessage
    {
        $this->sends++;

        if ($this->error) {
            throw $this->error;
        }

        return null;
    }

    public function __toString(): string
    {
        return 'scripted';
    }
}

/** Hands out whichever scripted transport the test registered per account. */
class ScriptedFactory extends MailerFactory
{
    /** @var array<int, ScriptedTransport> */
    public array $byAccountId = [];

    public function for(SmtpAccount $account, ?int $timeout = null): TransportInterface
    {
        return $this->byAccountId[$account->id] ??= new ScriptedTransport;
    }
}

class SmtpSenderTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected ScriptedFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@sender.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'allow_smtp_rotation' => true, 'max_smtp_accounts' => 5,
        ]]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->factory = new ScriptedFactory;
        $this->app->instance(MailerFactory::class, $this->factory);
    }

    protected function sender(): SmtpSender
    {
        return new SmtpSender(
            app(\App\Services\Smtp\SmtpSelector::class),
            $this->factory,
            app(\App\Services\Smtp\SendFailureClassifier::class),
        );
    }

    protected function message(): Email
    {
        return (new Email)->from('me@example.test')->to('you@example.test')->subject('Hi')->text('Hello');
    }

    protected function smtp(array $attributes = []): SmtpAccount
    {
        return SmtpAccount::factory()->forAccount($this->owner->account)->create($attributes);
    }

    protected function script(SmtpAccount $account, ?Throwable $error): ScriptedTransport
    {
        return $this->factory->byAccountId[$account->id] = new ScriptedTransport($error);
    }

    // -------------------------------------------------------------- happy

    public function test_a_successful_send_charges_the_account_and_clears_failures(): void
    {
        $account = $this->smtp(['daily_limit' => 10, 'consecutive_failures' => 2]);
        $this->script($account, null);

        $outcome = $this->sender()->send($this->owner->account, $this->message());

        $this->assertTrue($outcome->sent);
        $this->assertSame($account->id, $outcome->smtpAccount->id);
        $this->assertSame('sent', $outcome->recipientStatus());

        $fresh = $account->fresh();
        $this->assertSame(1, (int) $fresh->sent_today);
        $this->assertSame(0, (int) $fresh->consecutive_failures);
        $this->assertNotNull($fresh->last_success_at);
    }

    public function test_one_transport_is_reused_across_a_batch(): void
    {
        $account = $this->smtp();
        $transport = $this->script($account, null);

        $sender = $this->sender();

        for ($i = 0; $i < 5; $i++) {
            $sender->send($this->owner->account, $this->message());
        }

        $this->assertSame(5, $transport->sends);
        $this->assertCount(1, $this->factory->byAccountId, 'One transport, not five.');
        $this->assertSame(5, (int) $account->fresh()->sent_today);
    }

    // ------------------------------------------------------- account fail

    public function test_an_account_level_failure_moves_to_the_next_account(): void
    {
        $bad = $this->smtp(['name' => 'Bad', 'priority' => 0]);
        $good = $this->smtp(['name' => 'Good', 'priority' => 1]);

        $this->script($bad, new TransportException('Connection could not be established with host "x": Connection refused'));
        $this->script($good, null);

        $outcome = $this->sender()->send($this->owner->account, $this->message());

        $this->assertTrue($outcome->sent);
        $this->assertSame('Good', $outcome->smtpAccount->name);

        $this->assertSame(0, (int) $bad->fresh()->sent_today, 'The failed reservation must be given back.');
        $this->assertSame(1, (int) $bad->fresh()->consecutive_failures);
        $this->assertSame(1, (int) $good->fresh()->sent_today);
    }

    public function test_repeated_account_failures_trip_the_cooldown(): void
    {
        config(['knsoftic.smtp_failure_threshold' => 2]);

        $account = $this->smtp();
        $this->script($account, new TransportException('Connection refused'));

        $sender = $this->sender();
        $sender->send($this->owner->account, $this->message());
        $sender->send($this->owner->account, $this->message());

        $fresh = $account->fresh();

        $this->assertNotNull($fresh->cooldown_until);
        $this->assertTrue($fresh->isInCooldown());
    }

    // ----------------------------------------------------- recipient fail

    public function test_a_hard_bounce_is_reported_without_blaming_the_account(): void
    {
        $account = $this->smtp();

        $e = new UnexpectedResponseException(
            'Expected response code "250" but got code "550", with message "550 5.1.1 User unknown".'
        );
        $e->appendDebug("> MAIL FROM:<me@example.test>\r\n< 250 ok\r\n> RCPT TO:<ghost@example.test>\r\n< 550 no\r\n");
        $this->script($account, $e);

        $outcome = $this->sender()->send($this->owner->account, $this->message());

        $this->assertFalse($outcome->sent);
        $this->assertSame('bounced', $outcome->recipientStatus());
        $this->assertTrue($outcome->shouldSuppressRecipient());
        $this->assertSame('hard', $outcome->bounceType());

        $fresh = $account->fresh();

        $this->assertSame(
            0,
            (int) $fresh->consecutive_failures,
            'One dead mailbox must never count against the SMTP account.'
        );
        $this->assertNull($fresh->cooldown_until);
        $this->assertSame(0, (int) $fresh->sent_today, 'A rejected recipient does not consume quota.');
    }

    public function test_a_hard_bounce_is_not_retried_on_another_account(): void
    {
        $first = $this->smtp(['name' => 'First', 'priority' => 0]);
        $second = $this->smtp(['name' => 'Second', 'priority' => 1]);

        $e = new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 User unknown".');
        $e->appendDebug("> RCPT TO:<ghost@example.test>\r\n< 550 no\r\n");

        $this->script($first, $e);
        $secondTransport = $this->script($second, null);

        $outcome = $this->sender()->send($this->owner->account, $this->message());

        $this->assertFalse($outcome->sent);
        $this->assertSame(0, $secondTransport->sends,
            'A non-existent mailbox will not exist on another provider either.');
    }

    public function test_a_soft_bounce_is_retryable_and_does_not_suppress(): void
    {
        $account = $this->smtp();

        $e = new UnexpectedResponseException('Expected response code "250" but got code "452", with message "452 Mailbox full".');
        $e->appendDebug("> RCPT TO:<full@example.test>\r\n< 452 full\r\n");
        $this->script($account, $e);

        $outcome = $this->sender()->send($this->owner->account, $this->message());

        $this->assertSame('bounced', $outcome->recipientStatus());
        $this->assertSame('soft', $outcome->bounceType());
        $this->assertFalse($outcome->shouldSuppressRecipient());
        $this->assertTrue($outcome->isRetryable());
    }

    // ---------------------------------------------------------- exhausted

    public function test_no_capacity_is_a_deferral_not_a_failure(): void
    {
        $account = SmtpAccount::factory()->forAccount($this->owner->account)->exhausted(5)->create();
        $this->script($account, null);

        $outcome = $this->sender()->send($this->owner->account, $this->message());

        $this->assertFalse($outcome->sent);
        $this->assertTrue($outcome->deferred);
        $this->assertSame('pending', $outcome->recipientStatus(),
            'A quota pause must leave the recipient queued, not marked failed.');
        $this->assertTrue($outcome->isRetryable());
        $this->assertNull($outcome->failure);
    }

    public function test_an_account_with_no_smtp_at_all_says_so(): void
    {
        $outcome = $this->sender()->send($this->owner->account, $this->message());

        $this->assertFalse($outcome->sent);
        $this->assertFalse($outcome->deferred);
        $this->assertStringContainsString('No SMTP account is available', (string) $outcome->reason);
    }

    // -------------------------------------------------------------- usage

    public function test_usage_is_written_once_on_flush(): void
    {
        $account = $this->smtp();
        $this->script($account, null);

        $sender = $this->sender();

        for ($i = 0; $i < 3; $i++) {
            $sender->send($this->owner->account, $this->message());
        }

        $this->assertSame(0, DB::table('smtp_usage')->count());

        $sender->flush();

        $row = DB::table('smtp_usage')->first();

        $this->assertSame(3, (int) $row->sent);
        $this->assertSame($this->owner->account_id, (int) $row->account_id);
    }
}
