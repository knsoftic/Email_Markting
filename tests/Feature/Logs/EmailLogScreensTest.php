<?php

namespace Tests\Feature\Logs;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Role;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The email log screens.
 *
 * The log is the screen somebody opens when they need to know whether one
 * particular message went out, so the cases that matter most are the ugly
 * ones: a failed attempt with no SMTP account, a row whose campaign has since
 * been deleted, a row with no timestamps at all. None of them may 500, and
 * none of them may quietly render as if the send had succeeded.
 */
class EmailLogScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Campaign $campaign;

    protected SmtpAccount $smtp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Logged Ltd', 'name' => 'Owner', 'email' => 'owner@logscreens.test',
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

        $this->smtp = SmtpAccount::factory()->forAccount($this->owner->account)->create(['name' => 'Primary relay']);
        $this->campaign = Campaign::factory()->forAccount($this->owner->account)->create([
            'name' => 'Spring news', 'subject' => 'Spring news',
        ]);
    }

    /** One log row, with sensible defaults. */
    protected function row(array $overrides = []): CampaignLog
    {
        return CampaignLog::create(array_merge([
            'campaign_id' => $this->campaign->id,
            'smtp_account_id' => $this->smtp->id,
            'recipient_email' => 'reader@example.com',
            'sender_email' => 'hello@logged.test',
            'subject' => 'Spring news',
            'type' => 'campaign',
            'status' => 'sent',
            'sent_at' => now(),
        ], $overrides));
    }

    // --------------------------------------------------------------- index

    public function test_the_index_renders_a_row_of_every_type_and_status(): void
    {
        foreach (['campaign', 'automation', 'transactional', 'test', 'reply'] as $i => $type) {
            $this->row([
                'type' => $type,
                'campaign_id' => $type === 'campaign' ? $this->campaign->id : null,
                'recipient_email' => "type{$i}@example.com",
            ]);
        }

        foreach (['sent', 'failed', 'bounced', 'deferred'] as $i => $status) {
            $this->row([
                'status' => $status,
                'sent_at' => $status === 'sent' ? now() : null,
                'error' => $status === 'sent' ? null : "550 rejected: {$status}",
                'recipient_email' => "status{$i}@example.com",
            ]);
        }

        $response = $this->get('/logs')->assertOk();

        foreach (['Campaign', 'Automation', 'Transactional', 'Test', 'Reply'] as $label) {
            $response->assertSee($label);
        }

        foreach (['Sent', 'Failed', 'Bounced', 'Deferred'] as $label) {
            $response->assertSee($label);
        }

        $response->assertSee('type0@example.com')->assertSee('status3@example.com');
    }

    public function test_the_index_renders_hostile_rows(): void
    {
        // Nothing but an address: no subject, no sender, no SMTP account, no
        // campaign and no timestamps at all.
        $bare = $this->row([
            'campaign_id' => null, 'smtp_account_id' => null, 'sender_email' => null,
            'subject' => null, 'type' => 'campaign', 'status' => 'failed', 'sent_at' => null,
            'recipient_email' => 'bare@example.com',
        ]);
        DB::table('campaign_logs')->where('id', $bare->id)->update(['created_at' => null, 'updated_at' => null]);

        // A failure with no reason recorded.
        $this->row([
            'status' => 'failed', 'sent_at' => null, 'error' => null,
            'recipient_email' => 'silent-failure@example.com',
        ]);

        // The campaign was deleted after the send: the row keeps the id, and
        // the name has to survive with it.
        $deleted = Campaign::factory()->forAccount($this->owner->account)->create(['name' => 'Gone campaign']);
        $this->row(['campaign_id' => $deleted->id, 'recipient_email' => 'orphan@example.com']);
        $deleted->delete();

        // The SMTP account was removed afterwards.
        $oldSmtp = SmtpAccount::factory()->forAccount($this->owner->account)->create(['name' => 'Retired relay']);
        $this->row(['smtp_account_id' => $oldSmtp->id, 'recipient_email' => 'retired@example.com']);
        $oldSmtp->delete();

        // An automation send: no campaign, and no column that could name one.
        $this->row([
            'type' => 'automation', 'campaign_id' => null,
            'recipient_email' => 'drip@example.com',
        ]);

        $response = $this->get('/logs')->assertOk();

        $response->assertSee('bare@example.com')
            ->assertSee('No subject recorded')
            ->assertSee('No time recorded')
            ->assertSee('Not recorded')
            ->assertSee('silent-failure@example.com')
            ->assertSee('No reason was recorded')
            ->assertSee('Gone campaign')
            ->assertSee('Deleted campaign')
            ->assertSee('Retired relay')
            ->assertSee('Removed since')
            ->assertSee('Sent by an automation');
    }

    public function test_a_row_whose_campaign_was_purged_still_renders(): void
    {
        // A force-deleted campaign takes the foreign key with it: campaign_id
        // becomes NULL, so the row can no longer name what it belonged to. It
        // must still render, and must say the campaign is gone rather than
        // showing a dash that reads like "no campaign was involved".
        $purged = Campaign::factory()->forAccount($this->owner->account)->create(['name' => 'Purged']);
        $log = $this->row(['campaign_id' => $purged->id, 'recipient_email' => 'purged@example.com']);
        $purged->forceDelete();

        $this->assertNull($log->fresh()->campaign_id,
            'The foreign key is declared nullOnDelete, so a purge must null it rather than delete the log.');

        $this->get('/logs')->assertOk()
            ->assertSee('purged@example.com')
            ->assertSee('Campaign no longer exists');

        $this->get("/logs/{$log->id}")->assertOk()
            ->assertSee('The campaign record no longer exists.');
    }

    public function test_an_account_with_no_rows_gets_an_explanatory_empty_state(): void
    {
        $this->get('/logs')->assertOk()
            ->assertSee('No email has been sent from this account yet')
            ->assertSee('A row lands here every time this account attempts a send');
    }

    public function test_the_sidebar_links_the_log_for_someone_who_may_read_it(): void
    {
        // The sidebar entry is already written and guarded by Route::has()
        // plus logs.view, so naming the route logs.index is what makes it
        // appear. If that ever drifts the nav silently loses the link.
        $this->get('/dashboard')->assertOk()
            ->assertSee('Email Logs')
            ->assertSee('href="'.route('logs.index').'"', false);
    }

    // ------------------------------------------------------------- filters

    public function test_every_filter_narrows_the_list_and_survives_pagination(): void
    {
        $this->row(['recipient_email' => 'alice@example.com', 'subject' => 'Newsletter one']);
        $this->row([
            'recipient_email' => 'bob@example.com', 'subject' => 'Newsletter two',
            'status' => 'failed', 'sent_at' => null, 'error' => '535 authentication failed',
        ]);
        $this->row([
            'recipient_email' => 'carol@example.com', 'subject' => 'Drip step',
            'type' => 'automation', 'campaign_id' => null,
        ]);

        // Free text hits the recipient and the subject.
        $this->get('/logs?q=alice')->assertOk()->assertSee('alice@example.com')->assertDontSee('bob@example.com');
        $this->get('/logs?q=Drip')->assertOk()->assertSee('carol@example.com')->assertDontSee('alice@example.com');

        // Status and type.
        $this->get('/logs?status=failed')->assertOk()->assertSee('bob@example.com')->assertDontSee('alice@example.com');
        $this->get('/logs?type=automation')->assertOk()->assertSee('carol@example.com')->assertDontSee('bob@example.com');

        // Campaign.
        $this->get('/logs?campaign='.$this->campaign->id)->assertOk()
            ->assertSee('alice@example.com')->assertDontSee('carol@example.com');

        // A combination, and the pager keeps it.
        $response = $this->get('/logs?q=Newsletter&status=failed&campaign='.$this->campaign->id)->assertOk();
        $response->assertSee('bob@example.com')->assertDontSee('alice@example.com');

        // Every filter is carried into the export link.
        $response->assertSee('q=Newsletter', false);
        $response->assertSee('status=failed', false);
    }

    public function test_the_date_range_is_read_in_the_account_time_zone(): void
    {
        // The account runs in Asia/Karachi, which is UTC+5, and the column
        // holds UTC. Both of these rows fall on a different calendar day in
        // UTC than they do for the person reading the screen, so a filter
        // read in UTC rather than in the account's zone would return exactly
        // the wrong one of them each time.
        //
        //   early: 09 Mar 21:00 UTC  →  10 Mar 02:00 in Karachi
        //   late:  10 Mar 19:30 UTC  →  11 Mar 00:30 in Karachi
        $early = $this->row(['recipient_email' => 'early@example.com']);
        DB::table('campaign_logs')->where('id', $early->id)
            ->update(['created_at' => '2026-03-09 21:00:00', 'sent_at' => '2026-03-09 21:00:00']);

        $late = $this->row(['recipient_email' => 'late@example.com']);
        DB::table('campaign_logs')->where('id', $late->id)
            ->update(['created_at' => '2026-03-10 19:30:00', 'sent_at' => '2026-03-10 19:30:00']);

        $this->get('/logs?from=2026-03-10&to=2026-03-10')->assertOk()
            ->assertSee('early@example.com')->assertDontSee('late@example.com');

        $this->get('/logs?from=2026-03-11&to=2026-03-11')->assertOk()
            ->assertSee('late@example.com')->assertDontSee('early@example.com');

        // Both ends are inclusive.
        $this->get('/logs?from=2026-03-10&to=2026-03-11')->assertOk()
            ->assertSee('early@example.com')->assertSee('late@example.com');

        $this->get('/logs?from=2026-03-09&to=2026-03-09')->assertOk()
            ->assertDontSee('early@example.com')->assertDontSee('late@example.com');
    }

    public function test_hostile_query_strings_do_not_break_the_screen(): void
    {
        $this->row(['recipient_email' => 'normal@example.com']);

        // Array input on every filter — the whole point of $request->filter().
        $this->get('/logs?q[]=x&type[]=campaign&status[]=sent&campaign[]=1&from[]=a&to[]=b')
            ->assertOk()->assertSee('normal@example.com');

        // Values outside the enums, a non-numeric campaign, an unparseable date.
        $this->get('/logs?status=nonsense&type=nonsense')->assertOk()->assertSee('normal@example.com');
        $this->get('/logs?campaign=not-a-number')->assertOk()->assertSee('normal@example.com');
        $this->get('/logs?from=13th+of+never&to=xxxx-yy-zz')->assertOk()->assertSee('normal@example.com');

        // Past the end of the log.
        $this->get('/logs?page=9')->assertOk()->assertSee('Nothing on this page');

        // The same on the export.
        $this->get('/logs/export?status[]=sent&from=nope')->assertOk();
    }

    // -------------------------------------------------------------- detail

    public function test_the_detail_screen_shows_the_full_error_and_response(): void
    {
        $subscriber = Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'reader@example.com', 'name' => 'Reader One']);

        $log = $this->row([
            'subscriber_id' => $subscriber->id,
            'status' => 'failed',
            'sent_at' => null,
            'error' => '535 5.7.8 Authentication credentials invalid',
            'response' => '250 OK queued as ABC123',
        ]);

        $this->get("/logs/{$log->id}")->assertOk()
            ->assertSee('535 5.7.8 Authentication credentials invalid')
            ->assertSee('250 OK queued as ABC123')
            ->assertSee('Reader One')
            ->assertSee('Spring news')
            ->assertSee('Primary relay')
            ->assertSee('The send did not complete.');
    }

    public function test_the_detail_screen_is_honest_about_what_it_cannot_show(): void
    {
        // An automation send with nothing attached: no campaign, no contact,
        // no SMTP account and no captured response.
        $log = $this->row([
            'type' => 'automation', 'campaign_id' => null, 'smtp_account_id' => null,
            'subscriber_id' => null, 'response' => null, 'status' => 'failed',
            'sent_at' => null, 'error' => null,
        ]);

        $this->get("/logs/{$log->id}")->assertOk()
            ->assertSee('but not which')
            ->assertSee('No contact record is attached')
            ->assertSee('No sending account was recorded')
            ->assertSee('Not captured.')
            ->assertSee('No reason was stored with this attempt');
    }

    // -------------------------------------------------------------- export

    public function test_the_export_streams_the_filtered_set(): void
    {
        $this->row(['recipient_email' => 'keep@example.com', 'status' => 'failed', 'sent_at' => null,
            'error' => 'connection refused']);
        $this->row(['recipient_email' => 'drop@example.com', 'status' => 'sent']);

        $response = $this->get('/logs/export?status=failed')->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('keep@example.com', $csv);
        $this->assertStringNotContainsString('drop@example.com', $csv,
            'The export must contain exactly what the filters on screen selected.');
        $this->assertStringContainsString('connection refused', $csv);
        $this->assertStringContainsString('Spring news', $csv);
        $this->assertStringContainsString('Primary relay', $csv);
    }

    public function test_the_export_neutralises_spreadsheet_formulas(): void
    {
        // A subject and a bounce message are attacker-influenced text, and a
        // leading =, +, - or @ is executable the moment the CSV is opened.
        $this->row([
            'recipient_email' => 'target@example.com',
            'subject' => '=cmd|\' /c calc\'!A1',
            'status' => 'failed',
            'sent_at' => null,
            'error' => '@SUM(1+1)*cmd',
        ]);

        $csv = $this->get('/logs/export')->assertOk()->streamedContent();

        $this->assertStringContainsString("'=cmd", $csv,
            'A formula in the subject must be quoted so the spreadsheet treats it as text.');
        $this->assertStringContainsString("'@SUM", $csv,
            'A formula in the error text must be quoted too.');
        $this->assertStringNotContainsString(',=cmd', $csv);
    }

    public function test_a_purged_campaign_is_named_as_gone_in_the_export(): void
    {
        $purged = Campaign::factory()->forAccount($this->owner->account)->create(['name' => 'Purged']);
        $this->row(['campaign_id' => $purged->id, 'recipient_email' => 'purged@example.com']);
        $purged->forceDelete();

        $this->assertStringContainsString(
            'Campaign no longer exists',
            $this->get('/logs/export')->assertOk()->streamedContent()
        );
    }

    // ------------------------------------------------------------- tenancy

    public function test_another_accounts_log_is_not_reachable(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@logscreens.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = app(TenantManager::class)->runAs($other->account_id, fn () => CampaignLog::create([
            'recipient_email' => 'theirs@example.com',
            'subject' => 'Their subject line',
            'type' => 'campaign',
            'status' => 'sent',
            'sent_at' => now(),
        ]));

        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/logs')->assertOk()->assertDontSee('theirs@example.com');
        $this->get("/logs/{$foreign->id}")->assertNotFound();
        $this->assertStringNotContainsString('theirs@example.com',
            $this->get('/logs/export')->assertOk()->streamedContent());
    }

    public function test_a_member_without_the_permission_cannot_reach_the_log(): void
    {
        $this->row();

        $staff = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/logs')->assertForbidden();
        $this->get('/logs/export')->assertForbidden();
    }

    // ---------------------------------------------------------- efficiency

    public function test_a_full_page_does_not_issue_a_query_per_row(): void
    {
        // Every row points at its own campaign and its own SMTP account, so a
        // lazy-loading list would issue two queries per row and the count
        // would climb with the page size rather than staying flat.
        for ($i = 0; $i < 30; $i++) {
            $campaign = Campaign::factory()->forAccount($this->owner->account)->create(['name' => "Campaign {$i}"]);
            $smtp = SmtpAccount::factory()->forAccount($this->owner->account)->create(['name' => "Relay {$i}"]);

            $this->row([
                'campaign_id' => $campaign->id,
                'smtp_account_id' => $smtp->id,
                'recipient_email' => "row{$i}@example.com",
            ]);
        }

        DB::enableQueryLog();
        $this->get('/logs')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(20, $count,
            "A page of 30 log rows issued {$count} queries — the campaign and SMTP account must be eager loaded.");
    }
}
