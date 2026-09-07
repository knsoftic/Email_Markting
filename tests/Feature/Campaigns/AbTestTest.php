<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignVariant;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\AbTestService;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignRunner;
use App\Services\Campaigns\EmailCompiler;
use App\Services\Campaigns\RecipientGenerator;
use App\Services\Smtp\MailerFactory;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/** Keeps the subject line of everything it was asked to send. */
class AbTransport implements TransportInterface
{
    /** @var array<int, array{to: string, subject: string}> */
    public array $sent = [];

    public function send(RawMessage $message, $envelope = null): ?SentMessage
    {
        $body = $message->toString();

        preg_match('/^To:\s*(.+)$/mi', $body, $to);
        preg_match('/^Subject:\s*(.+)$/mi', $body, $subject);

        $this->sent[] = ['to' => trim($to[1] ?? ''), 'subject' => trim($subject[1] ?? '')];

        return null;
    }

    public function __toString(): string
    {
        return 'ab-recording';
    }
}

class AbMailerFactory extends MailerFactory
{
    public AbTransport $transport;

    public function __construct()
    {
        $this->transport = new AbTransport;
    }

    public function for(SmtpAccount $account, ?int $timeout = null): TransportInterface
    {
        return $this->transport;
    }
}

/**
 * Split sends.
 *
 * The failure this file is really about: an A/B test that quietly sends
 * everybody the same email and then reports a winner. That looks like a
 * working feature from every screen in the application, so it needs to be
 * caught by reading what actually left the transport.
 */
class AbTestTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected AbMailerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@abtest.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5,
            'max_emails_per_month' => 100000, 'max_contacts' => 100000,
        ]]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->factory = new AbMailerFactory;
        $this->app->instance(MailerFactory::class, $this->factory);

        SmtpAccount::factory()->forAccount($this->owner->account)->create(['name' => 'Relay']);
    }

    protected function ab(): AbTestService
    {
        return $this->app->make(AbTestService::class);
    }

    /**
     * @return array{0: Campaign, 1: CampaignVariant, 2: CampaignVariant}
     */
    protected function splitTest(int $contacts = 40, array $overrides = []): array
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();

        for ($i = 1; $i <= $contacts; $i++) {
            Subscriber::factory()->forAccount($this->owner->account)
                ->create(['email' => "reader{$i}@example.com", 'status' => 'active'])
                ->lists()->attach($list->id, ['subscribed_at' => now()]);
        }

        $doc = ['settings' => [], 'blocks' => [
            ['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hi']],
            ['id' => 'f', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
        ]];

        $compiler = app(EmailCompiler::class);

        $campaign = Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'name' => 'Split test', 'subject' => 'Control subject',
            'from_name' => 'Senders Ltd', 'from_email' => 'hello@senders.test',
            'status' => 'sending', 'blocks' => $doc,
            'html' => $compiler->compile($doc), 'plain_text' => $compiler->compileText($doc),
            'audience' => ['lists' => [$list->id]],
            'track_opens' => true, 'track_clicks' => true,
            'is_ab_test' => true, 'ab_test_type' => 'subject',
            'ab_sample_percent' => 50, 'ab_winner_metric' => 'opens',
            'ab_decide_after_minutes' => 60,
        ], $overrides));

        $a = CampaignVariant::create([
            'campaign_id' => $campaign->id, 'label' => 'A',
            'subject' => 'Version A subject', 'share_percent' => 50,
        ]);
        $b = CampaignVariant::create([
            'campaign_id' => $campaign->id, 'label' => 'B',
            'subject' => 'Version B subject', 'share_percent' => 50,
        ]);

        app(RecipientGenerator::class)->generate($campaign);

        return [$campaign->refresh(), $a, $b];
    }

    protected function runUntilStuck(Campaign $campaign, int $passes = 20): void
    {
        for ($i = 0; $i < $passes; $i++) {
            $result = $this->app->make(CampaignRunner::class)->runChunk($campaign);

            if ($result['claimed'] === 0) {
                return;
            }
        }
    }

    // ------------------------------------------------------------ the split

    public function test_the_sample_is_divided_and_the_rest_is_held_back(): void
    {
        [$campaign] = $this->splitTest(40);

        $split = $this->ab()->assign($campaign);

        $this->assertGreaterThan(0, $split['sample']);
        $this->assertGreaterThan(0, $split['holdback'], 'A 50% sample of 40 must leave people behind.');
        $this->assertSame(40, $split['sample'] + $split['holdback']);

        $perVariant = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('campaign_variant_id')
            ->selectRaw('campaign_variant_id, COUNT(*) as n')
            ->groupBy('campaign_variant_id')
            ->pluck('n', 'campaign_variant_id');

        $this->assertCount(2, $perVariant, 'Both versions must actually get recipients.');
    }

    public function test_assignment_is_stable_and_does_not_reshuffle_a_running_test(): void
    {
        [$campaign] = $this->splitTest(40);

        $this->ab()->assign($campaign);
        $before = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
            ->orderBy('id')->pluck('campaign_variant_id', 'id');

        $this->ab()->assign($campaign);
        $after = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
            ->orderBy('id')->pluck('campaign_variant_id', 'id');

        $this->assertEquals($before, $after,
            'Re-running assignment must never move somebody who has already been mailed one version.');
    }

    public function test_a_list_too_small_for_a_holdback_becomes_a_straight_split(): void
    {
        [$campaign] = $this->splitTest(2, ['ab_sample_percent' => 5]);

        $split = $this->ab()->assign($campaign);

        $this->assertSame(2, $split['sample']);
        $this->assertSame(0, $split['holdback']);
    }

    /**
     * The test this file exists for. Two versions, two subject lines, and what
     * actually left the transport has to contain both.
     */
    public function test_each_version_is_sent_with_its_own_subject(): void
    {
        [$campaign] = $this->splitTest(40);

        $this->ab()->assign($campaign);
        $this->runUntilStuck($campaign);

        $subjects = collect($this->factory->transport->sent)->pluck('subject')->unique()->values();

        $this->assertContains('Version A subject', $subjects->all());
        $this->assertContains('Version B subject', $subjects->all());
        $this->assertNotContains('Control subject', $subjects->all(),
            'Nobody in the sample should receive the campaign subject; both groups are in a version.');
    }

    public function test_the_holdback_is_not_sent_anything_before_a_winner_is_chosen(): void
    {
        [$campaign] = $this->splitTest(40);

        $split = $this->ab()->assign($campaign);
        $this->runUntilStuck($campaign);

        $this->assertCount($split['sample'], $this->factory->transport->sent,
            'Only the sample may be sent while the test is undecided.');

        $held = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
            ->whereNull('campaign_variant_id')->where('status', 'pending')->count();

        $this->assertSame($split['holdback'], $held);
        $this->assertSame('sending', $campaign->fresh()->status,
            'A campaign still owing mail to a holdback is not completed.');
    }

    public function test_the_chunk_job_reports_that_it_is_waiting_rather_than_spinning(): void
    {
        [$campaign] = $this->splitTest(40);

        $this->ab()->assign($campaign);
        $this->runUntilStuck($campaign);

        $result = $this->app->make(CampaignRunner::class)->runChunk($campaign);

        $this->assertSame(0, $result['claimed']);
        $this->assertFalse($result['finished']);
        $this->assertTrue($result['awaiting_decision'],
            'Without this the send job re-queues itself every second for the whole measuring window.');
    }

    // --------------------------------------------------------- the decision

    public function test_the_winner_is_the_better_rate_not_the_bigger_group(): void
    {
        // A deliberately lopsided split: B gets far fewer recipients, but a
        // better rate. Judging on totals would hand it to A.
        [$campaign, $a, $b] = $this->splitTest(40);
        $a->update(['share_percent' => 80]);
        $b->update(['share_percent' => 20]);

        $this->ab()->assign($campaign);
        $this->runUntilStuck($campaign);

        $this->openAll($campaign, $b->id, 1.0);
        $this->openAll($campaign, $a->id, 0.25);

        $winner = $this->ab()->decide($campaign->fresh());

        $this->assertNotNull($winner);
        $this->assertSame($b->id, $winner->id);
        $this->assertTrue($winner->fresh()->is_winner);
        $this->assertSame($b->id, (int) $campaign->fresh()->ab_winner_variant_id);
    }

    public function test_the_holdback_receives_the_winning_version(): void
    {
        [$campaign, $a, $b] = $this->splitTest(40);

        $this->ab()->assign($campaign);
        $this->runUntilStuck($campaign);

        $sampleCount = count($this->factory->transport->sent);

        $this->openAll($campaign, $b->id, 1.0);

        $this->ab()->decide($campaign->fresh());
        $this->runUntilStuck($campaign->fresh());

        $afterDecision = array_slice($this->factory->transport->sent, $sampleCount);

        $this->assertNotEmpty($afterDecision);

        foreach ($afterDecision as $message) {
            $this->assertSame('Version B subject', $message['subject'],
                'Everybody held back must receive the version that won.');
        }

        $this->assertSame('completed', $campaign->fresh()->status);
    }

    public function test_a_test_is_not_decided_before_its_window_closes(): void
    {
        [$campaign, , $b] = $this->splitTest(40);

        $this->ab()->assign($campaign);
        $this->runUntilStuck($campaign);
        $this->openAll($campaign, $b->id, 1.0);

        $this->assertSame(0, $this->ab()->decideDue()['decided'],
            'The measuring window is the whole point of a split test.');

        $this->travelTo(now()->addMinutes(90));

        $this->assertSame(1, $this->ab()->decideDue()['decided']);
    }

    public function test_a_test_where_nothing_was_delivered_declares_no_winner(): void
    {
        [$campaign] = $this->splitTest(40);
        $this->ab()->assign($campaign);

        $this->assertNull($this->ab()->decide($campaign),
            'A version that delivered nothing has not won anything.');
        $this->assertNull($campaign->fresh()->ab_decided_at);
    }

    public function test_deciding_twice_does_not_release_the_holdback_twice(): void
    {
        [$campaign, , $b] = $this->splitTest(40);

        $this->ab()->assign($campaign);
        $this->runUntilStuck($campaign);
        $this->openAll($campaign, $b->id, 1.0);

        $this->assertNotNull($this->ab()->decide($campaign->fresh()));
        $this->assertNull($this->ab()->decide($campaign->fresh()),
            'A second decision must find the first already recorded.');
    }

    // ------------------------------------------------------------- blockers

    public function test_two_identical_versions_are_refused_as_a_test(): void
    {
        [$campaign, $a, $b] = $this->splitTest(10);
        $b->update(['subject' => 'Version A subject']);

        $blockers = app(CampaignDispatcher::class)->blockers($campaign->fresh());

        $this->assertNotEmpty(array_filter($blockers, fn ($p) => str_contains($p, 'identical')));
    }

    public function test_a_split_test_with_one_version_is_refused(): void
    {
        [$campaign, , $b] = $this->splitTest(10);
        $b->delete();

        $blockers = app(CampaignDispatcher::class)->blockers($campaign->fresh());

        $this->assertNotEmpty(array_filter($blockers, fn ($p) => str_contains($p, 'fewer than two')));
    }

    public function test_an_ordinary_campaign_is_untouched_by_any_of_this(): void
    {
        [$campaign] = $this->splitTest(10, ['is_ab_test' => false]);

        $this->assertSame(['sample' => 0, 'holdback' => 0], $this->ab()->assign($campaign));

        $this->runUntilStuck($campaign);

        $subjects = collect($this->factory->transport->sent)->pluck('subject')->unique();

        $this->assertSame(['Control subject'], $subjects->all());
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    // --------------------------------------------------------------- helpers

    /** Marks a share of one variant's recipients as having opened. */
    protected function openAll(Campaign $campaign, int $variantId, float $share): void
    {
        $ids = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->where('campaign_variant_id', $variantId)
            ->where('status', 'sent')
            ->orderBy('id')
            ->pluck('id');

        $take = (int) ceil($ids->count() * $share);

        if ($take === 0) {
            return;
        }

        CampaignRecipient::withoutGlobalScopes()
            ->whereIn('id', $ids->take($take)->all())
            ->update(['first_opened_at' => now(), 'open_count' => 1]);
    }

    // ==================== regressions found by the Phase 10 audit ============

    /**
     * `decideDue()` used `withoutGlobalScopes()`, which also strips
     * SoftDeletingScope. A campaign the operator had deleted was still picked
     * up, its winner chosen, its held-back audience released, and somebody
     * notified about a campaign they had binned.
     */
    public function test_a_deleted_campaign_is_never_decided(): void
    {
        [$campaign, $a] = $this->splitTest(6);

        $this->ab()->assign($campaign);

        DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->update([
            'campaign_variant_id' => $a->id, 'status' => 'sent', 'sent_at' => now(),
            'first_opened_at' => now(), 'open_count' => 1,
        ]);

        $campaign->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $campaign->delete();

        $this->travelTo(now()->addMinutes(120));

        $this->assertSame(0, $this->ab()->decideDue()['decided'],
            'A deleted campaign must not be decided.');
        $this->assertNull(Campaign::withTrashed()->find($campaign->id)->ab_decided_at);
        $this->assertSame(0, DB::table('notifications')->count(),
            'and nobody may be notified about a campaign they deleted.');
    }

    /**
     * `--campaign` means "ignore the waiting window", not "ignore the data".
     * The screen refuses this state with a 422 and the sweep skips it; the
     * command would pick a winner by comparing a version that had gone out
     * against one that had sent nothing at all.
     */
    public function test_the_console_override_still_waits_for_the_sample_to_finish(): void
    {
        [$campaign, $a, $b] = $this->splitTest(40);

        $this->ab()->assign($campaign);

        $sample = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
            ->whereNotNull('campaign_variant_id')->orderBy('id')->pluck('id');

        $half = (int) floor($sample->count() / 2);

        // A has gone out. B is still entirely queued.
        DB::table('campaign_recipients')->whereIn('id', $sample->take($half)->all())
            ->update(['campaign_variant_id' => $a->id, 'status' => 'sent', 'sent_at' => now()]);
        DB::table('campaign_recipients')->whereIn('id', $sample->take(1)->all())
            ->update(['first_opened_at' => now(), 'open_count' => 1]);
        DB::table('campaign_recipients')->whereIn('id', $sample->slice($half)->all())
            ->update(['campaign_variant_id' => $b->id, 'status' => 'pending', 'sent_at' => null]);

        $this->artisan('campaigns:decide-ab', ['--campaign' => $campaign->id])
            ->expectsOutputToContain('has not gone out yet')
            ->assertExitCode(1);

        $campaign->refresh();

        $this->assertNull($campaign->ab_decided_at,
            'No winner may be chosen against a version that has sent nothing.');
        $this->assertNull($campaign->ab_winner_variant_id);
    }

    /**
     * The split is decided by CRC32 over string literals in raw SQL. Written
     * with double quotes, those are IDENTIFIERS under MySQL's ANSI_QUOTES
     * sql_mode, and assignment died with "Unknown column ':'". Nobody would
     * find that until the day the app met a server configured that way.
     */
    public function test_the_split_survives_ansi_quotes(): void
    {
        [$campaign] = $this->splitTest(20);

        DB::statement("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");

        try {
            $result = $this->ab()->assign($campaign);

            $this->assertGreaterThan(0, $result['sample']);
            $this->assertGreaterThan(0, $this->ab()->results($campaign)->sum('assigned'));
        } finally {
            DB::statement("SET SESSION sql_mode = REPLACE(@@sql_mode, ',ANSI_QUOTES', '')");
        }
    }
}
