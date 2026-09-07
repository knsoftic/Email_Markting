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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The campaign screens through HTTP. The point of these is less "does it
 * render" than "can a real person get from a draft to a send without the app
 * doing something it should not".
 */
class CampaignScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected SubscriberList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@screens.test',
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

        for ($i = 1; $i <= 3; $i++) {
            Subscriber::factory()->forAccount($this->owner->account)
                ->create(['email' => "s{$i}@example.com"])
                ->lists()->attach($this->list->id, ['subscribed_at' => now()]);
        }
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'March newsletter',
            'subject' => 'Hello {{first_name|there}}',
            'preview_text' => 'The short line',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'reply_to' => null,
            'timezone' => 'Asia/Karachi',
            'track_opens' => '1',
            'track_clicks' => '1',
            'audience' => ['lists' => [$this->list->id]],
            'blocks' => [
                ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Hello']],
                ['id' => 'b2', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
            ],
            'settings' => [],
        ], $overrides);
    }

    protected function campaign(array $overrides = []): Campaign
    {
        $document = ['settings' => [], 'blocks' => [
            ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Hello']],
            ['id' => 'b2', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
        ]];

        $compiler = app(EmailCompiler::class);

        return Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'name' => 'March newsletter',
            'subject' => 'Hello',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'status' => 'draft',
            'blocks' => $document,
            'html' => $compiler->compile($document),
            'plain_text' => $compiler->compileText($document),
            'audience' => ['lists' => [$this->list->id]],
            'timezone' => 'Asia/Karachi',
        ], $overrides));
    }

    // ------------------------------------------------------------- rendering

    public function test_every_campaign_screen_renders(): void
    {
        $campaign = $this->campaign();

        $this->get('/campaigns')->assertOk()->assertSee('March newsletter');
        $this->get('/campaigns/create')->assertOk();
        $this->get('/campaigns/scheduled')->assertOk();
        $this->get("/campaigns/{$campaign->id}")->assertOk()->assertSee('March newsletter');
        $this->get("/campaigns/{$campaign->id}/edit")->assertOk();
        $this->get("/campaigns/{$campaign->id}/confirm")->assertOk();
    }

    public function test_the_scheduled_screen_lists_only_scheduled_campaigns(): void
    {
        $this->campaign(['name' => 'Just a draft']);
        $this->campaign(['name' => 'Going out Friday', 'status' => 'scheduled', 'scheduled_at' => now()->addDay()]);

        $this->get('/campaigns/scheduled')
            ->assertOk()
            ->assertSee('Going out Friday')
            ->assertDontSee('Just a draft');
    }

    public function test_the_confirmation_screen_states_the_reach_and_the_exclusions(): void
    {
        // One contact who matches the list but must not be mailed.
        Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'gone@example.com', 'status' => 'unsubscribed'])
            ->lists()->attach($this->list->id, ['subscribed_at' => now()]);

        $campaign = $this->campaign();

        $this->get("/campaigns/{$campaign->id}/confirm")
            ->assertOk()
            ->assertSee('Main list')
            ->assertSee('3');
    }

    public function test_the_confirmation_screen_shows_why_a_campaign_cannot_be_sent(): void
    {
        $document = ['settings' => [], 'blocks' => [['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Hi']]]];

        $campaign = $this->campaign([
            'blocks' => $document,
            'html' => app(EmailCompiler::class)->compile($document),
        ]);

        $this->get("/campaigns/{$campaign->id}/confirm")
            ->assertOk()
            ->assertSee('unsubscribe link', false);
    }

    /**
     * A query string is attacker-controlled and costs nothing to malform.
     * ?q[]=x makes Request::string() stringify an array, which PHP turns into
     * a warning and Laravel into a 500 — from a plain URL anyone can type.
     */
    public function test_a_malformed_query_string_does_not_crash_the_list_screens(): void
    {
        $this->campaign();

        foreach (['/campaigns?q[]=x', '/campaigns?status[]=x', '/campaigns?q[]=x&status[]=y',
            '/campaigns?status=nonsense', '/campaigns?page=999', '/campaigns/scheduled?page=999'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /**
     * The redisplay after a rejected save is the one moment the user's work
     * exists only in that response. A field posted as name[]=x comes back from
     * old() as an array, and an unguarded input renders it — trim() on an
     * array is fatal, so the form that rejected the input would 500 instead of
     * showing the error.
     */
    public function test_an_array_posted_into_a_text_field_does_not_crash_the_redisplay(): void
    {
        // Required fields: the array is not a value they could hold, so they
        // are reported as missing and the form redisplays.
        foreach (['name', 'subject', 'from_name', 'from_email'] as $field) {
            $this->from('/campaigns/create')
                ->post('/campaigns', $this->payload([$field => ['x']]))
                ->assertRedirect('/campaigns/create')
                ->assertSessionHasErrors($field);

            $this->get('/campaigns/create')->assertOk();
        }

        // reply_to is optional, so an unusable value is simply not set — but
        // it must not be stored as anything either.
        $this->post('/campaigns', $this->payload(['reply_to' => ['x'], 'name' => 'Optional field']))
            ->assertRedirect();

        $this->assertNull(Campaign::where('name', 'Optional field')->firstOrFail()->reply_to);
    }

    // --------------------------------------------------------------- saving

    public function test_creating_a_campaign_compiles_and_stores_the_audience(): void
    {
        $this->post('/campaigns', $this->payload())->assertRedirect();

        $campaign = Campaign::where('name', 'March newsletter')->firstOrFail();

        $this->assertSame('draft', $campaign->status);
        $this->assertSame([$this->list->id], $campaign->audience['lists']);
        $this->assertStringContainsString('{{unsubscribe_link}}', (string) $campaign->html);
        $this->assertTrue((bool) $campaign->track_opens);
        $this->assertSame($this->owner->id, $campaign->user_id);
    }

    public function test_tracking_can_be_switched_off(): void
    {
        $this->post('/campaigns', $this->payload(['track_opens' => '0', 'track_clicks' => '0']))
            ->assertRedirect();

        $campaign = Campaign::where('name', 'March newsletter')->firstOrFail();

        $this->assertFalse((bool) $campaign->track_opens);
        $this->assertFalse((bool) $campaign->track_clicks);
    }

    public function test_a_missing_subject_is_refused(): void
    {
        $this->post('/campaigns', $this->payload(['subject' => '']))
            ->assertSessionHasErrors('subject');
    }

    public function test_a_malformed_sender_address_is_refused(): void
    {
        $this->post('/campaigns', $this->payload(['from_email' => 'not-an-address']))
            ->assertSessionHasErrors('from_email');
    }

    // ------------------------------------------------------------- controls

    public function test_the_progress_endpoint_reports_the_live_numbers(): void
    {
        $campaign = $this->campaign([
            'status' => 'sending', 'total_recipients' => 10, 'sent_count' => 4, 'failed_count' => 1,
        ]);

        $this->getJson("/campaigns/{$campaign->id}/progress")
            ->assertOk()
            ->assertJson([
                'status' => 'sending',
                'total' => 10,
                'sent' => 4,
                'failed' => 1,
                'finished' => false,
                'paused' => false,
            ]);
    }

    public function test_the_progress_endpoint_says_when_it_is_over(): void
    {
        $campaign = $this->campaign(['status' => 'completed', 'total_recipients' => 5, 'sent_count' => 5]);

        $this->getJson("/campaigns/{$campaign->id}/progress")
            ->assertOk()
            ->assertJson(['finished' => true, 'percent' => 100]);
    }

    public function test_duplicating_produces_a_fresh_draft(): void
    {
        $campaign = $this->campaign(['status' => 'completed', 'sent_count' => 40, 'total_recipients' => 40]);

        $this->post("/campaigns/{$campaign->id}/duplicate")->assertRedirect();

        $copy = Campaign::where('name', 'March newsletter (copy)')->firstOrFail();

        $this->assertSame('draft', $copy->status);
        $this->assertSame(0, (int) $copy->sent_count, 'A copy must not inherit the original\'s reporting.');
        $this->assertSame(0, (int) $copy->total_recipients);
        $this->assertSame($campaign->html, $copy->html);
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $campaign = $this->campaign();

        $this->delete("/campaigns/{$campaign->id}")->assertRedirect('/campaigns');

        $this->assertNull(Campaign::find($campaign->id));
    }

    public function test_a_test_send_does_not_touch_the_campaign(): void
    {
        $campaign = $this->campaign();

        $this->post("/campaigns/{$campaign->id}/test", ['email' => 'reviewer@example.com'])
            ->assertRedirect();

        $campaign->refresh();

        $this->assertSame('draft', $campaign->status);
        $this->assertSame(0, (int) $campaign->sent_count);
    }

    public function test_a_test_send_needs_a_real_address(): void
    {
        $campaign = $this->campaign();

        $this->post("/campaigns/{$campaign->id}/test", ['email' => 'nope'])
            ->assertSessionHasErrors('email');
    }

    // ------------------------------------------------------------ scheduling

    public function test_scheduling_from_the_screen_stores_the_local_time(): void
    {
        Queue::fake();

        $campaign = $this->campaign();

        $this->post("/campaigns/{$campaign->id}/schedule", [
            'scheduled_at' => now('Asia/Karachi')->addDays(2)->format('Y-m-d\TH:i'),
            'timezone' => 'Asia/Karachi',
        ])->assertRedirect();

        $campaign->refresh();

        $this->assertSame('scheduled', $campaign->status);
        $this->assertTrue($campaign->scheduled_at->isFuture());

        $this->post("/campaigns/{$campaign->id}/unschedule")->assertRedirect();

        $this->assertSame('draft', $campaign->fresh()->status);
        $this->assertNull($campaign->fresh()->scheduled_at);
    }

    public function test_an_invalid_timezone_is_refused(): void
    {
        $campaign = $this->campaign();

        $this->post("/campaigns/{$campaign->id}/schedule", [
            'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'timezone' => 'Mars/Olympus',
        ])->assertSessionHasErrors('timezone');
    }

    /**
     * The confirmation screen computes its browser-side minimum when the page
     * renders, so a form left open past its own pre-filled time posts a moment
     * that has since passed. That has to come back on the field rather than as
     * the dispatcher's raw 422 page.
     */
    public function test_a_send_time_that_has_passed_comes_back_as_a_field_error(): void
    {
        Queue::fake();

        $campaign = $this->campaign();

        $this->from("/campaigns/{$campaign->id}/confirm")
            ->post("/campaigns/{$campaign->id}/schedule", [
                'scheduled_at' => now('Asia/Karachi')->subMinutes(20)->format('Y-m-d\TH:i'),
                'timezone' => 'Asia/Karachi',
            ])
            ->assertRedirect("/campaigns/{$campaign->id}/confirm")
            ->assertSessionHasErrors('scheduled_at');

        $this->assertSame('draft', $campaign->fresh()->status);

        // Read in the CHOSEN zone, not the app's: a moment that is past in UTC
        // is still hours away in Kiritimati and must be accepted.
        $this->post("/campaigns/{$campaign->id}/schedule", [
            'scheduled_at' => now('Pacific/Kiritimati')->addMinutes(30)->format('Y-m-d\TH:i'),
            'timezone' => 'Pacific/Kiritimati',
        ])->assertSessionHasNoErrors();

        $this->assertSame('scheduled', $campaign->fresh()->status);
    }

    // ------------------------------------------------------ the campaign page

    public function test_the_campaign_page_and_the_confirmation_render_in_every_status(): void
    {
        foreach (Campaign::STATUSES as $status) {
            $campaign = $this->campaign([
                'name' => "Row {$status}",
                'status' => $status,
                'scheduled_at' => $status === 'scheduled' ? now()->addDay() : null,
                'started_at' => in_array($status, ['queued', 'sending', 'paused', 'completed', 'failed'], true) ? now()->subHour() : null,
                'completed_at' => in_array($status, ['completed', 'cancelled'], true) ? now() : null,
                'paused_at' => $status === 'paused' ? now() : null,
                'total_recipients' => 3, 'sent_count' => 1, 'failed_count' => 1, 'bounced_count' => 1,
                'unique_opens' => 1, 'unique_clicks' => 1,
                'last_error' => $status === 'failed' ? "SMTP 535 <auth> & 'refused'" : null,
            ]);

            $states = ['sent', 'skipped', 'pending'];

            \Illuminate\Support\Facades\DB::table('campaign_recipients')->insert(
                Subscriber::orderBy('id')->get()->values()->map(fn ($s, $i) => [
                    'campaign_id' => $campaign->id, 'subscriber_id' => $s->id, 'email' => $s->email,
                    'status' => $states[$i] ?? 'sent', 'created_at' => now(), 'updated_at' => now(),
                ])->all()
            );

            $this->get("/campaigns/{$campaign->id}")->assertOk();
            $this->get("/campaigns/{$campaign->id}/confirm")->assertOk();
        }
    }

    /**
     * A zone that was valid when the row was written can be dropped from the
     * tz database later. Carbon throws on one it does not know, so the page
     * must fall back the same way the list screens do.
     */
    public function test_a_stale_timezone_does_not_crash_the_campaign_page(): void
    {
        $campaign = $this->campaign(['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);

        \Illuminate\Support\Facades\DB::table('campaigns')
            ->where('id', $campaign->id)->update(['timezone' => 'Mars/Olympus']);

        $this->get("/campaigns/{$campaign->id}")->assertOk();
        $this->get("/campaigns/{$campaign->id}/confirm")->assertOk();
    }

    /** A campaign scheduled from a draft has no recipients yet, and still has to say when it goes out. */
    public function test_a_scheduled_campaign_says_when_it_goes_out(): void
    {
        $campaign = $this->campaign([
            'status' => 'scheduled', 'scheduled_at' => now()->addDays(2),
            'started_at' => null, 'total_recipients' => 0,
        ]);

        $this->get("/campaigns/{$campaign->id}")->assertOk()
            ->assertSee('Nothing has been sent yet')
            ->assertSee($campaign->scheduled_at->copy()->setTimezone('Asia/Karachi')->format('D, d M Y H:i'));
    }

    /** A read-only member must not be shown a titled panel with nothing in it. */
    public function test_a_read_only_member_gets_no_empty_manage_panel(): void
    {
        $campaign = $this->campaign(['status' => 'completed', 'total_recipients' => 3, 'sent_count' => 3]);

        $staff = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => \App\Models\Role::withoutGlobalScopes()->where('slug', \App\Models\Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get("/campaigns/{$campaign->id}")->assertOk()
            ->assertDontSee('Send a test')
            ->assertDontSee('>Manage<', false);

        $this->get("/campaigns/{$campaign->id}/confirm")->assertForbidden();
    }

    // ------------------------------------------------------------- tenancy

    public function test_another_accounts_campaign_is_invisible(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@screens.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = app(TenantManager::class)->runAs($other->account_id, fn () => Campaign::factory()
            ->forAccount($other->account)->create(['name' => 'Their campaign']));

        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/campaigns')->assertOk()->assertDontSee('Their campaign');
        $this->get("/campaigns/{$foreign->id}")->assertNotFound();
        $this->get("/campaigns/{$foreign->id}/confirm")->assertNotFound();
        $this->post("/campaigns/{$foreign->id}/send")->assertNotFound();
    }

    // --------------------------------------------------------- permissions

    public function test_a_read_only_member_cannot_reach_the_send_controls(): void
    {
        $staff = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => \App\Models\Role::withoutGlobalScopes()->where('slug', \App\Models\Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        $campaign = $this->campaign();

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/campaigns')->assertOk();
        $this->get("/campaigns/{$campaign->id}")->assertOk();

        $this->get("/campaigns/{$campaign->id}/confirm")->assertForbidden();
        $this->post("/campaigns/{$campaign->id}/send")->assertForbidden();
        $this->get("/campaigns/{$campaign->id}/edit")->assertForbidden();
        $this->delete("/campaigns/{$campaign->id}")->assertForbidden();
    }
}
