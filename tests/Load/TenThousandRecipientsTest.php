<?php

namespace Tests\Load;

use App\Models\Campaign;
use App\Models\SmtpAccount;
use App\Models\SubscriberList;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\EmailCompiler;
use App\Services\Campaigns\RecipientGenerator;
use App\Services\Smtp\MailerFactory;
use App\Support\PlanLimits;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Counts what it was asked to deliver, and to whom, without keeping the bodies.
 *
 * Keeping ten thousand rendered messages in memory would make this test measure
 * the test rather than the application.
 */
class CountingTransport implements TransportInterface
{
    public int $count = 0;

    /** @var array<string, int> address => times sent */
    public array $perAddress = [];

    public function send(RawMessage $message, $envelope = null): ?SentMessage
    {
        $this->count++;

        if (preg_match('/^To:\s*(.+)$/mi', $message->toString(), $m)) {
            $to = trim($m[1]);
            $this->perAddress[$to] = ($this->perAddress[$to] ?? 0) + 1;
        }

        return null;
    }

    public function __toString(): string
    {
        return 'counting';
    }
}

class CountingMailerFactory extends MailerFactory
{
    public CountingTransport $transport;

    public function __construct()
    {
        $this->transport = new CountingTransport;
    }

    public function for(SmtpAccount $account, ?int $timeout = null): TransportInterface
    {
        return $this->transport;
    }
}

/**
 * Ten thousand recipients through the real queue.
 *
 * ── Why this is not in the ordinary suite ───────────────────────────────────
 * It lives in tests/Load, which phpunit.xml does not list as a testsuite, so a
 * normal run never touches it. Run it deliberately:
 *
 *     php vendor/bin/phpunit tests/Load/TenThousandRecipientsTest.php
 *
 * ── What it is actually for ─────────────────────────────────────────────────
 * Every correctness property in the send path is about *scale*: claim-before-
 * send, the chunk loop, the pacing, the counters. Each has a unit test at three
 * or four recipients, and every one of those would still pass if the design
 * fell apart at ten thousand — a lock that is held a moment too long, a chunk
 * that re-claims rows it already sent, an accumulator that drifts by one per
 * pass. None of that shows up in a small test.
 *
 * The queue connection is the real database driver here, not `sync`, so the
 * job genuinely re-queues itself and genuinely competes for its own lock.
 *
 * The single assertion that matters is exactly-once: ten thousand addresses,
 * ten thousand messages, and no address seen twice.
 */
class TenThousandRecipientsTest extends TestCase
{
    use RefreshDatabase;

    protected const RECIPIENTS = 10000;

    protected User $owner;

