<?php

namespace Tests\Feature\Automation;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
use App\Models\Bounce;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Automation\AutomationEnroller;
use App\Services\Automation\AutomationRunner;
use App\Services\Smtp\MailerFactory;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;
use Throwable;

/** Records what it was asked to send, and fails on command. */
class AutomationTransport implements TransportInterface
{
    /** @var array<int, string> */
    public array $sentTo = [];

    /** @var array<int, string> */
    public array $raw = [];

    public ?Throwable $error = null;

    public function send(RawMessage $message, $envelope = null): ?SentMessage
    {
        $body = $message->toString();
        preg_match('/^To:\s*(.+)$/mi', $body, $m);

        $this->sentTo[] = trim($m[1] ?? 'unknown');
        $this->raw[] = $body;

        if ($this->error) {
            throw $this->error;
        }

        return null;
    }

    public function __toString(): string
    {
        return 'automation-recording';
    }
}

class AutomationMailerFactory extends MailerFactory
{
    public AutomationTransport $transport;

    public function __construct()
    {
        $this->transport = new AutomationTransport;
    }

    public function for(SmtpAccount $account, ?int $timeout = null): TransportInterface
    {
        return $this->transport;
    }
}

/**
 * The automation runner.
 *
 * These exist for one reason: the failures here are silent and repeat. An
 * automation that mails somebody twice does it to everybody, every week, and
 * nobody notices until a customer complains — so double-sending, re-entry,
 * infinite chains and opt-out-mid-sequence each get an explicit test.
 */
class AutomationRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected AutomationMailerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@automation.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 100000,
        ]]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->factory = new AutomationMailerFactory;
        $this->app->instance(MailerFactory::class, $this->factory);

        SmtpAccount::factory()->forAccount($this->owner->account)->create([
            'name' => 'Relay', 'from_email' => 'relay@senders.test', 'from_name' => 'Relay',
        ]);
    }

    protected function runner(): AutomationRunner
    {
        return $this->app->make(AutomationRunner::class);
    }

    protected function enroller(): AutomationEnroller
    {
        return $this->app->make(AutomationEnroller::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    protected function automation(array $steps, array $overrides = []): Automation
    {
        $automation = Automation::create(array_merge([
            'account_id' => $this->owner->account_id,
            'user_id' => $this->owner->id,
            'name' => 'Welcome sequence',
            'trigger_type' => 'subscriber_added',
            'status' => 'active',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
        ], $overrides));

        foreach ($steps as $i => $step) {
            AutomationStep::create(array_merge([
                'automation_id' => $automation->id,
                'position' => $i + 1,
            ], $step));
        }

        return $automation->refresh();
    }

    /** @return array<string, mixed> */
    protected function sendStep(string $subject = 'Welcome', string $html = '<p>Hello there</p>'): array
    {
        return ['type' => 'send_email', 'label' => 'Welcome email',
            'config' => ['subject' => $subject, 'html' => $html]];
    }

    protected function contact(string $email = 'reader@example.com'): Subscriber
    {
        return Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => $email, 'first_name' => 'Reader', 'status' => 'active']);
    }

    // -------------------------------------------------------- the happy path

    public function test_a_run_walks_its_steps_and_completes(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'Welcomed']);

        $automation = $this->automation([
            $this->sendStep(),
            ['type' => 'add_tag', 'config' => ['tag_id' => $tag->id]],
        ]);

        $subscriber = $this->contact();
        $this->enroller()->enrol($automation, $subscriber);

        $result = $this->runner()->tick();

        $this->assertSame(1, $result['claimed']);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(2, $result['advanced']);
        $this->assertSame(1, $result['completed']);

        $this->assertSame(['reader@example.com'], $this->factory->transport->sentTo);
        $this->assertTrue($subscriber->tags()->whereKey($tag->id)->exists());

        $run = AutomationRun::withoutGlobalScopes()->first();
        $this->assertSame('completed', $run->status);
        $this->assertNull($run->next_run_at);
        $this->assertNotNull($run->completed_at);

        $this->assertSame(1, (int) $automation->fresh()->emails_sent);
        $this->assertSame(1, (int) $automation->fresh()->completed_count);
        $this->assertSame(1, (int) AutomationStep::where('type', 'send_email')->value('sent_count'));

        // The email log has always had an `automation` type and nothing wrote
        // one, so "did this person get the email?" had no answer for anything
        // an automation sent.
        $this->assertDatabaseHas('campaign_logs', [
            'account_id' => $this->owner->account_id,
            'recipient_email' => 'reader@example.com',
            'type' => 'automation',
            'status' => 'sent',
            'campaign_id' => null,
        ]);
    }

    public function test_a_wait_step_stops_the_run_until_its_time(): void
    {
        $automation = $this->automation([
            ['type' => 'wait', 'config' => ['amount' => 2, 'unit' => 'days']],
            $this->sendStep(),
        ]);

        $this->enroller()->enrol($automation, $this->contact());

        $this->runner()->tick();

        $run = AutomationRun::withoutGlobalScopes()->first();

        $this->assertSame('waiting', $run->status);
        $this->assertSame([], $this->factory->transport->sentTo, 'The wait must be respected.');
        $this->assertTrue($run->next_run_at->greaterThan(now()->addHours(47)));

        // A second tick before the timer expires must do nothing at all.
        $this->assertSame(0, $this->runner()->tick()['claimed']);
        $this->assertSame([], $this->factory->transport->sentTo);

        // And when it expires, the sequence continues from where it stopped.
        $this->travelTo(now()->addDays(3));
        $this->runner()->tick();

        $this->assertSame(['reader@example.com'], $this->factory->transport->sentTo);
        $this->assertSame('completed', AutomationRun::withoutGlobalScopes()->first()->status);
    }

    // ------------------------------------------------- the failures that hurt

    /**
     * The single most important test in this file. Two ticks running at once
     * is the normal state of a busy scheduler, not an exotic race.
     */
    public function test_two_concurrent_ticks_cannot_send_the_same_email_twice(): void
    {
        $automation = $this->automation([$this->sendStep()]);
        $this->enroller()->enrol($automation, $this->contact());

        // Two runner instances, the same due run, no coordination between them.
        $first = $this->app->make(AutomationRunner::class)->tick();
        $second = $this->app->make(AutomationRunner::class)->tick();

        $this->assertSame(1, $first['claimed']);
        $this->assertSame(0, $second['claimed'], 'The second tick must find nothing left to claim.');
        $this->assertCount(1, $this->factory->transport->sentTo);
    }

    public function test_a_contact_who_opts_out_mid_sequence_is_not_mailed_again(): void
    {
        $automation = $this->automation([
            $this->sendStep('First'),
            ['type' => 'wait', 'config' => ['amount' => 1, 'unit' => 'days']],
            $this->sendStep('Second'),
        ]);

        $subscriber = $this->contact();
        $this->enroller()->enrol($automation, $subscriber);

        $this->runner()->tick();
        $this->assertCount(1, $this->factory->transport->sentTo);

        // They unsubscribe on day two of a three-step sequence.
        app(\App\Services\Contacts\SuppressionService::class)
            ->suppress($subscriber->email, 'unsubscribed', null, null, 'test', $this->owner->account_id);

        $this->travelTo(now()->addDays(2));
        $this->runner()->tick();

        $this->assertCount(1, $this->factory->transport->sentTo,
            'The second email must never be sent to somebody who opted out.');

        $run = AutomationRun::withoutGlobalScopes()->first();
        $this->assertSame('cancelled', $run->status);
        $this->assertStringContainsString('opted out', $run->last_error);
    }

    public function test_a_run_that_chains_forever_is_stopped_rather_than_looping(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'Chained']);

        // Far more instant steps than one tick may spend.
        $steps = [];
        for ($i = 0; $i < AutomationRunner::MAX_STEPS_PER_TICK + 10; $i++) {
            $steps[] = ['type' => 'add_tag', 'config' => ['tag_id' => $tag->id]];
        }

        $automation = $this->automation($steps);
        $this->enroller()->enrol($automation, $this->contact());

        $result = $this->runner()->tick();

        $this->assertSame(AutomationRunner::MAX_STEPS_PER_TICK, $result['advanced'],
            'One tick must never spend more than its budget on a single run.');

        $run = AutomationRun::withoutGlobalScopes()->first();
        $this->assertSame('waiting', $run->status, 'A long sequence is rescheduled, not killed.');

        // And it genuinely finishes rather than being stuck at the ceiling.
        $this->travelTo(now()->addMinutes(2));
        $this->runner()->tick();

        $this->assertSame('completed', AutomationRun::withoutGlobalScopes()->first()->status);
    }

    public function test_running_out_of_the_monthly_allowance_waits_instead_of_failing(): void
    {
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 0,
        ]]);
        $this->owner->account->refresh();

        $automation = $this->automation([$this->sendStep()]);
        $this->enroller()->enrol($automation, $this->contact());

        $result = $this->runner()->tick();

        $this->assertSame(1, $result['deferred']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame([], $this->factory->transport->sentTo);

        $run = AutomationRun::withoutGlobalScopes()->first();
        $this->assertSame('waiting', $run->status,
            'A sequence must survive the month running out; it is not the subscriber\'s fault.');
        $this->assertNotNull($run->current_step_id, 'It must resume on the step it could not send.');
    }

    public function test_a_hard_bounce_stops_the_run_and_is_recorded(): void
    {
        // The debug transcript is what makes this a bounce rather than a
        // rejected message: a 550 answering RCPT TO: is a bad mailbox, the
        // same 550 answering DATA is the message being refused.
        $error = new UnexpectedResponseException(
            'Expected response code "250" but got code "550", with message "550 5.1.1 No such user here".'
        );
        $error->appendDebug("> RCPT TO:<gone@example.com>
< 550 5.1.1 No such user here
");

        $this->factory->transport->error = $error;

        $automation = $this->automation([$this->sendStep(), $this->sendStep('Second')]);
        $subscriber = $this->contact('gone@example.com');
        $this->enroller()->enrol($automation, $subscriber);

        $this->runner()->tick();

        $bounce = Bounce::withoutGlobalScopes()->first();
        $this->assertNotNull($bounce, 'An automation bounce belongs in the bounce log like any other.');
        $this->assertSame('hard', $bounce->type);
        $this->assertNull($bounce->campaign_id, 'It belongs to no campaign, and says so.');

        $run = AutomationRun::withoutGlobalScopes()->first();
        $this->assertSame('cancelled', $run->status);

        $this->assertTrue(
            Suppression::withoutGlobalScopes()->where('email', 'gone@example.com')->exists(),
            'A hard bounce must stop the address being mailed everywhere, not just here.'
        );
    }

    public function test_a_worker_that_died_mid_step_does_not_strand_the_subscriber(): void
    {
        $automation = $this->automation([$this->sendStep()]);
        $this->enroller()->enrol($automation, $this->contact());

        // A run left claimed by a process that never came back.
        DB::table('automation_runs')->update([
            'status' => 'running',
            'updated_at' => now()->subMinutes(AutomationRunner::LEASE_MINUTES + 5),
        ]);

        $this->assertSame(1, $this->runner()->tick()['claimed']);
        $this->assertCount(1, $this->factory->transport->sentTo);
    }

    // ------------------------------------------------------------ step types

    public function test_a_condition_that_is_not_met_ends_the_run(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'Customer']);

        $automation = $this->automation([
            ['type' => 'condition', 'config' => ['check' => 'has_tag', 'tag_id' => $tag->id]],
            $this->sendStep(),
        ]);

        $this->enroller()->enrol($automation, $this->contact());
        $this->runner()->tick();

        $this->assertSame([], $this->factory->transport->sentTo);
        $this->assertSame('completed', AutomationRun::withoutGlobalScopes()->first()->status);
    }

    public function test_a_condition_that_is_met_lets_the_run_continue(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'Customer']);
        $subscriber = $this->contact();
        $subscriber->tags()->attach($tag->id);

        $automation = $this->automation([
            ['type' => 'condition', 'config' => ['check' => 'has_tag', 'tag_id' => $tag->id]],
            $this->sendStep(),
        ]);

        $this->enroller()->enrol($automation, $subscriber);
        $this->runner()->tick();

        $this->assertCount(1, $this->factory->transport->sentTo);
    }

    public function test_an_unrecognised_condition_stops_rather_than_mailing(): void
    {
        $automation = $this->automation([
            ['type' => 'condition', 'config' => ['check' => 'reads_minds']],
            $this->sendStep(),
        ]);

        $this->enroller()->enrol($automation, $this->contact());
        $this->runner()->tick();

        $this->assertSame([], $this->factory->transport->sentTo,
            'A condition nobody can evaluate must never be treated as met.');
    }

    public function test_if_false_skip_steps_over_exactly_one_step(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'Bought']);

        $automation = $this->automation([
            ['type' => 'condition', 'config' => ['check' => 'has_tag', 'tag_id' => $tag->id, 'if_false' => 'skip']],
            $this->sendStep('Reminder'),
            $this->sendStep('Newsletter'),
        ]);

        $this->enroller()->enrol($automation, $this->contact());
        $this->runner()->tick();

        $this->assertCount(1, $this->factory->transport->sentTo);
        $this->assertStringContainsString('Newsletter', implode('', $this->factory->transport->raw));
        $this->assertStringNotContainsString('Reminder', implode('', $this->factory->transport->raw));
    }

    public function test_the_move_list_step_moves_the_contact(): void
    {
        $from = SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => 'Leads']);
        $to = SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => 'Customers']);

        $subscriber = $this->contact();
        $subscriber->lists()->attach($from->id, ['subscribed_at' => now()]);

        $automation = $this->automation([
            ['type' => 'move_list', 'config' => ['from_list_id' => $from->id, 'list_id' => $to->id]],
        ]);

        $this->enroller()->enrol($automation, $subscriber);
        $this->runner()->tick();

        $this->assertFalse($subscriber->lists()->whereKey($from->id)->exists());
        $this->assertTrue($subscriber->lists()->whereKey($to->id)->exists());
    }

    public function test_the_unsubscribe_step_opts_the_contact_out_and_ends_the_run(): void
    {
        $automation = $this->automation([
            ['type' => 'unsubscribe'],
            $this->sendStep('Never sent'),
        ]);

        $subscriber = $this->contact();
        $this->enroller()->enrol($automation, $subscriber);
        $this->runner()->tick();

        $this->assertTrue(
            Suppression::withoutGlobalScopes()->where('email', $subscriber->email)->exists()
        );
        $this->assertSame([], $this->factory->transport->sentTo);
        $this->assertSame('completed', AutomationRun::withoutGlobalScopes()->first()->status);
    }

    // ----------------------------------------------------------- the message

    public function test_the_email_carries_the_opt_out_headers_a_campaign_carries(): void
    {
        $automation = $this->automation([$this->sendStep('Welcome', '<p>Hello {{first_name}}</p>')]);
        $this->enroller()->enrol($automation, $this->contact());
        $this->runner()->tick();

        $raw = $this->factory->transport->raw[0];

        $this->assertStringContainsString('List-Unsubscribe:', $raw);
        $this->assertStringContainsString('List-Unsubscribe-Post:', $raw);
        $this->assertStringContainsString('Auto-Submitted: auto-generated', $raw);
        $this->assertStringContainsString('Precedence: bulk', $raw);
        $this->assertStringContainsString('Reader', $raw, 'Personalisation must be applied.');
    }

    public function test_a_step_with_nothing_to_send_fails_the_run_with_a_readable_reason(): void
    {
        $automation = $this->automation([
            ['type' => 'send_email', 'label' => 'Empty', 'config' => ['subject' => '', 'html' => '']],
        ]);

        $this->enroller()->enrol($automation, $this->contact());
        $result = $this->runner()->tick();

        $this->assertSame(1, $result['failed']);
        $this->assertSame([], $this->factory->transport->sentTo);

        $run = AutomationRun::withoutGlobalScopes()->first();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('no subject or content', $run->last_error);
    }

    // ------------------------------------------------------------- lifecycle

    public function test_a_paused_automation_resumes_where_it_stopped_rather_than_restarting(): void
    {
        $automation = $this->automation([
            $this->sendStep('First'),
            ['type' => 'wait', 'config' => ['amount' => 1, 'unit' => 'days']],
            $this->sendStep('Second'),
        ]);

        $this->enroller()->enrol($automation, $this->contact());

        // Step one goes out, then the run parks on the wait.
        $this->runner()->tick();
        $this->assertCount(1, $this->factory->transport->sentTo);

        $onWait = AutomationRun::withoutGlobalScopes()->first()->current_step_id;

        $automation->forceFill(['status' => 'paused'])->save();
        $this->travelTo(now()->addDays(2));
        $this->runner()->tick();

        $this->assertCount(1, $this->factory->transport->sentTo,
            'A paused automation must not send, even once its timer has expired.');

        $run = AutomationRun::withoutGlobalScopes()->first();
        $this->assertSame('waiting', $run->status);
        $this->assertSame($onWait, $run->current_step_id,
            'Pausing must leave the run on the step it had reached.');

        $automation->forceFill(['status' => 'active'])->save();
        $this->travelTo(now()->addMinutes(20));
        $this->runner()->tick();

        $this->assertCount(2, $this->factory->transport->sentTo);
        $this->assertStringContainsString('Second', $this->factory->transport->raw[1],
            'Resuming must continue the sequence, not start it again.');
    }

    public function test_a_deleted_contact_cancels_their_run_rather_than_failing_it(): void
    {
        $automation = $this->automation([$this->sendStep()]);
        $subscriber = $this->contact();
        $this->enroller()->enrol($automation, $subscriber);

        $subscriber->forceDelete();

        $result = $this->runner()->tick();

        $this->assertSame(0, $result['failed']);
        $this->assertSame([], $this->factory->transport->sentTo);
    }

    public function test_another_accounts_run_is_never_advanced_with_our_smtp(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@automation.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = app(TenantManager::class)->runAs($other->account_id, function () use ($other) {
            $automation = Automation::create([
                'account_id' => $other->account_id, 'name' => 'Theirs',
                'trigger_type' => 'subscriber_added', 'status' => 'active',
                'from_name' => 'Rival', 'from_email' => 'hi@rival.test',
            ]);
            AutomationStep::create([
                'automation_id' => $automation->id, 'position' => 1, 'type' => 'send_email',
                'config' => ['subject' => 'Theirs', 'html' => '<p>Theirs</p>'],
            ]);

            $subscriber = Subscriber::factory()->forAccount($other->account)
                ->create(['email' => 'their-reader@example.com', 'status' => 'active']);

            return [$automation->refresh(), $subscriber];
        });

        app(TenantManager::class)->set($this->owner->account_id);
        $this->enroller()->enrol($foreign[0], $foreign[1]);

        // Their account has no SMTP of its own, so their run cannot borrow ours.
        $result = $this->runner()->tick();

        $this->assertSame([], $this->factory->transport->sentTo);
        $this->assertSame(1, $result['failed']);
    }

    // ------------------------------------------- deleted things stay deleted

    /**
     * The bug class this codebase has already been bitten by once.
     *
     * `withoutGlobalScopes()` lifts EVERY global scope, SoftDeletingScope
     * included. Used where only tenancy was meant to be lifted, it hands back
     * rows the operator has deleted — and an automation that keeps mailing
     * people after it has been deleted is the worst version of that, because
     * deleting it is precisely the thing the operator did to make it stop.
     */
    public function test_a_deleted_automation_stops_an_in_flight_run(): void
    {
        $automation = $this->automation([$this->sendStep()]);
        $subscriber = $this->contact();

        $run = $this->enroller()->enrol($automation, $subscriber);
        $this->assertNotNull($run);

        $automation->delete();
        $this->assertSoftDeleted('automations', ['id' => $automation->id]);

        $result = $this->runner()->tick();

        $this->assertSame([], $this->factory->transport->sentTo,
            'A deleted automation must not send anything.');
        $this->assertSame(0, $result['sent']);
        $this->assertSame('cancelled', $run->fresh()->status,
            'The run must end, not park itself forever for an automation nobody can see.');
        $this->assertNull($run->fresh()->next_run_at);
    }

    public function test_a_deleted_automation_enrols_nobody_new(): void
    {
        $automation = $this->automation([$this->sendStep()]);
        $automation->delete();

        $subscriber = $this->contact('late@example.com');

        app(\App\Services\Automation\AutomationTrigger::class)
            ->subscriberAdded($this->owner->account_id, [$subscriber->id]);

        $this->assertSame(0, AutomationRun::withoutGlobalScope(\App\Models\Scopes\AccountScope::class)
            ->where('automation_id', $automation->id)->count(),
            'A deleted automation must not be listening for triggers.');
    }

    public function test_a_deleted_contact_is_not_mailed(): void
    {
        $automation = $this->automation([$this->sendStep()]);
        $subscriber = $this->contact('gone@example.com');

        $run = $this->enroller()->enrol($automation, $subscriber);

        $subscriber->delete();
        $this->assertSoftDeleted('subscribers', ['id' => $subscriber->id]);

        $this->runner()->tick();

        $this->assertSame([], $this->factory->transport->sentTo,
            'A contact the operator deleted must not receive automation mail.');
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_a_send_step_whose_template_was_deleted_does_not_send_an_empty_email(): void
    {
        $template = \App\Models\EmailTemplate::create([
            'account_id' => $this->owner->account_id,
            'user_id' => $this->owner->id,
            'name' => 'Welcome template',
            'subject' => 'From a template',
            'html' => '<p>Body from the template</p>',
        ]);

        $automation = $this->automation([
            ['type' => 'send_email', 'label' => 'Templated', 'email_template_id' => $template->id, 'config' => []],
        ]);

        $subscriber = $this->contact('templated@example.com');
        $run = $this->enroller()->enrol($automation, $subscriber);

        $template->delete();

        $result = $this->runner()->tick();

        $this->assertSame([], $this->factory->transport->sentTo,
            'A deleted template must not be resurrected by the runner.');
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('no subject or content', (string) $run->fresh()->last_error);
    }

    public function test_move_list_ignores_a_list_that_was_deleted(): void
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => 'Customers']);

        $automation = $this->automation([
            ['type' => 'move_list', 'label' => 'Onto customers', 'config' => ['list_id' => $list->id]],
        ]);

        $subscriber = $this->contact('mover@example.com');
        $this->enroller()->enrol($automation, $subscriber);

        $list->delete();

        $this->runner()->tick();

        $this->assertSame(0, $subscriber->lists()->count(),
            'A contact must not be filed onto a list the operator deleted.');
    }

    // ------------------------------------------- one automation starting another

    /**
     * A "tag them" step is a real event, so a `tag_added` automation must see
     * it. Without this the builder offers a chain the runner never walks.
     */
    public function test_a_tag_step_starts_an_automation_that_watches_that_tag(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'Onboarded']);

        $second = $this->automation([$this->sendStep('Second sequence')], [
            'name' => 'Watches the tag', 'trigger_type' => 'tag_added', 'trigger_config' => ['tag_id' => $tag->id],
        ]);

        $first = $this->automation([
            ['type' => 'add_tag', 'label' => 'Tag them', 'config' => ['tag_id' => $tag->id]],
        ], ['name' => 'Tags them']);

        $subscriber = $this->contact('chained@example.com');
        $this->enroller()->enrol($first, $subscriber);

        $this->runner()->tick();

        $this->assertSame(1, AutomationRun::withoutGlobalScope(\App\Models\Scopes\AccountScope::class)
            ->where('automation_id', $second->id)->count(),
            'Tagging somebody from a step must start the automation waiting for that tag.');
    }

    /**
     * Two automations that tag each other, both allowing re-entry.
     *
     * The chain above is only safe because an automation-caused event may
     * never RE-ARM a finished run. Without that ban this pair would re-arm one
     * another one hop per tick for ever, and each hop is an email.
     */
    public function test_two_automations_that_tag_each_other_come_to_a_stop(): void
    {
        $tagA = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'Ping']);
        $tagB = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'Pong']);

        $ping = $this->automation([
            ['type' => 'add_tag', 'label' => 'Pong them', 'config' => ['tag_id' => $tagB->id]],
        ], ['name' => 'Ping', 'trigger_type' => 'tag_added',
            'trigger_config' => ['tag_id' => $tagA->id], 'allow_reentry' => true]);

        $pong = $this->automation([
            ['type' => 'add_tag', 'label' => 'Ping them', 'config' => ['tag_id' => $tagA->id]],
        ], ['name' => 'Pong', 'trigger_type' => 'tag_added',
            'trigger_config' => ['tag_id' => $tagB->id], 'allow_reentry' => true]);

        $subscriber = $this->contact('pingpong@example.com');
        $this->enroller()->enrol($ping, $subscriber);

        // Ten ticks is far more than the two hops this can honestly take.
        for ($i = 0; $i < 10; $i++) {
            $this->runner()->tick();
        }

        $runs = AutomationRun::withoutGlobalScope(\App\Models\Scopes\AccountScope::class)->get();

        $this->assertCount(2, $runs, 'Each automation may hold exactly one run for this contact.');
        $this->assertSame(['completed', 'completed'], $runs->pluck('status')->sort()->values()->all(),
            'The cycle must come to rest, not keep re-arming itself every tick.');
        $this->assertSame(0, AutomationRun::withoutGlobalScope(\App\Models\Scopes\AccountScope::class)
            ->whereIn('status', ['waiting', 'running'])->count());
    }
}
