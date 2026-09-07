<?php

namespace Tests\Feature\Tracking;

use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Models\Role;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\EmailCompiler;
use App\Services\Campaigns\MessageBuilder;
use App\Services\Campaigns\RecipientGenerator;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The analytics screens through HTTP.
 *
 * A report screen fails quietly: it renders, and the number on it is wrong or
 * the page dies only for the one campaign shape nobody tried. So these walk
 * every state a campaign can be in rather than just the happy one.
 */
class AnalyticsScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@screens6.test',
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

    protected function makeCampaign(int $contacts = 3, array $overrides = []): Campaign
    {
        static $seq = 0;
        $batch = ++$seq;

        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();

        for ($i = 1; $i <= $contacts; $i++) {
            Subscriber::factory()->forAccount($this->owner->account)
                ->create(['email' => "s{$batch}-{$i}@example.com"])
                ->lists()->attach($list->id, ['subscribed_at' => now()]);
        }

        $doc = ['settings' => [], 'blocks' => [
            ['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hi']],
            ['id' => 'b', 'type' => 'button', 'settings' => ['text' => 'Shop', 'href' => 'https://shop.example.com/sale']],
            ['id' => 'f', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
        ]];

        $compiler = app(EmailCompiler::class);

        $campaign = Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'name' => "Reported campaign {$batch}",
            'subject' => 'Hello',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'status' => 'completed',
            'blocks' => $doc,
            'html' => $compiler->compile($doc),
            'plain_text' => $compiler->compileText($doc),
            'audience' => ['lists' => [$list->id]],
            'started_at' => now()->subHours(3),
            'completed_at' => now()->subHour(),
            'timezone' => 'Asia/Karachi',
        ], $overrides));

        app(RecipientGenerator::class)->generate($campaign);

        return $campaign->fresh();
    }

    protected function withEngagement(): CampaignLink
    {
        CampaignRecipient::where('campaign_id', $this->campaign->id)
            ->update(['status' => 'sent', 'sent_at' => now()->subHours(3)]);

        $this->campaign->forceFill(['total_recipients' => 3, 'sent_count' => 3])->save();

        app(MessageBuilder::class)->blueprint($this->campaign);
        $link = CampaignLink::where('campaign_id', $this->campaign->id)->firstOrFail();

        $rows = CampaignRecipient::where('campaign_id', $this->campaign->id)->orderBy('id')->get();

        $this->get(URL::signedRoute('track.open', ['recipient' => $rows[0]->id]));
        $this->withHeaders(['User-Agent' => 'GoogleImageProxy'])
            ->get(URL::signedRoute('track.open', ['recipient' => $rows[1]->id]));
        $this->get(URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $rows[0]->id]));

        return $link;
    }

    // ------------------------------------------------------------ rendering

    public function test_both_screens_render(): void
    {
        $this->withEngagement();

        $this->get('/analytics')->assertOk();
        $this->get("/analytics/campaigns/{$this->campaign->id}")
            ->assertOk()
            ->assertSee('Reported campaign 1');
    }

    public function test_the_dashboard_renders_for_an_account_that_has_never_sent(): void
    {
        Campaign::query()->delete();

        $this->get('/analytics')->assertOk();
    }

    public function test_a_campaign_report_renders_in_every_status(): void
    {
        foreach (['draft', 'scheduled', 'queued', 'sending', 'paused', 'completed', 'failed', 'cancelled'] as $status) {
            $this->campaign->forceFill([
                'status' => $status,
                'started_at' => $status === 'draft' ? null : now()->subHour(),
            ])->save();

            $this->get("/analytics/campaigns/{$this->campaign->id}")
                ->assertOk("The report died on a {$status} campaign.");
        }
    }

    public function test_a_campaign_with_no_recipients_at_all_does_not_crash(): void
    {
        CampaignRecipient::where('campaign_id', $this->campaign->id)->delete();
        $this->campaign->forceFill(['total_recipients' => 0, 'sent_count' => 0])->save();

        $this->get("/analytics/campaigns/{$this->campaign->id}")->assertOk();
    }

    public function test_a_malformed_query_string_does_not_crash_either_screen(): void
    {
        foreach ([
            '/analytics?days=abc',
            '/analytics?days[]=7',
            '/analytics?days=99999',
            "/analytics/campaigns/{$this->campaign->id}?q[]=x",
            "/analytics/campaigns/{$this->campaign->id}?engagement[]=x",
            "/analytics/campaigns/{$this->campaign->id}?status=nonsense",
            "/analytics/campaigns/{$this->campaign->id}?page=999",
        ] as $url) {
            $this->get($url)->assertOk("Crashed on {$url}");
        }
    }

    // ------------------------------------------------------------- filters

    public function test_the_engagement_filter_narrows_the_drill_down(): void
    {
        $this->withEngagement();

        $rows = CampaignRecipient::where('campaign_id', $this->campaign->id)->orderBy('id')->get();

        $this->get("/analytics/campaigns/{$this->campaign->id}?engagement=clicked")
            ->assertOk()
            ->assertSee($rows[0]->email)
            ->assertDontSee($rows[2]->email);
    }

    public function test_the_window_switcher_is_honoured(): void
    {
        $this->get('/analytics?days=7')->assertOk()->assertSee('7');
        $this->get('/analytics?days=90')->assertOk();
    }

    // -------------------------------------------------------------- export

    public function test_the_export_streams_the_filtered_recipients(): void
    {
        $this->withEngagement();

        $response = $this->get("/analytics/campaigns/{$this->campaign->id}/export?engagement=clicked");

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $rows = CampaignRecipient::where('campaign_id', $this->campaign->id)->orderBy('id')->get();

        $this->assertStringContainsString('Email', $csv);
        $this->assertStringContainsString($rows[0]->email, $csv);
        $this->assertStringNotContainsString($rows[2]->email, $csv,
            'The export must match what the filter shows on screen.');
    }

    /**
     * The replies note was written in Phase 6, when nothing measured replies,
     * and said so. Phase 9 measures them — a screen showing "Replies 1" above
     * "replies are not being measured" is the kind of contradiction that makes
     * a whole report untrustworthy.
     */
    public function test_the_replies_note_reflects_whether_a_mailbox_can_see_them(): void
    {
        $this->get("/analytics/campaigns/{$this->campaign->id}")
            ->assertOk()
            ->assertSee('No mailbox is connected, so replies cannot be counted.')
            ->assertDontSee('Replies are not being measured yet');

        \App\Models\Mailbox::create([
            'user_id' => $this->owner->id, 'name' => 'Support', 'email' => 'support@senders.test',
            'imap_host' => 'imap.senders.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@senders.test', 'imap_password' => 'secret',
        ]);

        $this->get("/analytics/campaigns/{$this->campaign->id}")
            ->assertOk()
            ->assertSee('How replies are counted.')
            ->assertDontSee('No mailbox is connected');
    }

    // ------------------------------------------------------------- rebuild

    public function test_rebuilding_repairs_drifted_counters(): void
    {
        $link = $this->withEngagement();

        $this->campaign->forceFill([
            'opened_count' => 999, 'unique_opens' => 999, 'clicked_count' => 999, 'unique_clicks' => 999,
        ])->save();
        $link->forceFill(['click_count' => 999, 'unique_click_count' => 999])->save();

        $this->post("/analytics/campaigns/{$this->campaign->id}/rebuild")
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = $this->campaign->fresh();

        $this->assertSame(2, (int) $campaign->opened_count);
        $this->assertSame(2, (int) $campaign->unique_opens);
        $this->assertSame(1, (int) $campaign->clicked_count);
        $this->assertSame(1, (int) $link->fresh()->click_count);
    }

    // --------------------------------------------------------- permissions

    public function test_a_role_without_analytics_view_is_refused(): void
    {
        $staff = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        // Staff ships with analytics.view; take it away to prove the gate.
        $role = Role::withoutGlobalScopes()->where('slug', Role::STAFF)->first();
        $role->permissions()->detach(
            \App\Models\Permission::where('slug', 'analytics.view')->value('id')
        );

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/analytics')->assertForbidden();
        $this->get("/analytics/campaigns/{$this->campaign->id}")->assertForbidden();
        $this->post("/analytics/campaigns/{$this->campaign->id}/rebuild")->assertForbidden();
    }

    // ------------------------------------------------------------- tenancy

    public function test_another_accounts_campaign_report_is_not_reachable(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@screens6.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = app(TenantManager::class)->runAs($other->account_id, fn () => Campaign::factory()
            ->forAccount($other->account)->create(['name' => 'Theirs', 'subject' => 'Hi']));

        app(TenantManager::class)->set($this->owner->account_id);

        $this->get("/analytics/campaigns/{$foreign->id}")->assertNotFound();
        $this->get("/analytics/campaigns/{$foreign->id}/export")->assertNotFound();
        $this->post("/analytics/campaigns/{$foreign->id}/rebuild")->assertNotFound();
    }
}
