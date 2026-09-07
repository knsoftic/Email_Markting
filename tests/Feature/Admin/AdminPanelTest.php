<?php

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountProvisioner;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
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
        ]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Customer Ltd',
            'name' => 'Customer Owner',
            'email' => 'customer@example.test',
            'password' => 'Password123!',
            'timezone' => 'UTC',
        ]);

        $this->owner->forceFill(['email_verified_at' => now()])->save();
    }

    // ------------------------------------------------------------- access

    public function test_account_users_cannot_reach_the_admin_panel(): void
    {
        $this->actingAs($this->owner)->get('/admin')->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/users')->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/plans')->assertForbidden();
    }

    public function test_guests_are_redirected_from_the_admin_panel(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_super_admin_can_open_every_admin_screen(): void
    {
        $account = $this->owner->account;

        $screens = [
            '/admin',
            '/admin/plans',
            '/admin/plans/create',
            '/admin/users',
            '/admin/users/create',
            "/admin/users/{$this->owner->id}",
            "/admin/users/{$this->owner->id}/edit",
            '/admin/accounts',
            "/admin/accounts/{$account->id}",
            '/admin/subscriptions',
            '/admin/roles',
            '/admin/roles/create',
            '/admin/settings/branding',
            '/admin/settings/system',
            '/admin/settings/payment',
            '/admin/system/health',
            '/admin/system/queue',
            '/admin/activity',
        ];

        foreach ($screens as $screen) {
            $this->actingAs($this->admin)->get($screen)->assertOk("Failed opening {$screen}");
        }
    }

    // -------------------------------------------------------------- plans

    public function test_super_admin_can_create_a_plan_with_limits_and_features(): void
    {
        $this->actingAs($this->admin)->post('/admin/plans', [
            'name' => 'Agency',
            'slug' => 'agency',
            'price' => 199,
            'currency' => 'usd',
            'billing_period' => 'monthly',
            'trial_days' => 7,
            'sort_order' => 4,
            'is_active' => '1',
            'max_contacts' => 500000,
            'max_emails_per_month' => '',   // blank => unlimited
            'max_smtp_accounts' => 0,       // zero => not allowed
            'allow_custom_smtp' => '1',
            'allow_ab_testing' => '1',
        ])->assertRedirect('/admin/plans');

        $plan = Plan::firstWhere('slug', 'agency');

        $this->assertNotNull($plan);
        $this->assertSame('USD', $plan->currency);
        $this->assertSame(500000, $plan->max_contacts);
        $this->assertNull($plan->max_emails_per_month, 'A blank limit must store as unlimited.');
        $this->assertSame(0, $plan->max_smtp_accounts);
        $this->assertTrue($plan->allow_custom_smtp);
        $this->assertTrue($plan->allow_ab_testing);
        $this->assertFalse($plan->allow_automation, 'Unchecked features must store as false.');
    }

    public function test_only_one_plan_can_be_the_default(): void
    {
        $this->actingAs($this->admin)->post('/admin/plans', [
            'name' => 'New Default', 'slug' => 'new-default', 'price' => 0,
            'currency' => 'USD', 'billing_period' => 'monthly', 'trial_days' => 0,
            'sort_order' => 9, 'is_active' => '1', 'is_default' => '1',
        ]);

        $this->assertSame(1, Plan::where('is_default', true)->count());
        $this->assertSame('new-default', Plan::where('is_default', true)->value('slug'));
    }

    public function test_a_plan_with_active_subscriptions_is_deactivated_not_deleted(): void
    {
        $plan = $this->owner->account->subscription->plan;

        $this->actingAs($this->admin)
            ->delete("/admin/plans/{$plan->id}")
            ->assertRedirect('/admin/plans');

        $this->assertNotNull(Plan::find($plan->id), 'Plan in use must survive.');
        $this->assertFalse(Plan::find($plan->id)->is_active);
    }

    // -------------------------------------------------------------- users

    public function test_creating_an_account_user_provisions_account_and_subscription(): void
    {
        $this->actingAs($this->admin)->post('/admin/users', [
            'name' => 'New Owner',
            'email' => 'new-owner@example.test',
            'company_name' => 'Fresh Co',
            'timezone' => 'UTC',
            'status' => 'active',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'email_verified' => '1',
        ])->assertRedirect();

        $user = User::withoutGlobalScopes()->firstWhere('email', 'new-owner@example.test');

        $this->assertNotNull($user);
        $this->assertNotNull($user->account_id);
        $this->assertSame($user->id, $user->account->owner_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(
            Subscription::withoutGlobalScopes()->where('account_id', $user->account_id)->exists()
        );
    }

    public function test_suspending_a_user_locks_them_out(): void
    {
        $this->actingAs($this->admin)
            ->patch("/admin/users/{$this->owner->id}/status")
            ->assertRedirect();

        $this->assertSame('suspended', $this->owner->fresh()->status);

        $this->actingAs($this->owner->fresh())
            ->get('/dashboard')
            ->assertRedirect(route('login'));
    }

    public function test_admin_cannot_suspend_themselves(): void
    {
        $this->actingAs($this->admin)
            ->patch("/admin/users/{$this->admin->id}/status")
            ->assertSessionHas('error');

        $this->assertSame('active', $this->admin->fresh()->status);
    }

    // ----------------------------------------------------------- accounts

    public function test_suspending_an_account_locks_out_its_users(): void
    {
        $account = $this->owner->account;

        $this->actingAs($this->admin)
            ->patch("/admin/accounts/{$account->id}/status")
            ->assertRedirect();

        $this->assertSame('suspended', $account->fresh()->status);

        // fresh(): the in-memory model still carries the pre-suspension
        // account relation; a real request always loads the user anew.
        $this->actingAs($this->owner->fresh())
            ->get('/dashboard')
            ->assertRedirect(route('login'));
    }

    public function test_limit_overrides_take_precedence_over_the_plan(): void
    {
        $subscription = $this->owner->account->subscription;

        $this->actingAs($this->admin)->put("/admin/subscriptions/{$subscription->id}/overrides", [
            'overrides' => [
                'max_contacts' => 999999,
                'allow_ab_testing' => '1',
                'max_mailboxes' => '',   // blank => fall back to plan
            ],
        ])->assertRedirect();

        $fresh = $subscription->fresh();

        $this->assertSame(999999, $fresh->limit('max_contacts'));
        $this->assertTrue($fresh->limit('allow_ab_testing'));
        $this->assertSame(
            $fresh->plan->max_mailboxes,
            $fresh->limit('max_mailboxes'),
            'A blank override must fall through to the plan value.'
        );
    }

    // ------------------------------------------------------- impersonation

    public function test_admin_can_impersonate_and_return(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/users/{$this->owner->id}/impersonate")
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('impersonator_id', $this->admin->id);

        $this->assertSame($this->owner->id, auth()->id());

        $this->post('/stop-impersonating')->assertRedirect(route('admin.users.index'));

        $this->assertSame($this->admin->id, auth()->id());
        $this->assertFalse(session()->has('impersonator_id'));
    }

    public function test_super_admins_cannot_be_impersonated(): void
    {
        $other = User::factory()->superAdmin()->create();

        $this->actingAs($this->admin)
            ->post("/admin/users/{$other->id}/impersonate")
            ->assertSessionHas('error');

        $this->assertSame($this->admin->id, auth()->id());
    }

    // ----------------------------------------------------------- settings

    public function test_branding_settings_drive_the_interface(): void
    {
        $this->actingAs($this->admin)->put('/admin/settings/branding', [
            'company_name' => 'Acme Mailer',
            'tagline' => 'Send better email',
            'primary_color' => '#ff0000',
            'accent_color' => '#00ff00',
            'footer_text' => 'Acme footer',
        ])->assertRedirect();

        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Acme Mailer')
            ->assertSee('Acme footer');
    }

    public function test_registration_can_be_closed_from_system_settings(): void
    {
        $this->actingAs($this->admin)->put('/admin/settings/system', [
            'allow_registration' => '0',
            'require_email_verification' => '1',
            'default_plan_slug' => 'starter',
            'trial_days' => 14,
        ])->assertRedirect();

        auth()->logout();

        $this->get('/register')->assertForbidden();
    }

    public function test_email_verification_can_be_switched_off(): void
    {
        $unverified = app(AccountProvisioner::class)->provision([
            'company_name' => 'Unverified Ltd',
            'name' => 'Unverified',
            'email' => 'unverified@example.test',
            'password' => 'Password123!',
            'timezone' => 'UTC',
        ]);

        $this->actingAs($unverified)->get('/dashboard')->assertRedirect(route('verification.notice'));

        $this->actingAs($this->admin)->put('/admin/settings/system', [
            'allow_registration' => '1',
            'require_email_verification' => '0',
            'default_plan_slug' => 'starter',
            'trial_days' => 14,
        ]);

        $this->actingAs($unverified)->get('/dashboard')->assertOk();
    }

    // -------------------------------------------------------------- roles

    public function test_built_in_roles_cannot_be_edited_or_deleted(): void
    {
        $owner = Role::withoutGlobalScopes()->where('slug', Role::OWNER)->first();

        $this->actingAs($this->admin)
            ->put("/admin/roles/{$owner->id}", ['name' => 'Hacked', 'slug' => 'hacked'])
            ->assertSessionHas('error');

        $this->assertSame('Account Owner', $owner->fresh()->name);

        $this->actingAs($this->admin)
            ->delete("/admin/roles/{$owner->id}")
            ->assertSessionHas('error');

        $this->assertNotNull(Role::withoutGlobalScopes()->find($owner->id));
    }
}
