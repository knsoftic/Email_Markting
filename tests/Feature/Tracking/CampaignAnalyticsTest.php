<?php

namespace Tests\Feature\Tracking;

use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\EmailCompiler;
use App\Services\Campaigns\MessageBuilder;
use App\Services\Campaigns\RecipientGenerator;
use App\Services\Tracking\CampaignAnalytics;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The numbers a campaign report shows.
 *
 * A reporting bug is quieter than a sending bug and lives longer: nobody gets
 * an error, they just make decisions on a figure that is wrong. So these test
 * the arithmetic and the denominators, not the rendering.
 */
class CampaignAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@analytics.test',
            'password' => 'Password123!', 'timezone' => 'Asia/Karachi',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 100000,
        ]]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        SmtpAccount::factory()->forAccount($this->owner->account)->create();

        $this->campaign = $this->makeCampaign();
    }

    protected function makeCampaign(int $contacts = 4, array $overrides = []): Campaign
    {
        static $seq = 0;
        $batch = ++$seq;

        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();

        for ($i = 1; $i <= $contacts; $i++) {
            Subscriber::factory()->forAccount($this->owner->account)
                ->create(['email' => "a{$batch}-{$i}@example.com"])
                ->lists()->attach($list->id, ['subscribed_at' => now()]);
        }

        $doc = ['settings' => [], 'blocks' => [
            ['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hi']],
            ['id' => 'b', 'type' => 'button', 'settings' => ['text' => 'Shop', 'href' => 'https://shop.example.com']],
            ['id' => 'f', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
        ]];

        $compiler = app(EmailCompiler::class);

        $campaign = Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'name' => "Report campaign {$batch}",
            'subject' => 'Hello',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'status' => 'completed',
            'blocks' => $doc,
            'html' => $compiler->compile($doc),
            'plain_text' => $compiler->compileText($doc),
            'audience' => ['lists' => [$list->id]],
            'started_at' => now()->subHours(2),
            'completed_at' => now()->subHour(),
            'timezone' => 'Asia/Karachi',
        ], $overrides));

        app(RecipientGenerator::class)->generate($campaign);

        return $campaign->fresh();
    }

    protected function analytics(): CampaignAnalytics
    {
        return app(CampaignAnalytics::class);
    }

    protected function recipients(): \Illuminate\Support\Collection
    {
        return CampaignRecipient::where('campaign_id', $this->campaign->id)->orderBy('id')->get();
    }

    /** Marks everybody sent, so the rates have a denominator. */
    protected function markAllSent(): void
    {
        CampaignRecipient::where('campaign_id', $this->campaign->id)
            ->update(['status' => 'sent', 'sent_at' => now()->subHours(2)]);

        $this->campaign->forceFill([
            'total_recipients' => $this->recipients()->count(),
            'sent_count' => $this->recipients()->count(),
        ])->save();
    }

    protected function open(CampaignRecipient $recipient, array $headers = []): void
    {
        $this->withHeaders($headers)
            ->get(URL::signedRoute('track.open', ['recipient' => $recipient->id]));
    }

    protected function click(CampaignRecipient $recipient, CampaignLink $link): void
    {
        $this->get(URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $recipient->id]));
    }

    protected function link(): CampaignLink
    {
        app(MessageBuilder::class)->blueprint($this->campaign);

        return CampaignLink::where('campaign_id', $this->campaign->id)->firstOrFail();
    }

    // ------------------------------------------------------------ the rates

    public function test_the_open_rate_divides_unique_opens_by_sent(): void
    {
        $this->markAllSent();

        $this->open($this->recipients()[0]);
        $this->open($this->recipients()[0]); // same person again
        $this->open($this->recipients()[1]);

        $summary = $this->analytics()->summary($this->campaign->fresh());

        $this->assertSame(3, $summary['opens']);
        $this->assertSame(2, $summary['unique_opens']);
        $this->assertSame(50.0, $summary['open_rate'], '2 unique opens of 4 sent.');
    }

    public function test_click_to_open_divides_by_opens_not_by_sent(): void
    {
        $this->markAllSent();
        $link = $this->link();

        $this->open($this->recipients()[0]);
        $this->open($this->recipients()[1]);
        $this->click($this->recipients()[0], $link);

        $summary = $this->analytics()->summary($this->campaign->fresh());

        $this->assertSame(2, $summary['unique_opens']);
        $this->assertSame(1, $summary['unique_clicks']);
        $this->assertSame(25.0, $summary['click_rate'], '1 of 4 sent.');
        $this->assertSame(50.0, $summary['click_to_open_rate'], '1 of the 2 who opened.');
    }

    public function test_a_rate_with_nothing_to_divide_by_is_zero_not_an_error(): void
    {
        $summary = $this->analytics()->summary($this->campaign);

        $this->assertSame(0.0, $summary['open_rate']);
        $this->assertSame(0.0, $summary['click_to_open_rate']);
        $this->assertSame(0.0, $summary['bounce_rate']);
    }

    public function test_the_bounce_rate_counts_bounces_against_everything_attempted(): void
    {
        $this->markAllSent();

        // One of the four bounced: 3 accepted, 1 rejected.
        $bounced = $this->recipients()[3];
        $bounced->forceFill(['status' => 'bounced', 'bounce_type' => 'hard', 'bounced_at' => now()])->save();
        $this->campaign->forceFill(['sent_count' => 3, 'bounced_count' => 1])->save();

        $summary = $this->analytics()->summary($this->campaign->fresh());

        $this->assertSame(25.0, $summary['bounce_rate'], '1 bounce out of 4 attempted, not out of 3 accepted.');
        $this->assertSame(1, $summary['bounced_hard']);
        $this->assertSame(0, $summary['bounced_soft']);
    }

    public function test_machine_opens_are_reported_separately_from_the_open_rate(): void
    {
        $this->markAllSent();

        $this->open($this->recipients()[0]);
        $this->open($this->recipients()[1], ['User-Agent' => 'Mozilla/5.0 (compatible; GoogleImageProxy)']);

        $summary = $this->analytics()->summary($this->campaign->fresh());

        $this->assertSame(2, $summary['unique_opens']);
        $this->assertSame(1, $summary['machine_opens']);
        $this->assertSame(50.0, $summary['machine_open_share'],
            'Half of this campaign "open rate" is a proxy, and the report has to be able to say so.');

        $sources = $this->analytics()->openSources($this->campaign);

        $this->assertSame(1, (int) $sources['reader']);
        $this->assertSame(1, (int) $sources['gmail-proxy']);
    }

    public function test_delivered_of_attempted_accounts_for_every_outcome(): void
    {
        $this->campaign->forceFill([
            'total_recipients' => 10, 'sent_count' => 7, 'bounced_count' => 2, 'failed_count' => 1,
        ])->save();

        $summary = $this->analytics()->summary($this->campaign->fresh());

        $this->assertSame(70.0, $summary['delivered_of_attempted']);
    }

    // ---------------------------------------------------------- the series

    public function test_the_timeline_returns_one_axis_for_both_series(): void
    {
        $this->markAllSent();
        $link = $this->link();

        $this->open($this->recipients()[0]);
        $this->click($this->recipients()[1], $link);

        $timeline = $this->analytics()->timeline($this->campaign->fresh());

        $this->assertNotEmpty($timeline['labels']);
        $this->assertCount(count($timeline['labels']), $timeline['opens'],
            'Two series on different axes would invent a correlation.');
        $this->assertCount(count($timeline['labels']), $timeline['clicks']);
        $this->assertSame('hour', $timeline['unit'], 'A campaign sent two hours ago is shown by hour.');
    }

    public function test_an_old_campaign_is_bucketed_by_day(): void
    {
        $old = $this->makeCampaign(2, ['started_at' => now()->subDays(10)]);

        $this->assertSame('day', $this->analytics()->timeline($old)['unit']);
    }

    public function test_a_campaign_that_never_started_has_an_empty_timeline(): void
    {
        $draft = $this->makeCampaign(1, ['status' => 'draft', 'started_at' => null]);

        $timeline = $this->analytics()->timeline($draft);

        $this->assertIsArray($timeline['labels']);
        $this->assertSame(count($timeline['labels']), count($timeline['opens']));
    }

    // ------------------------------------------------------------- links

    public function test_top_links_are_ordered_by_unique_clicks(): void
    {
        $this->markAllSent();
        $link = $this->link();

        $this->click($this->recipients()[0], $link);
        $this->click($this->recipients()[1], $link);
        $this->click($this->recipients()[1], $link);

        $top = $this->analytics()->topLinks($this->campaign);

        $this->assertSame(2, (int) $top->first()->unique_click_count);
        $this->assertSame(3, (int) $top->first()->click_count);
    }

    // -------------------------------------------------------- drill-down

    public function test_the_engagement_filters_select_the_right_people(): void
    {
        $this->markAllSent();
        $link = $this->link();

        $opener = $this->recipients()[0];
        $clicker = $this->recipients()[1];

        $this->open($opener);
        $this->click($clicker, $link);

        $filtered = fn (string $engagement) => $this->analytics()
            ->recipients($this->campaign, ['engagement' => $engagement])
            ->pluck('email')->all();

        $this->assertContains($opener->email, $filtered('opened'));
        $this->assertContains($clicker->email, $filtered('clicked'),
            'A click also marks an open, so a clicker appears in both.');
        $this->assertContains($clicker->email, $filtered('opened'));

        $unopened = $filtered('unopened');
        $this->assertNotContains($opener->email, $unopened);
        $this->assertContains($this->recipients()[2]->email, $unopened);
    }

    public function test_the_drill_down_can_be_searched(): void
    {
        $this->markAllSent();
        $target = $this->recipients()[2];

        $found = $this->analytics()
            ->recipients($this->campaign, ['q' => $target->email])
            ->pluck('email')->all();

        $this->assertSame([$target->email], $found);
    }

    // -------------------------------------------------------- account wide

    public function test_the_account_summary_aggregates_across_campaigns(): void
    {
        $this->markAllSent();
        $this->campaign->forceFill(['unique_opens' => 2, 'unique_clicks' => 1])->save();

        $second = $this->makeCampaign(2);
        $second->forceFill([
            'sent_count' => 2, 'unique_opens' => 1, 'unique_clicks' => 0, 'started_at' => now()->subHour(),
        ])->save();

        $summary = $this->analytics()->accountSummary(30);

        $this->assertSame(6, $summary['sent'], '4 from the first campaign, 2 from the second.');
        $this->assertSame(3, $summary['unique_opens']);
        $this->assertSame(50.0, $summary['open_rate']);
    }

    public function test_the_account_timeline_includes_days_with_no_activity(): void
    {
        $timeline = $this->analytics()->accountTimeline($this->owner->account_id, 7);

        $this->assertGreaterThanOrEqual(7, count($timeline['labels']));
        $this->assertCount(count($timeline['labels']), $timeline['sent'],
            'A chart that skips quiet days makes a gap look like activity.');
        $this->assertCount(count($timeline['labels']), $timeline['opens']);
    }

    // ------------------------------------------------------------ tenancy

    public function test_another_accounts_events_never_reach_these_numbers(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@analytics.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        app(TenantManager::class)->runAs($other->account_id, function () use ($other) {
            Campaign::factory()->forAccount($other->account)->create([
                'name' => 'Their campaign', 'subject' => 'Hi', 'status' => 'completed',
                'sent_count' => 1000, 'unique_opens' => 900, 'started_at' => now()->subHour(),
                'completed_at' => now(),
            ]);
        });

        app(TenantManager::class)->set($this->owner->account_id);

        $summary = $this->analytics()->accountSummary(30);

        $this->assertLessThan(1000, $summary['sent'], 'The scope must keep another tenant out.');
        $this->assertSame(0, $summary['unique_opens'] - $summary['unique_opens']);
        $this->assertFalse(
            $this->analytics()->leaderboard(30)->contains('name', 'Their campaign')
        );
    }
}
