<?php

namespace Tests\Feature\Tracking;

use App\Models\Bounce;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Suppression;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\CampaignRunner;
use App\Services\Campaigns\EmailCompiler;
use App\Services\Campaigns\RecipientGenerator;
use App\Services\Smtp\MailerFactory;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;
use Throwable;

/** Fails every send with whatever it is told to. */
class BouncingTransport implements TransportInterface
{
    public ?Throwable $error = null;

    public function send(RawMessage $message, $envelope = null): ?SentMessage
    {
        if ($this->error) {
            throw $this->error;
        }

        return null;
    }

    public function __toString(): string
    {
        return 'bouncing';
    }
}

class BouncingFactory extends MailerFactory
{
    public BouncingTransport $transport;

    public function __construct()
    {
        $this->transport = new BouncingTransport;
    }

    public function for(SmtpAccount $account, ?int $timeout = null): TransportInterface
    {
        return $this->transport;
    }
}

/**
 * Bounce handling.
 *
 * A hard bounce means the address does not exist, and mailing it again is what
 * costs a sender their reputation. A soft bounce means somebody's mailbox was
 * full on Tuesday. Treating the two the same in either direction is the bug
 * worth guarding against.
 */
class BounceHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected BouncingFactory $factory;

    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@bounce.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 100000,
        ]]);
        $this->owner->account->refresh();

        app(TenantManager::class)->set($this->owner->account_id);

        $this->factory = new BouncingFactory;
        $this->app->instance(MailerFactory::class, $this->factory);

        SmtpAccount::factory()->forAccount($this->owner->account)->create();
    }

    /** Builds a one-contact campaign, optionally reusing an existing address. */
    protected function campaignFor(string $email): Campaign
    {
        $batch = ++$this->seq;
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();

        $subscriber = Subscriber::withTrashed()->firstOrNew(['email' => $email]);
        $subscriber->fill(['first_name' => 'Reader', 'status' => 'active', 'source' => 'manual']);
        $subscriber->account_id = $this->owner->account_id;
        $subscriber->save();
        $subscriber->lists()->syncWithoutDetaching([$list->id => ['subscribed_at' => now()]]);

        $doc = ['settings' => [], 'blocks' => [
            ['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hi']],
            ['id' => 'f', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
        ]];

        $compiler = app(EmailCompiler::class);

        $campaign = Campaign::factory()->forAccount($this->owner->account)->create([
            'name' => "Campaign {$batch}",
            'subject' => 'Hello',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'status' => 'sending',
            'blocks' => $doc,
            'html' => $compiler->compile($doc),
            'plain_text' => $compiler->compileText($doc),
            'audience' => ['lists' => [$list->id]],
        ]);

        app(RecipientGenerator::class)->generate($campaign);

        return $campaign->fresh();
    }

    /**
     * A failure shaped the way Symfony actually reports one.
     *
     * The classifier reads the reply code out of Symfony's message text and
     * the failing command out of the SMTP dialogue — so a fixture that only
     * sets the exception's numeric code proves nothing about the real path.
     * `RCPT TO` is what makes a rejection the recipient's rather than ours.
     */
    protected function failWith(int $code, string $reply, string $command = 'RCPT TO:<contact@example.com>'): void
    {
        $e = new UnexpectedResponseException(
            'Expected response code "250" but got code "'.$code.'", with message "'.$reply.'".'
        );

        $e->appendDebug('> '.$command."
< ".$reply."
");

        $this->factory->transport->error = $e;
    }

    protected function send(Campaign $campaign): void
    {
        app(CampaignRunner::class)->runChunk($campaign);
    }

    // -------------------------------------------------------------- logging

    public function test_a_hard_bounce_is_logged_with_its_code_and_reason(): void
    {
        $this->failWith(550, '550 5.1.1 The email account that you tried to reach does not exist');

        $campaign = $this->campaignFor('missing@example.com');
        $this->send($campaign);

        $bounce = Bounce::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('hard', $bounce->type);
        $this->assertSame('missing@example.com', $bounce->email);
        $this->assertSame('smtp', $bounce->source);
        $this->assertSame($campaign->id, $bounce->campaign_id);
        $this->assertSame($this->owner->account_id, $bounce->account_id);
        $this->assertNotEmpty($bounce->description);
        $this->assertNotNull($bounce->occurred_at);
    }

    public function test_a_soft_bounce_is_logged_as_soft(): void
    {
        $this->failWith(452, '452 4.2.2 The recipient mailbox is over quota');

        $this->send($this->campaignFor('full@example.com'));

        $this->assertSame('soft', Bounce::withoutGlobalScopes()->firstOrFail()->type);
    }

    public function test_our_own_failure_is_never_written_to_a_contacts_bounce_history(): void
    {
        // 535 is an authentication failure: our credentials, not their
        // address, and it happens at AUTH — before any recipient is named.
        $this->failWith(535, '535 5.7.8 Authentication credentials invalid', 'AUTH LOGIN');

        $this->send($this->campaignFor('innocent@example.com'));

        $this->assertSame(0, Bounce::withoutGlobalScopes()->count(),
            'Blaming a contact for our own SMTP misconfiguration would eventually delete them.');
        $this->assertSame(0, Suppression::withoutGlobalScopes()->count());
    }

    // ---------------------------------------------------------- suppression

    public function test_a_hard_bounce_stops_the_address_being_mailed_again(): void
    {
        $this->failWith(550, '550 5.1.1 No such user here');

        $this->send($this->campaignFor('gone@example.com'));

        $suppression = Suppression::withoutGlobalScopes()->where('email', 'gone@example.com')->first();

        $this->assertNotNull($suppression);
        $this->assertSame('hard_bounce', $suppression->reason);
    }

    public function test_a_soft_bounce_does_not_suppress(): void
    {
        $this->failWith(451, '451 4.3.0 Try again later');

        $this->send($this->campaignFor('busy@example.com'));

        $this->assertSame(0, Suppression::withoutGlobalScopes()->count(),
            'A full mailbox is not a dead address; suppressing would delete a good contact.');
    }

    public function test_the_configured_hard_bounce_limit_is_actually_respected(): void
    {
        // The config has always advertised this; nothing read it until now.
        config()->set('knsoftic.hard_bounce_limit', 3);

        $this->failWith(550, '550 5.1.1 No such user here');

        $email = 'repeat@example.com';

        $this->send($this->campaignFor($email));
        $this->assertSame(0, Suppression::withoutGlobalScopes()->count(), 'One of three.');

        $this->send($this->campaignFor($email));
        $this->assertSame(0, Suppression::withoutGlobalScopes()->count(), 'Two of three.');

        $this->send($this->campaignFor($email));

        $this->assertSame(1, Suppression::withoutGlobalScopes()->count(), 'The third one suppresses.');
        $this->assertSame(3, Bounce::withoutGlobalScopes()->where('type', 'hard')->count());
    }

    public function test_a_second_bounce_does_not_create_a_second_suppression(): void
    {
        $this->failWith(550, '550 5.1.1 No such user here');

        $email = 'twice@example.com';

        $this->send($this->campaignFor($email));

        // The address is suppressed now, so a later campaign would skip it —
        // but a bounce arriving anyway must not duplicate the row.
        app(\App\Services\Tracking\BounceRecorder::class);

        $this->send($this->campaignFor($email));

        $this->assertSame(1, Suppression::withoutGlobalScopes()->where('email', $email)->count());
    }

    // ------------------------------------------------------- recipient row

    public function test_the_recipient_row_records_the_bounce_type(): void
    {
        $this->failWith(550, '550 5.1.1 No such user here');

        $campaign = $this->campaignFor('typed@example.com');
        $this->send($campaign);

        $recipient = CampaignRecipient::where('campaign_id', $campaign->id)->firstOrFail();

        $this->assertSame('bounced', $recipient->status);
        $this->assertSame('hard', $recipient->bounce_type);
        $this->assertNotNull($recipient->bounced_at);
        $this->assertSame(1, (int) $campaign->fresh()->bounced_count);
        $this->assertSame(0, (int) $campaign->fresh()->failed_count,
            'A bounce is a bounce, not a generic failure — the two numbers mean different things.');
    }

    public function test_the_history_for_an_address_reads_newest_first(): void
    {
        $this->failWith(550, '550 5.1.1 No such user here');
        config()->set('knsoftic.hard_bounce_limit', 5);

        $email = 'history@example.com';

        $this->send($this->campaignFor($email));
        $this->send($this->campaignFor($email));

        $history = app(\App\Services\Tracking\BounceRecorder::class)
            ->historyFor($email, $this->owner->account_id);

        $this->assertCount(2, $history);
        $this->assertTrue(
            $history->first()->occurred_at->gte($history->last()->occurred_at)
        );
    }
}
