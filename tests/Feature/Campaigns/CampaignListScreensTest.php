<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\EmailCompiler;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampaignListScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected SubscriberList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Sweep Ltd', 'name' => 'Owner', 'email' => 'owner@sweep.test',
            'password' => 'Password123!', 'timezone' => 'Asia/Karachi',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 100000,
        ])]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        SmtpAccount::factory()->forAccount($this->owner->account)->create();
        $this->list = SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => 'Main list']);

        Subscriber::factory()->forAccount($this->owner->account)->create(['email' => 's1@example.com'])
            ->lists()->attach($this->list->id, ['subscribed_at' => now()]);
    }

    protected function make(array $overrides = []): Campaign
    {
        $document = ['settings' => [], 'blocks' => [
            ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Hello']],
            ['id' => 'b2', 'type' => 'footer', 'settings' => ['companyLine' => 'Sweep Ltd']],
        ]];

        return Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'subject' => 'Hello',
            'from_name' => 'Sweep Ltd',
            'from_email' => 'hello@sweep.test',
            'blocks' => $document,
            'html' => app(EmailCompiler::class)->compile($document),
            'audience' => ['lists' => [$this->list->id]],
            'timezone' => 'Asia/Karachi',
        ], $overrides));
    }

    public function test_the_index_renders_a_campaign_in_every_status(): void
    {
        foreach (Campaign::STATUSES as $status) {
            $this->make([
                'name' => "Row {$status}",
                'status' => $status,
                'scheduled_at' => $status === 'scheduled' ? now()->addDay() : null,
                'total_recipients' => 40,
                'sent_count' => 12,
                'failed_count' => 2,
                'bounced_count' => 1,
                'opened_count' => 9,
                'unique_opens' => 6,
                'clicked_count' => 4,
                'unique_clicks' => 3,
            ]);
        }

        $response = $this->get('/campaigns')->assertOk();

        foreach (Campaign::STATUSES as $status) {
            $response->assertSee("Row {$status}");
        }
    }

    public function test_the_index_renders_hostile_rows(): void
    {
        // Every counter at zero, no audience, no timestamps at all.
        $bare = $this->make(['name' => 'Bare draft', 'status' => 'draft', 'audience' => null, 'html' => null]);
        DB::table('campaigns')->where('id', $bare->id)->update([
            'created_at' => null, 'updated_at' => null, 'subject' => '',
        ]);

        // A time zone no longer in timezone_identifiers_list().
        $stale = $this->make(['name' => 'Stale zone', 'status' => 'completed', 'total_recipients' => 3, 'sent_count' => 3]);
        DB::table('campaigns')->where('id', $stale->id)->update(['timezone' => 'Mars/Olympus', 'completed_at' => null]);

        // Cancelled from a schedule: never generated a single recipient row.
        $this->make(['name' => 'Cancelled schedule', 'status' => 'cancelled', 'total_recipients' => 0]);

        // Running with nothing recorded yet.
        $this->make(['name' => 'Fresh queue', 'status' => 'queued', 'total_recipients' => 0, 'started_at' => null]);

        // Paused mid-flight.
        $this->make(['name' => 'Half done', 'status' => 'paused', 'total_recipients' => 10, 'sent_count' => 4, 'paused_at' => null]);

        $this->get('/campaigns')->assertOk()
            ->assertSee('Bare draft')
            ->assertSee('Stale zone')
            ->assertSee('Cancelled schedule')
            ->assertSee('No recipients')
            ->assertSee('Resolved at send time');

        // Filters, search, a nonsense status, and an out-of-range page.
        $this->get('/campaigns?status=cancelled')->assertOk();
        $this->get('/campaigns?status=nonsense')->assertOk();
        $this->get('/campaigns?q=Bare&status=draft')->assertOk()->assertSee('Bare draft');
        $this->get('/campaigns?q=nothing+matches+this')->assertOk()->assertSee('No campaigns match this search');
        $this->get('/campaigns?page=9')->assertOk();
    }

    public function test_the_scheduled_screen_renders_hostile_rows(): void
    {
        $this->get('/campaigns/scheduled')->assertOk()->assertSee('Nothing is scheduled');

        // Overdue, so the "starting shortly" badge and the past wording run.
        $this->make(['name' => 'Overdue one', 'status' => 'scheduled', 'scheduled_at' => now()->subHours(3)]);

        // Future, so the "in ..." wording runs.
        $this->make(['name' => 'Future one', 'status' => 'scheduled', 'scheduled_at' => now()->addDays(2)]);

        // Scheduled with no time and no audience at all.
        $orphan = $this->make(['name' => 'No time set', 'status' => 'scheduled', 'scheduled_at' => null, 'audience' => null]);
        DB::table('campaigns')->where('id', $orphan->id)->update(['from_name' => '', 'reply_to' => null]);

        // A stored zone that no longer exists.
        $stale = $this->make(['name' => 'Stale zone row', 'status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
        DB::table('campaigns')->where('id', $stale->id)->update(['timezone' => 'Mars/Olympus']);

        // Previously counted, then re-scheduled.
        $this->make(['name' => 'Counted before', 'status' => 'scheduled', 'scheduled_at' => now()->addDay(),
            'total_recipients' => 25, 'reply_to' => 'replies@sweep.test',
            'audience' => ['lists' => [$this->list->id], 'tags' => [], 'segments' => []]]);

        $this->get('/campaigns/scheduled')->assertOk()
            ->assertSee('Overdue one')
            ->assertSee('Future one')
            ->assertSee('No time set')
            ->assertSee('Stale zone row')
            ->assertSee('Counted before')
            ->assertSee('Send time missing')
            ->assertSee('Nothing selected')
            ->assertSee('25 at the last count');

        $this->get('/campaigns/scheduled?page=9')->assertOk();
    }

    public function test_a_deleted_scheduled_campaign_is_not_dispatched(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $campaign = $this->make([
            'name' => 'Deleted but due', 'status' => 'scheduled', 'scheduled_at' => now()->subMinute(),
        ]);

        // Exactly what the Delete button on campaigns/index does.
        $this->delete("/campaigns/{$campaign->id}")->assertRedirect('/campaigns');
        $this->assertNull(Campaign::find($campaign->id), 'The row must be gone from normal reads.');

        $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

        $row = DB::table('campaigns')->where('id', $campaign->id)->first();

        $this->assertSame('scheduled', $row->status,
            'A deleted campaign must not be picked up and started by the scheduler.');
        $this->assertSame(0, DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->count(),
            'A deleted campaign must not have recipients generated for it.');
    }

    public function test_a_read_only_member_sees_no_send_controls_on_either_screen(): void
    {
        $this->make(['name' => 'Waiting row', 'status' => 'scheduled', 'scheduled_at' => now()->addDay()]);

        $staff = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => \App\Models\Role::withoutGlobalScopes()->where('slug', \App\Models\Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/campaigns')->assertOk()->assertDontSee('Review &amp; send', false)->assertDontSee('Delete');
        $this->get('/campaigns/scheduled')->assertOk()->assertDontSee('Unschedule')->assertDontSee('Review &amp; send now', false);
    }
}
