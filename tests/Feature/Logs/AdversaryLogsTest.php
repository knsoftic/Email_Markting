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

/** THROWAWAY adversarial probe. Delete before finishing. */
class AdversaryLogsTest extends TestCase
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
            'company_name' => 'Adv Ltd', 'name' => 'Owner', 'email' => 'owner@adv.test',
            'password' => 'Password123!', 'timezone' => 'Asia/Karachi',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $sub = $this->owner->account->subscription;
        $sub->update(['overrides' => array_merge($sub->overrides ?? [], [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 20, 'max_emails_per_month' => 100000,
        ])]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->smtp = SmtpAccount::factory()->forAccount($this->owner->account)->create(['name' => 'Primary relay']);
        $this->campaign = Campaign::factory()->forAccount($this->owner->account)->create([
            'name' => 'Spring news', 'subject' => 'Spring news',
        ]);
    }

    protected function row(array $o = []): CampaignLog
    {
        return CampaignLog::create(array_merge([
            'campaign_id' => $this->campaign->id,
            'smtp_account_id' => $this->smtp->id,
            'recipient_email' => 'reader@example.com',
            'sender_email' => 'hello@adv.test',
            'subject' => 'Spring news',
            'type' => 'campaign',
            'status' => 'sent',
            'sent_at' => now(),
        ], $o));
    }

    // ------------------------------------------------------------ escaping

    public function test_hostile_content_never_escapes_its_attribute(): void
    {
        $payload = '"><script>alert(1)</script>';
        $attr = '" onmouseover="alert(2)';

        $c = Campaign::factory()->forAccount($this->owner->account)->create(['name' => $payload.'CAMP']);
        $s = SmtpAccount::factory()->forAccount($this->owner->account)->create(['name' => $attr.'SMTP']);
        $sub = Subscriber::factory()->forAccount($this->owner->account)->create([
            'email' => 'x@example.com', 'name' => $payload.'SUBNAME',
        ]);

        $log = $this->row([
            'campaign_id' => $c->id,
            'smtp_account_id' => $s->id,
            'subscriber_id' => $sub->id,
            'recipient_email' => 'evil@example.com',
            'sender_email' => $attr.'@example.com',
            'subject' => $payload.'SUBJ',
            'status' => 'failed',
            'sent_at' => null,
            'error' => $payload.'ERR',
            'response' => $attr.'RESP',
        ]);

        foreach (['/logs', '/logs?q='.urlencode($payload), "/logs/{$log->id}"] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, "raw script in {$url}");
            $this->assertStringNotContainsString('onmouseover="alert(2)', $html, "attribute break in {$url}");
        }
    }

    public function test_the_search_term_is_reflected_safely(): void
    {
        $this->row();
        $html = $this->get('/logs?q='.urlencode('"><script>alert(9)</script>'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(9)</script>', $html);
    }

    // ----------------------------------------------------- reachable paths

    public function test_hostile_pagination_and_ids(): void
    {
        $this->row();

        foreach (['?page=abc', '?page=-5', '?page=0', '?page[]=1', '?page=99999999999999999999',
            '?campaign=99999999999999999999', '?q='.urlencode('%'), '?q='.urlencode('_'),
            '?from=2026-02-30&to=2026-13-45', '?from=0000-00-00',
            '?status=SENT', '?type=CAMPAIGN', '?campaign=-1', '?campaign=0',
        ] as $qs) {
            $r = $this->get('/logs'.$qs);
            $this->assertTrue(in_array($r->status(), [200], true), "GET /logs{$qs} => {$r->status()}");
            $e = $this->get('/logs/export'.$qs);
            $this->assertSame(200, $e->getStatusCode(), "GET /logs/export{$qs} => ".$e->getStatusCode());
            $e->streamedContent();
        }

        $this->get('/logs/abc')->assertNotFound();
        $this->get('/logs/0')->assertNotFound();
        $this->get('/logs/999999')->assertNotFound();
    }

    public function test_a_broken_account_timezone_does_not_break_the_screen(): void
    {
        $log = $this->row();
        DB::table('accounts')->where('id', $this->owner->account_id)->update(['timezone' => 'Mars/Phobos']);

        $this->get('/logs')->assertOk();
        $this->get("/logs/{$log->id}")->assertOk();
        $this->get('/logs/export')->assertOk()->streamedContent();
    }

    public function test_show_with_no_timestamps_at_all(): void
    {
        $log = $this->row(['sent_at' => null, 'status' => 'failed']);
        DB::table('campaign_logs')->where('id', $log->id)->update(['created_at' => null, 'updated_at' => null]);

        $this->get("/logs/{$log->id}")->assertOk()->assertSee('No time recorded');
    }

    public function test_a_crafted_referer_cannot_send_back_offsite(): void
    {
        $log = $this->row();

        $html = $this->get("/logs/{$log->id}", ['referer' => 'https://evil.test/logs?x=1'])
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('href="https://evil.test', $html);
        $this->assertStringNotContainsString('evil.test', $html);
    }

    // --------------------------------------------------- permission shapes

    public function test_a_reader_without_campaign_permission_gets_no_campaign_link(): void
    {
        $this->row();

        $role = Role::create([
            'account_id' => $this->owner->account_id,
            'name' => 'Log reader', 'slug' => 'log-reader',
        ]);
        $role->permissions()->sync(
            DB::table('permissions')->whereIn('slug', ['logs.view'])->pluck('id')->all()
        );

        $reader = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => $role->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($reader);
        app(TenantManager::class)->set($this->owner->account_id);

        $index = $this->get('/logs')->assertOk();
        $index->assertDontSee('/campaigns/'.$this->campaign->id, false);

        $log = CampaignLog::first();
        $show = $this->get("/logs/{$log->id}")->assertOk();
        $show->assertDontSee('/campaigns/'.$this->campaign->id, false);
        $show->assertSee('You do not have permission to open campaigns.');
    }

    // ------------------------------------------------------------- numbers

    public function test_the_export_contains_exactly_the_number_the_button_promises(): void
    {
        foreach (range(1, 7) as $i) {
            $this->row(['recipient_email' => "a{$i}@example.com", 'status' => 'sent']);
        }
        foreach (range(1, 4) as $i) {
            $this->row(['recipient_email' => "f{$i}@example.com", 'status' => 'failed', 'sent_at' => null]);
        }

        // No filters.
        $html = $this->get('/logs')->assertOk()->getContent();
        $this->assertStringContainsString('Export 11 rows (CSV)', $html);
        $this->assertSame(11, $this->csvRows($this->get('/logs/export')->streamedContent()));

        // Status filter.
        $html = $this->get('/logs?status=failed')->assertOk()->getContent();
        $this->assertStringContainsString('Export these 4 rows (CSV)', $html);
        $this->assertSame(4, $this->csvRows($this->get('/logs/export?status=failed')->streamedContent()));

        // Chip counts must equal the database.
        $this->assertStringContainsString('>7<', preg_replace('/\s+/', '', $html));
    }

    public function test_the_status_chip_counts_match_the_database(): void
    {
        foreach (['sent' => 5, 'failed' => 3, 'bounced' => 2, 'deferred' => 1] as $status => $n) {
            foreach (range(1, $n) as $i) {
                $this->row(['status' => $status, 'recipient_email' => "{$status}{$i}@example.com",
                    'sent_at' => $status === 'sent' ? now() : null]);
            }
        }

        $html = preg_replace('/\s+/', ' ', $this->get('/logs')->assertOk()->getContent());

        // Isolate the chip strip so the sidebar/header cannot satisfy a match.
        preg_match('/<div class="mb-4 flex flex-wrap items-center gap-2">(.*?)<\/div> <form/', $html, $m);
        $this->assertNotEmpty($m, 'status chip strip not found');
        $chips = $m[1];

        foreach (['Sent' => 5, 'Failed' => 3, 'Bounced' => 2, 'Deferred' => 1, 'All attempts' => 11] as $label => $n) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($label, '/').' <span[^>]*>\s*'.$n.'\s*<\/span>/',
                $chips,
                "chip {$label} should read {$n}; chips were: {$chips}"
            );
        }

        $this->assertSame(11, CampaignLog::count());
    }

    public function test_a_filtered_page_past_the_end_offers_a_working_first_page_link(): void
    {
        $this->row(['recipient_email' => 'only@example.com']);

        $html = $this->get('/logs?q=only&page=9')->assertOk()->getContent();
        $this->assertStringContainsString('Nothing on this page', $html);

        // The link it offers must actually land on a page with the row.
        preg_match('/First page[\s\S]{0,40}/', $html, $m);
        preg_match('/<a href="([^"]+)"[^>]*>\s*First page/', $html, $link);
        $this->assertNotEmpty($link, 'no First page link found');

        $target = html_entity_decode($link[1]);
        $this->get(str_replace(config('app.url'), '', $target))->assertOk()->assertSee('only@example.com');
    }

    // ------------------------------------------------------------- tenancy

    public function test_filtering_on_another_accounts_campaign_leaks_nothing(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival', 'name' => 'R', 'email' => 'r@adv.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        [$foreignCampaign, $foreignLog] = app(TenantManager::class)->runAs($other->account_id, function () {
            $c = Campaign::factory()->forAccount(\App\Models\Account::withoutGlobalScopes()->find(
                \App\Models\User::withoutGlobalScopes()->where('email', 'r@adv.test')->value('account_id')
            ))->create(['name' => 'RIVAL CAMPAIGN']);

            $l = CampaignLog::create([
                'campaign_id' => $c->id,
                'recipient_email' => 'rival@example.com',
                'subject' => 'RIVAL SUBJECT',
                'type' => 'campaign', 'status' => 'sent', 'sent_at' => now(),
            ]);

            return [$c, $l];
        });

        app(TenantManager::class)->set($this->owner->account_id);
        $this->actingAs($this->owner);
        $this->row();

        $html = $this->get('/logs?campaign='.$foreignCampaign->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('RIVAL', $html);

        $this->get('/logs')->assertOk()->assertDontSee('RIVAL');
        $this->get("/logs/{$foreignLog->id}")->assertNotFound();

        $csv = $this->get('/logs/export?campaign='.$foreignCampaign->id)->assertOk()->streamedContent();
        $this->assertStringNotContainsString('RIVAL', $csv);
    }

    // -------------------------------------------------------------- export

    public function test_export_filename_and_bom(): void
    {
        $this->row();
        $r = $this->get('/logs/export')->assertOk();
        $csv = $r->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('attachment;', (string) $r->headers->get('content-disposition'));
    }

    public function test_export_neutralises_a_leading_tab_formula(): void
    {
        $this->row(['subject' => "\t=1+1", 'recipient_email' => 'tab@example.com']);
        $csv = $this->get('/logs/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString("\t=1+1", $csv, 'leading tab lets the formula through');
    }

    // --------------------------------------------------------------- utils

    protected function csvRows(string $csv): int
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $n = -1; // header
        while (fgetcsv($fh) !== false) {
            $n++;
        }
        fclose($fh);

        return $n;
    }
}
