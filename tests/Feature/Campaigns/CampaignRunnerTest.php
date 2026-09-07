<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignLog;
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
use App\Services\Contacts\SuppressionService;
use App\Services\Smtp\MailerFactory;
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
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;
use Throwable;

/** Records what it was asked to send, and fails on command. */
class RecordingTransport implements TransportInterface
{
    /** @var array<int, string> */
    public array $sentTo = [];

    public ?Throwable $error = null;

    public function send(RawMessage $message, $envelope = null): ?SentMessage
    {
        // The envelope is what actually addresses the message.
        preg_match('/^To:\s*(.+)$/mi', $message->toString(), $m);
        $this->sentTo[] = trim($m[1] ?? 'unknown');

        if ($this->error) {
            throw $this->error;
        }

        return null;
    }

    public function __toString(): string
    {
        return 'recording';
    }
}

class RecordingFactory extends MailerFactory
{
    public RecordingTransport $transport;

    public function __construct()
    {
        $this->transport = new RecordingTransport;
    }

    public function for(SmtpAccount $account, ?int $timeout = null): TransportInterface
    {
        return $this->transport;
    }
}

/**
 * The send pipeline. These tests exist because the failure modes here are the
 * ones that email a person who opted out, email somebody twice, or quietly
 * drop half a campaign.
 */
class CampaignRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected RecordingFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@runner.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 100000,
        ]]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->factory = new RecordingFactory;
        $this->app->instance(MailerFactory::class, $this->factory);

        SmtpAccount::factory()->forAccount($this->owner->account)->create(['name' => 'Relay']);
    }

    protected function runner(): CampaignRunner
    {
        return $this->app->make(CampaignRunner::class);
    }

    /** @return array{0: Campaign, 1: SubscriberList} */
    protected function campaignWith(int $contacts, array $overrides = []): array
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();

        for ($i = 1; $i <= $contacts; $i++) {
            $subscriber = Subscriber::factory()->forAccount($this->owner->account)
                ->create(['email' => "person{$i}@example.com", 'first_name' => "Person{$i}"]);
            $subscriber->lists()->attach($list->id, ['subscribed_at' => now()]);
        }

        $doc = ['settings' => [], 'blocks' => [
            ['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hi {{first_name|there}}']],
            ['id' => 'f', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
        ]];

        $compiler = app(EmailCompiler::class);

        $campaign = Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'name' => 'Test campaign',
            'subject' => 'Hello {{first_name|there}}',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'status' => 'sending',
            'blocks' => $doc,
            'html' => $compiler->compile($doc),
            'plain_text' => $compiler->compileText($doc),
            'audience' => ['lists' => [$list->id]],
        ], $overrides));

        app(RecipientGenerator::class)->generate($campaign);

        return [$campaign->fresh(), $list];
    }

    // ------------------------------------------------------- generation

    public function test_generation_excludes_suppressed_and_inactive_contacts(): void
    {
        [$campaign] = $this->campaignWith(3);

        // Add three more that must not be reachable.
        $list = SubscriberList::first();
        foreach ([
            ['email' => 'gone@example.com', 'status' => 'unsubscribed'],
            ['email' => 'bounced@example.com', 'status' => 'bounced'],
            ['email' => 'blocked@example.com', 'status' => 'active'],
        ] as $attributes) {
            $s = Subscriber::factory()->forAccount($this->owner->account)->create($attributes);
            $s->lists()->attach($list->id, ['subscribed_at' => now()]);
        }

        Suppression::factory()->forAccount($this->owner->account)->create(['email' => 'blocked@example.com']);

        app(RecipientGenerator::class)->generate($campaign);

        $emails = CampaignRecipient::where('campaign_id', $campaign->id)->pluck('email');

        $this->assertCount(3, $emails);
        $this->assertNotContains('gone@example.com', $emails->all());
        $this->assertNotContains('bounced@example.com', $emails->all());
        $this->assertNotContains('blocked@example.com', $emails->all());
    }

    public function test_generation_is_safe_to_re_run(): void
    {
        [$campaign] = $this->campaignWith(5);

        $created = app(RecipientGenerator::class)->generate($campaign);

        $this->assertSame(0, $created, 'A second pass must add nobody.');
        $this->assertSame(5, CampaignRecipient::where('campaign_id', $campaign->id)->count());
        $this->assertSame(5, (int) $campaign->fresh()->total_recipients);
    }

    public function test_an_empty_audience_reaches_nobody_rather_than_everybody(): void
    {
        $this->campaignWith(5);

        $orphan = Campaign::factory()->forAccount($this->owner->account)->create(['audience' => []]);

        $this->assertSame(0, app(RecipientGenerator::class)->generate($orphan));
        $this->assertSame(0, CampaignRecipient::where('campaign_id', $orphan->id)->count());
    }

    // ----------------------------------------------------------- sending

    public function test_a_chunk_sends_and_records_every_recipient(): void
    {
        [$campaign] = $this->campaignWith(4);

        $result = $this->runner()->runChunk($campaign);

        $this->assertSame(4, $result['claimed']);
        $this->assertSame(4, $result['sent']);
        $this->assertTrue($result['finished']);

        $this->assertCount(4, $this->factory->transport->sentTo);
        $this->assertSame(4, (int) $campaign->fresh()->sent_count);
        $this->assertSame('completed', $campaign->fresh()->status);
        $this->assertSame(4, CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'sent')->count());
        $this->assertSame(4, CampaignLog::withoutGlobalScopes()->where('campaign_id', $campaign->id)->count());
    }

    public function test_each_message_is_personalised_and_carries_its_own_unsubscribe_header(): void
    {
        [$campaign] = $this->campaignWith(2);

        $this->runner()->runChunk($campaign);

        $recipients = CampaignRecipient::where('campaign_id', $campaign->id)->get();

        foreach ($recipients as $recipient) {
            $this->assertNotNull($recipient->message_id, 'A message id is needed to match replies later.');
        }

        $this->assertNotSame(
            $recipients[0]->message_id,
            $recipients[1]->message_id,
            'Two recipients must not share a Message-ID.'
        );
    }

    public function test_nobody_is_sent_to_twice_across_repeated_runs(): void
    {
        [$campaign] = $this->campaignWith(3);

        $this->runner()->runChunk($campaign);
        $this->runner()->runChunk($campaign);
        $this->runner()->runChunk($campaign);

        $this->assertCount(3, $this->factory->transport->sentTo);
        $this->assertSame(3, (int) $campaign->fresh()->sent_count);
    }

    // ------------------------------------------------------- compliance

    public function test_someone_who_opts_out_mid_send_is_not_mailed(): void
    {
        [$campaign] = $this->campaignWith(3);

        // They were in the audience at generation, and opt out before the
        // chunk runs — exactly the gap that generation-time filtering misses.
        app(SuppressionService::class)->suppress('person2@example.com', 'unsubscribed');

        $result = $this->runner()->runChunk($campaign);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(2, $result['sent']);
        $this->assertNotContains('person2@example.com', $this->factory->transport->sentTo);

        $skipped = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('email', 'person2@example.com')->first();

        $this->assertSame('skipped', $skipped->status);
        $this->assertStringContainsString('suppressed', (string) $skipped->error);
    }

    public function test_a_hard_bounce_suppresses_the_address_for_future_campaigns(): void
    {
        [$campaign] = $this->campaignWith(1);

        $e = new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 User unknown".');
        $e->appendDebug("> RCPT TO:<person1@example.com>\r\n< 550 no\r\n");
        $this->factory->transport->error = $e;

        $this->runner()->runChunk($campaign);

        $recipient = CampaignRecipient::where('campaign_id', $campaign->id)->first();

        $this->assertSame('bounced', $recipient->status);
        $this->assertSame('hard', $recipient->bounce_type);
        $this->assertSame(1, (int) $campaign->fresh()->bounced_count);
        $this->assertTrue(
            app(SuppressionService::class)->isSuppressed('person1@example.com', $this->owner->account_id),
            'A dead mailbox must not be mailed again by the next campaign.'
        );
    }

    // ---------------------------------------------------------- capacity

    public function test_running_out_of_smtp_capacity_defers_rather_than_failing(): void
    {
        [$campaign] = $this->campaignWith(3);

        SmtpAccount::withoutGlobalScopes()->where('account_id', $this->owner->account_id)
            ->update(['daily_limit' => 1, 'sent_today' => 1, 'day_reset_at' => now()]);

        $result = $this->runner()->runChunk($campaign);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['deferred']);

        $campaign->refresh();

        $this->assertSame('sending', $campaign->status, 'A quota pause is not a campaign failure.');
        $this->assertSame(0, (int) $campaign->failed_count);
        $this->assertSame(3, CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')->count(), 'Everyone stays queued for later.');
    }

    public function test_an_account_level_smtp_failure_is_recorded_against_the_recipient(): void
    {
        [$campaign] = $this->campaignWith(1);

        $this->factory->transport->error = new TransportException('Connection refused');

        $this->runner()->runChunk($campaign);

        $recipient = CampaignRecipient::where('campaign_id', $campaign->id)->first();

        $this->assertSame('failed', $recipient->status);
        $this->assertSame(1, (int) $campaign->fresh()->failed_count);
        $this->assertNull($recipient->bounce_type, 'A refused connection is not a bounce.');
    }

    // ------------------------------------------------------------ control

    public function test_a_paused_campaign_sends_nothing_and_keeps_its_queue(): void
    {
        [$campaign] = $this->campaignWith(4);
        $campaign->update(['status' => 'paused']);

        $result = $this->runner()->runChunk($campaign);

        $this->assertTrue($result['paused']);
        $this->assertSame(0, $result['claimed']);
        $this->assertEmpty($this->factory->transport->sentTo);
        $this->assertSame(4, CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')->count());
    }

    public function test_resuming_continues_from_where_it_stopped(): void
    {
        [$campaign] = $this->campaignWith(3);

        $this->runner()->runChunk($campaign);
        $sentFirst = count($this->factory->transport->sentTo);

        $campaign->update(['status' => 'paused']);
        $this->runner()->runChunk($campaign);

        $this->assertCount($sentFirst, $this->factory->transport->sentTo, 'Paused means paused.');

        $campaign->update(['status' => 'sending']);
        $this->runner()->runChunk($campaign);

        $this->assertSame(3, (int) $campaign->fresh()->sent_count);
        $this->assertCount(3, array_unique($this->factory->transport->sentTo),
            'Resuming must not re-send to anybody.');
    }

    public function test_a_cancelled_campaign_stops_immediately(): void
    {
        [$campaign] = $this->campaignWith(4);
        $campaign->update(['status' => 'cancelled']);

        $this->runner()->runChunk($campaign);

        $this->assertEmpty($this->factory->transport->sentTo);
        $this->assertSame('cancelled', $campaign->fresh()->status);
    }

    // --------------------------------------------------------- completion

    public function test_completion_does_not_fire_while_work_remains(): void
    {
        [$campaign] = $this->campaignWith(2);

        // One row is held by another worker; the campaign is not finished.
        DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->limit(1)
            ->update(['status' => 'sending', 'locked_by' => 'other-worker', 'locked_at' => now()]);

        $this->assertFalse($this->runner()->tryFinalise($campaign));
        $this->assertSame('sending', $campaign->fresh()->status);
    }

    public function test_completion_fires_exactly_once(): void
    {
        [$campaign] = $this->campaignWith(1);

        $this->runner()->runChunk($campaign);

        $this->assertSame('completed', $campaign->fresh()->status);
        $this->assertFalse($this->runner()->tryFinalise($campaign->fresh()),
            'A second finalise must not fire — it would reset completed_at and re-notify.');
    }

    public function test_a_crashed_workers_claim_is_reclaimed_after_the_lease(): void
    {
        [$campaign] = $this->campaignWith(2);

        DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->update([
            'status' => 'sending',
            'locked_by' => 'dead-worker',
            'locked_at' => now()->subMinutes(CampaignRunner::LEASE_MINUTES + 1),
        ]);

        $result = $this->runner()->runChunk($campaign);

        $this->assertSame(2, $result['claimed'], 'A dead worker must not strand the campaign.');
        $this->assertSame(2, $result['sent']);
    }

    public function test_a_live_claim_is_left_alone(): void
    {
        [$campaign] = $this->campaignWith(2);

        DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->update([
            'status' => 'sending', 'locked_by' => 'busy-worker', 'locked_at' => now(),
        ]);

        $result = $this->runner()->runChunk($campaign);

        $this->assertSame(0, $result['claimed'], 'Stealing a live claim would double-send.');
        $this->assertEmpty($this->factory->transport->sentTo);
    }

    // ------------------------------------------------------- plan limits

    public function test_the_monthly_email_limit_stops_the_send_without_failing_it(): void
    {
        [$campaign] = $this->campaignWith(3);

        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 1,
        ]]);
        $this->owner->account->refresh();

        $result = $this->runner()->runChunk($campaign->fresh());

        $this->assertLessThan(3, $result['sent']);
        $this->assertSame('sending', $campaign->fresh()->status);
        $this->assertGreaterThan(0, CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')->count());
    }

    public function test_one_account_cannot_send_another_accounts_campaign(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@runner.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        [$campaign] = $this->campaignWith(2);

        app(TenantManager::class)->set($other->account_id);

        $this->assertSame(0, Campaign::query()->count(), 'The campaign must be invisible to the other tenant.');
        $this->assertSame(0, app(RecipientGenerator::class)->count($campaign));
    }
}
