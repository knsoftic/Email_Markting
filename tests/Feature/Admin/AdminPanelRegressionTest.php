<?php

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\Campaign;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscriber;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\PlanLimits;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Defects found while mapping the admin panel to write its guide.
 *
 * They share a shape worth naming: none of them shows an error. Each one
 * quietly does something other than what the operator asked for — an override
 * dropped, a limit removed, a count that disagrees with the count next to it.
 * That is exactly the kind of behaviour a guide would have had to document as
 * if it were intended, which is why they were fixed first.
 */
class AdminPanelRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->admin = User::factory()->superAdmin()->create([
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::SUPER_ADMIN)->value('id'),
            'email_verified_at' => now(),
        ]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Customer Ltd', 'name' => 'Customer Owner',
            'email' => 'customer@example.test', 'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->admin);
    }

    protected function account(): Account
    {
        return $this->owner->account->fresh();
    }

    // ------------------------------------------------------ limit overrides

    /**
     * Assigning a plan wrote a NEW subscription row, and the per-account
     * overrides live on that row. They were dropped without a word: an operator
     * who had granted a customer extra contacts would move them to a bigger
     * plan and take the grant away in the same click.
     */
    public function test_changing_the_plan_keeps_the_per_account_overrides(): void
    {
        $account = $this->account();

        $account->subscription->forceFill([
            'overrides' => ['max_contacts' => 50000, 'allow_custom_smtp' => true],
        ])->save();

        $target = Plan::withoutGlobalScopes()->where('slug', 'pro')->firstOrFail();

        $this->post("/admin/accounts/{$account->id}/subscription", [
            'plan_id' => $target->id,
            'status' => 'active',
        ])->assertRedirect();

        $fresh = $account->fresh();

        $this->assertSame($target->id, $fresh->subscription->plan_id, 'The plan must have changed.');
        $this->assertSame(50000, $fresh->subscription->overrides['max_contacts'] ?? null,
            'The contact override must survive a plan change.');
        $this->assertSame(50000, PlanLimits::for($fresh)->limit('max_contacts'));
    }

    public function test_the_operator_is_told_the_overrides_were_kept(): void
    {
        $account = $this->account();
        $account->subscription->forceFill(['overrides' => ['max_contacts' => 50000]])->save();

        $target = Plan::withoutGlobalScopes()->where('slug', 'pro')->firstOrFail();

        $this->post("/admin/accounts/{$account->id}/subscription", [
            'plan_id' => $target->id, 'status' => 'active',
        ])->assertSessionHas('success', fn (string $m) => str_contains($m, 'override'));
    }

    // ------------------------------------------------------------ deleted plan

    /**
     * The worst of the set. Plans soft-delete, `plan` resolved to null for the
     * accounts still on one, and a null limit means UNLIMITED — so removing a
     * plan from the list handed its customers unlimited contacts and unlimited
     * sending, while switching every feature flag off at the same time.
     */
    public function test_a_deleted_plan_still_governs_the_accounts_on_it(): void
    {
        $account = $this->account();
        $plan = $account->subscription->plan;

        $before = PlanLimits::for($account)->limit('max_contacts');
        $this->assertIsInt($before, 'This test needs a plan with a real contact limit.');

        $plan->delete();

        $after = PlanLimits::for($account->fresh())->limit('max_contacts');

        $this->assertSame($before, $after,
            'Deleting a plan must not silently give its accounts unlimited everything.');
        $this->assertFalse(PlanLimits::for($account->fresh())->isUnlimited('max_contacts'));
    }

    // --------------------------------------------------------- counts agreeing

    /**
     * The account detail cards counted soft-deleted rows; the accounts list did
     * not. The same customer showed two different contact counts on two screens
     * of the same panel, and either could be read as "near their limit".
     */
    public function test_the_account_cards_do_not_count_deleted_rows(): void
    {
        $account = $this->account();

        $keep = Subscriber::factory()->forAccount($account)->count(3)->create();
        $gone = Subscriber::factory()->forAccount($account)->create();
        $gone->delete();

        Campaign::factory()->forAccount($account)->create(['name' => 'Live', 'subject' => 'Hi']);
        $deletedCampaign = Campaign::factory()->forAccount($account)->create(['name' => 'Gone', 'subject' => 'Hi']);
        $deletedCampaign->delete();

        $view = $this->get("/admin/accounts/{$account->id}")->assertOk()->viewData('counts');

        $this->assertSame($keep->count(), $view['subscribers'],
            'A contact the customer deleted must not be counted against them here.');
        $this->assertSame(1, $view['campaigns']);
        $this->assertSame(
            PlanLimits::for($account)->usageFor('max_contacts'),
            $view['subscribers'],
            'The card must agree with the figure the plan limit is actually measured against.'
        );
    }

    // -------------------------------------------------------------- lockout

    /**
     * Suspend and Delete both refuse to act on yourself. The edit form's status
     * dropdown did not — and suspending yourself signs you out on the next
     * request, with nobody able to sign back in and undo it.
     */
    public function test_an_admin_cannot_suspend_their_own_account(): void
    {
        $this->put("/admin/users/{$this->admin->id}", [
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'timezone' => 'UTC',
            'status' => 'suspended',
            'email_verified' => '1',
        ])->assertSessionHas('error');

        $this->assertSame('active', $this->admin->fresh()->status,
            'The one mistake in this panel that cannot be undone from inside the product.');
    }

    public function test_an_admin_may_still_suspend_somebody_else(): void
    {
        $this->put("/admin/users/{$this->owner->id}", [
            'name' => $this->owner->name,
            'email' => $this->owner->email,
            'timezone' => 'UTC',
            'status' => 'suspended',
            'email_verified' => '1',
        ])->assertRedirect();

        $this->assertSame('suspended', $this->owner->fresh()->status);
    }

    // ---------------------------------------------------------------- roles

    /**
     * `role_id` was validated with a bare `exists:roles,id`, so an id typed into
     * the request could attach one customer's private role to another
     * customer's user.
     */
    public function test_a_role_belonging_to_another_account_cannot_be_assigned(): void
    {
        $rival = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival Owner',
            'email' => 'rival@example.test', 'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $theirRole = Role::create([
            'account_id' => $rival->account_id,
            'name' => 'Their private role',
            'slug' => 'their-private-role',
            'is_system' => false,
        ]);

        $before = $this->owner->role_id;

        $this->put("/admin/users/{$this->owner->id}", [
            'name' => $this->owner->name,
            'email' => $this->owner->email,
            'timezone' => 'UTC',
            'status' => 'active',
            'role_id' => $theirRole->id,
            'email_verified' => '1',
        ])->assertSessionHasErrors('role_id');

        $this->assertSame($before, $this->owner->fresh()->role_id);
    }

    public function test_a_system_role_can_still_be_assigned(): void
    {
        $staff = Role::withoutGlobalScopes()->where('slug', Role::STAFF)->firstOrFail();

        $this->put("/admin/users/{$this->owner->id}", [
            'name' => $this->owner->name,
            'email' => $this->owner->email,
            'timezone' => 'UTC',
            'status' => 'active',
            'role_id' => $staff->id,
            'email_verified' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame($staff->id, $this->owner->fresh()->role_id);
    }

    // ------------------------------------------------------- hostile input

    /**
     * `$request->date()` throws on anything it cannot parse, so a stale
     * bookmark answered with a 500.
     */
    public function test_the_activity_screen_survives_a_malformed_date(): void
    {
        foreach ([
            '/admin/activity?from=hello',
            '/admin/activity?to=notadate',
            '/admin/activity?from=2026-13-45',
            '/admin/activity?from[]=2026-01-01',
            '/admin/activity?q[]=x',
            '/admin/activity?account_id=abc',
            '/admin/activity?page=999',
        ] as $url) {
            $this->get($url)->assertOk("Crashed on {$url}");
        }
    }

    // ------------------------------------------------------------- branding

    /**
     * The help text offered SVG and the `image` rule refused it, so an operator
     * following the screen's own advice got a rejection naming a format the
     * screen had just recommended. SVG stays refused — it can carry script and
     * is served from our origin to every signed-in user — and the screen now
     * says so.
     */
    public function test_an_svg_logo_is_refused_and_the_screen_does_not_offer_one(): void
    {
        Storage::fake('public');

        $this->put('/admin/settings/branding', $this->branding([
            'logo' => UploadedFile::fake()->create('logo.svg', 8, 'image/svg+xml'),
        ]))->assertSessionHasErrors('logo');

        $this->get('/admin/settings/branding')
            ->assertOk()
            ->assertSee('PNG, JPG or WebP')
            ->assertDontSee('PNG, JPG, SVG or WebP');
    }

    public function test_a_png_logo_is_accepted(): void
    {
        Storage::fake('public');

        $this->put('/admin/settings/branding', $this->branding([
            'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
        ]))->assertSessionHasNoErrors();
    }

    /**
     * The branding form's required fields, so a test about the logo is about
     * the logo and not about the three unrelated fields beside it.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function branding(array $extra = []): array
    {
        return array_merge([
            'company_name' => 'KN Softic',
            'primary_color' => '#1d4ed8',
            'accent_color' => '#7c3aed',
        ], $extra);
    }

    // ------------------------------------------------------- suspension bites

    /**
     * The most consequential of the set, because suspension is the operator's
     * main lever and it was not pulling anything.
     *
     * `EnsureAccountIsActive` is HTTP middleware: it logged the account's users
     * out of the browser and stopped there. The queue knew nothing about it, so
     * a campaign already in flight kept sending, the scheduler kept starting
     * new ones, automations kept mailing and mailboxes kept syncing. Suspending
     * an account for non-payment or for abuse did not stop its mail.
     */
    public function test_a_suspended_account_cannot_send(): void
    {
        $account = $this->account();

        \App\Models\SmtpAccount::factory()->forAccount($account)->create([
            'name' => 'Relay', 'from_email' => 'relay@customer.test', 'from_name' => 'Relay',
        ]);

        $message = (new \Symfony\Component\Mime\Email)
            ->from('relay@customer.test')->to('reader@example.com')
            ->subject('Hello')->text('Hello');

        $sender = app(\App\Services\Smtp\SmtpSender::class);

        $account->forceFill(['status' => 'suspended'])->save();

        $outcome = $sender->send($account->fresh(), $message);

        $this->assertFalse($outcome->sent, 'A suspended account must not send.');
        $this->assertTrue($outcome->deferred,
            'It must PAUSE, not fail — failing would burn every recipient and lose the campaign.');
        $this->assertStringContainsString('suspended', (string) $outcome->reason);
    }

    /**
     * A campaign scheduled by a customer who is later suspended stays
     * scheduled: it goes out if they are reactivated, rather than being lost.
     */
    public function test_the_scheduler_skips_a_suspended_accounts_campaign(): void
    {
        $account = $this->account();

        $campaign = Campaign::factory()->forAccount($account)->create([
            'name' => 'Due now', 'subject' => 'Hi',
            'status' => 'scheduled', 'scheduled_at' => now()->subMinute(), 'timezone' => 'UTC',
        ]);

        $account->forceFill(['status' => 'suspended'])->save();

        $this->artisan('campaigns:dispatch-scheduled')->assertExitCode(0);

        $this->assertSame('scheduled', $campaign->fresh()->status,
            'It must stay scheduled — not claimed, and not lost.');

        $account->forceFill(['status' => 'active'])->save();

        $this->artisan('campaigns:dispatch-scheduled')->assertExitCode(0);

        // Not asserted as 'queued': this campaign has no audience and no content,
        // so the dispatcher rightly refuses it on its own merits. What matters
        // here is that the scheduler CONSIDERED it once the account was active —
        // while suspended it was not looked at at all.
        $this->assertNotSame('scheduled', $campaign->fresh()->status,
            'Once the account is active again the scheduler must pick the campaign up.');
    }
}