    protected CountingMailerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Volume Ltd', 'name' => 'Owner', 'email' => 'owner@load.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5,
            'max_contacts' => 1000000, 'max_lists' => 100,
            'max_emails_per_month' => 1000000,
        ])]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->factory = new CountingMailerFactory;
        $this->app->instance(MailerFactory::class, $this->factory);

        SmtpAccount::factory()->forAccount($this->owner->account)->create([
            'name' => 'Relay', 'from_email' => 'relay@load.test', 'from_name' => 'Relay',
            // No per-hour or per-day ceiling: this is measuring the send path,
            // not the rate limiter, which has its own tests.
            'hourly_limit' => null, 'daily_limit' => null, 'monthly_limit' => null,
            'send_delay_ms' => 0,
        ]);

        // The real queue. On `sync` the job would run inline and never exercise
        // the re-queue loop, the lock, or the pacing — which is most of what
        // this test exists to check.
        config([
            'queue.default' => 'database',
            'knsoftic.send_rate_per_minute' => 1000000,
        ]);
    }

    public function test_ten_thousand_recipients_each_receive_exactly_one_message(): void
    {
        $started = microtime(true);

        $campaign = $this->campaignForEveryone();

        $generated = microtime(true);

        $this->assertSame(self::RECIPIENTS,
            DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->count(),
            'The recipient list must be generated in full before anything is sent.');

        app(CampaignDispatcher::class)->sendNow($campaign);

        $passes = $this->drainTheQueue();

        $finished = microtime(true);
        $campaign->refresh();

        // ---------------------------------------------------------- exactly once

        $this->assertSame(self::RECIPIENTS, $this->factory->transport->count,
            'Every recipient must be handed to the transport exactly once.');

        $this->assertCount(self::RECIPIENTS, $this->factory->transport->perAddress,
            'Ten thousand distinct addresses must have been written to.');

        $repeated = array_filter($this->factory->transport->perAddress, fn (int $n) => $n > 1);

        $this->assertSame([], $repeated,
            'These addresses were sent to more than once: '
            .implode(', ', array_slice(array_keys($repeated), 0, 10)));

        // -------------------------------------------------------- and recorded

        $byStatus = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')->pluck('n', 'status')->all();

        $this->assertSame(['sent' => self::RECIPIENTS], $byStatus,
            'Every row must end as sent — nothing left claimed, pending or locked.');

        $this->assertSame(0, DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)->whereNotNull('locked_by')->count(),
            'No row may still be holding a claim once the campaign has finished.');

        $this->assertSame(self::RECIPIENTS, (int) $campaign->sent_count,
            'The denormalised counter must agree with the rows.');

        $this->assertSame('completed', $campaign->status);
        $this->assertNotNull($campaign->completed_at);

        $this->assertSame(self::RECIPIENTS,
            PlanLimits::for($this->owner->account)->usageFor('max_emails_per_month'),
            "The month's allowance must have been charged once per message.");

        // The queue must have emptied itself rather than been abandoned.
        $this->assertSame(0, DB::table('jobs')->count(), 'Jobs were left on the queue.');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Some chunks failed.');

        fwrite(STDERR, sprintf(
            "\n  %s recipients: generated in %.1fs, sent in %.1fs across %d worker passes"
            ."\n  %.0f messages/sec, peak memory %.0f MB\n",
            number_format(self::RECIPIENTS),
            $generated - $started,
            $finished - $generated,
            $passes,
            self::RECIPIENTS / max(0.001, $finished - $generated),
            memory_get_peak_usage(true) / 1048576,
        ));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Builds the list and the campaign.
     *
     * The contacts are bulk-inserted rather than made one at a time by a
     * factory: ten thousand model saves would take longer than the thing being
     * measured and would tell us nothing about it.
     */
    protected function campaignForEveryone(?int $count = null): Campaign
    {
        $count ??= self::RECIPIENTS;

        $list = SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => 'Everyone']);
        $accountId = $this->owner->account_id;
        $now = now();

        for ($offset = 0; $offset < $count; $offset += 1000) {
            $rows = [];

            for ($i = $offset + 1; $i <= min($offset + 1000, $count); $i++) {
                $rows[] = [
                    'account_id' => $accountId,
                    'email' => "reader{$i}@example.com",
                    'first_name' => "Reader{$i}",
                    'status' => 'active',
                    'consent_status' => 'explicit',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('subscribers')->insert($rows);
        }

        $ids = DB::table('subscribers')->where('account_id', $accountId)->pluck('id');

        foreach ($ids->chunk(1000) as $chunk) {
            DB::table('list_subscriber')->insert(
                $chunk->map(fn ($id) => [
                    'subscriber_list_id' => $list->id,
                    'subscriber_id' => $id,
                    'subscribed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        }

        $doc = ['settings' => [], 'blocks' => [
            ['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hello {{first_name}}']],
            ['id' => 'b', 'type' => 'button', 'settings' => ['text' => 'Read', 'href' => 'https://example.com/read']],
            ['id' => 'f', 'type' => 'footer', 'settings' => ['companyLine' => 'Volume Ltd']],
        ]];

        $compiler = app(EmailCompiler::class);

        $campaign = Campaign::factory()->forAccount($this->owner->account)->create([
            'name' => 'The big one', 'subject' => 'Hello {{first_name}}',
            'from_name' => 'Volume Ltd', 'from_email' => 'hello@load.test',
            'status' => 'draft', 'blocks' => $doc,
            'html' => $compiler->compile($doc), 'plain_text' => $compiler->compileText($doc),
            'audience' => ['lists' => [$list->id]],
            'track_opens' => true, 'track_clicks' => true,
        ]);

        app(RecipientGenerator::class)->generate($campaign);

        return $campaign->refresh();
    }

    /**
     * Works the queue until it stays empty.
     *
     * Each chunk re-queues the next one with a short delay, and
     * `--stop-when-empty` returns as soon as nothing is *available* — a delayed
     * job is not. So the clock is moved forward between passes, which is also
     * the honest way to test the pacing: it proves the next chunk becomes
     * available rather than that the test waited long enough.
     *
     * @return int  worker passes taken
     */
    protected function drainTheQueue(int $maxPasses = 400): int
    {
        for ($pass = 1; $pass <= $maxPasses; $pass++) {
            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--sleep' => 0,
                '--tries' => 1,
            ]);

            $pending = DB::table('jobs')->count();

            if ($pending === 0) {
                return $pass;
            }

            // Past the delay the last chunk asked for.
            $this->travel(2)->seconds();
        }

        $this->fail("The queue was still not empty after {$maxPasses} worker passes.");
    }

    /**
     * The scheduler, end to end.
     *
     * A campaign sends because a cron tick found it, claimed it and queued it.
     * That path has no user in it at all, so nothing about it is exercised by
     * pressing Send — and it is the path most sends actually take.
     *
     * A smaller list here on purpose: what is under test is the hand-off from
     * the scheduler to the queue, not the volume, which the test above covers.
     */
    public function test_the_scheduler_claims_a_due_campaign_and_sends_it(): void
    {
        $campaign = $this->campaignForEveryone(1200);

        $campaign->forceFill([
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'timezone' => 'UTC',
        ])->save();

        $this->artisan('campaigns:dispatch-scheduled')->assertExitCode(0);

        $this->assertSame('queued', $campaign->fresh()->status,
            'The tick must claim the campaign by moving it out of "scheduled".');

        $this->drainTheQueue();

        $campaign->refresh();

        $this->assertSame(1200, $this->factory->transport->count);
        $this->assertCount(1200, $this->factory->transport->perAddress,
            'Every scheduled recipient, once each.');
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(1200, (int) $campaign->sent_count);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /**
     * The guard the command's own comment claims: a campaign deleted while it
     * was still scheduled must not be picked up and sent to its whole audience
     * afterwards.
     */
    public function test_the_scheduler_will_not_send_a_campaign_that_was_deleted(): void
    {
        $campaign = $this->campaignForEveryone(200);

        $campaign->forceFill([
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'timezone' => 'UTC',
        ])->save();

        $campaign->delete();

        $this->artisan('campaigns:dispatch-scheduled')->assertExitCode(0);
        $this->drainTheQueue();

        $this->assertSame(0, $this->factory->transport->count,
            'A deleted campaign must not be sent by the scheduler.');
        $this->assertSame('scheduled', Campaign::withTrashed()->find($campaign->id)->status,
            'and it must not even be claimed.');
    }
}
