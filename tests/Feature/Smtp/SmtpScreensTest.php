<?php

namespace Tests\Feature\Smtp;

use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SmtpAccount;
use App\Models\SmtpAssignment;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SmtpScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@smtp.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $this->admin = User::factory()->superAdmin()->create();

        // Starter genuinely ships max_smtp_accounts = 0, so the feature flag
        // alone is not enough — the seat count has to be granted too.
        $this->allow([
            'allow_custom_smtp' => true,
            'allow_smtp_rotation' => true,
            'max_smtp_accounts' => 5,
        ]);
        $this->asOwner();
    }

    protected function allow(array $overrides): void
    {
        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], $overrides)]);
        $this->owner->account->refresh();
    }

    protected function asOwner(): void
    {
        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);
    }

    protected function asAdmin(): void
    {
        $this->actingAs($this->admin);
        app(TenantManager::class)->forget();
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Primary relay',
            'provider' => 'custom',
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@example.test',
            'password' => 'super-secret-value',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@example.test',
            'is_active' => '1',
            'verify_peer' => '1',
        ], $overrides);
    }

    // ------------------------------------------------------------ creating

    public function test_an_owner_can_add_an_smtp_account(): void
    {
        $this->post('/smtp', $this->payload())->assertRedirect();

        $account = SmtpAccount::withoutGlobalScopes()->firstWhere('name', 'Primary relay');

        $this->assertNotNull($account);
        $this->assertSame($this->owner->account_id, $account->account_id);
        $this->assertFalse((bool) $account->is_global);
        $this->assertSame('super-secret-value', $account->password, 'The password must decrypt back.');
    }

    public function test_the_password_is_encrypted_at_rest(): void
    {
        $this->post('/smtp', $this->payload())->assertRedirect();

        $stored = DB::table('smtp_accounts')->where('name', 'Primary relay')->value('password');

        $this->assertNotSame('super-secret-value', $stored);
        $this->assertStringNotContainsString('super-secret-value', (string) $stored);
    }

    public function test_the_password_never_appears_in_a_response(): void
    {
        $account = SmtpAccount::factory()->forAccount($this->owner->account)
            ->create(['password' => 'do-not-leak-me']);

        foreach (["/smtp", "/smtp/{$account->id}", "/smtp/{$account->id}/edit"] as $url) {
            $this->get($url)->assertOk()->assertDontSee('do-not-leak-me');
        }
    }

    public function test_a_url_style_host_is_normalised(): void
    {
        $this->post('/smtp', $this->payload([
            'host' => 'smtps://smtp.example.test:465/', 'port' => null,
        ]))->assertRedirect();

        $account = SmtpAccount::withoutGlobalScopes()->firstWhere('name', 'Primary relay');

        $this->assertSame('smtp.example.test', $account->host);
        $this->assertSame(465, (int) $account->port);
    }

    public function test_a_blank_limit_means_unlimited_not_zero(): void
    {
        $this->post('/smtp', $this->payload(['daily_limit' => '', 'hourly_limit' => '']))->assertRedirect();

        $account = SmtpAccount::withoutGlobalScopes()->firstWhere('name', 'Primary relay');

        $this->assertNull($account->daily_limit);
        $this->assertNull($account->hourly_limit);
    }

    // ------------------------------------------------------------ updating

    public function test_leaving_the_password_blank_on_edit_keeps_the_stored_one(): void
    {
        $account = SmtpAccount::factory()->forAccount($this->owner->account)
            ->create(['password' => 'original-secret']);

        $this->put("/smtp/{$account->id}", $this->payload([
            'name' => 'Renamed', 'password' => '',
        ]))->assertRedirect();

        $account->refresh();

        $this->assertSame('Renamed', $account->name);
        $this->assertSame('original-secret', $account->password,
            'A blank password field must not wipe the credential.');
    }

    public function test_changing_the_connection_invalidates_the_previous_test_result(): void
    {
        $account = SmtpAccount::factory()->forAccount($this->owner->account)->create([
            'host' => 'old.example.test', 'test_passed' => true, 'last_tested_at' => now(),
        ]);

        $this->put("/smtp/{$account->id}", $this->payload([
            'host' => 'new.example.test', 'password' => '',
        ]))->assertRedirect();

        $account->refresh();

        $this->assertFalse((bool) $account->test_passed, 'A stale pass badge would be a lie.');
        $this->assertNull($account->last_tested_at);
    }

    public function test_a_cosmetic_edit_keeps_the_test_result(): void
    {
        $account = SmtpAccount::factory()->forAccount($this->owner->account)->create([
            'host' => 'keep.example.test', 'port' => 587, 'encryption' => 'tls',
            'username' => 'mailer@example.test', 'test_passed' => true, 'last_tested_at' => now(),
        ]);

        $this->put("/smtp/{$account->id}", $this->payload([
            'name' => 'Just a new label',
            'host' => 'keep.example.test', 'port' => 587, 'encryption' => 'tls',
            'username' => 'mailer@example.test', 'password' => '',
        ]))->assertRedirect();

        $this->assertTrue((bool) $account->fresh()->test_passed);
    }

    // --------------------------------------------------------- plan limits

    public function test_a_plan_without_custom_smtp_cannot_add_one(): void
    {
        $this->allow(['allow_custom_smtp' => false]);

        $this->get('/smtp')->assertOk();
        $this->get('/smtp/create')->assertRedirect();
        $this->post('/smtp', $this->payload())->assertSessionHas('error');

        $this->assertSame(0, SmtpAccount::withoutGlobalScopes()->where('is_global', false)->count());
    }

    public function test_the_smtp_account_limit_is_enforced(): void
    {
        $this->allow(['max_smtp_accounts' => 1]);

        $this->post('/smtp', $this->payload(['name' => 'First']))->assertRedirect();
        $this->post('/smtp', $this->payload(['name' => 'Second']))->assertSessionHas('error');

        $this->assertSame(1, SmtpAccount::withoutGlobalScopes()->where('is_global', false)->count());
    }

    // ---------------------------------------------------------- ownership

    public function test_a_tenant_cannot_edit_or_delete_a_shared_admin_account(): void
    {
        $shared = SmtpAccount::factory()->global()->create(['name' => 'Platform relay']);
        SmtpAssignment::create(['smtp_account_id' => $shared->id, 'scope' => 'all']);

        $this->asOwner();

        // It is visible...
        $this->get("/smtp/{$shared->id}")->assertOk();

        // ...but not theirs to change.
        $this->get("/smtp/{$shared->id}/edit")->assertForbidden();
        $this->put("/smtp/{$shared->id}", $this->payload())->assertForbidden();
        $this->delete("/smtp/{$shared->id}")->assertForbidden();
        $this->patch("/smtp/{$shared->id}/status")->assertForbidden();

        $this->assertNotNull(SmtpAccount::withoutGlobalScopes()->find($shared->id));
    }

    public function test_an_unassigned_shared_account_is_invisible_to_the_tenant(): void
    {
        $shared = SmtpAccount::factory()->global()->create(['name' => 'Not for you']);

        $this->asOwner();

        $this->get("/smtp/{$shared->id}")->assertNotFound();
        $this->get('/smtp')->assertOk()->assertDontSee('Not for you');
    }

    public function test_a_plan_scoped_shared_account_only_appears_for_that_plan(): void
    {
        $shared = SmtpAccount::factory()->global()->create(['name' => 'Business relay']);
        $business = Plan::where('slug', 'business')->firstOrFail();
        SmtpAssignment::create([
            'smtp_account_id' => $shared->id, 'scope' => 'plan', 'plan_id' => $business->id,
        ]);

        $this->asOwner();
        $this->get("/smtp/{$shared->id}")->assertNotFound();

        $this->owner->account->subscription->update(['plan_id' => $business->id]);
        $this->owner->account->refresh();

        $this->get("/smtp/{$shared->id}")->assertOk();
    }

    /**
     * The list screen, not the detail page.
     *
     * Everything above tests /smtp/{id}, and a tenant almost never arrives
     * there directly — they open the list, look for the account their mail is
     * going through, and judge from what they find. Both halves of that
     * rendering (the selector returning the account, and the section being
     * shown at all) could be broken without a single test failing, and the
     * symptom would be exactly the report this was written for: "the admin
     * SMTP accounts are not showing to the user".
     */
    public function test_an_assigned_shared_account_appears_on_the_list_screen(): void
    {
        $shared = SmtpAccount::factory()->global()->create(['name' => 'Platform relay']);
        SmtpAssignment::create(['smtp_account_id' => $shared->id, 'scope' => 'all']);

        $this->asOwner();

        $this->get('/smtp')
            ->assertOk()
            ->assertSee('Provided by')
            ->assertSee('Platform relay')
            ->assertSee($shared->host);
    }

    public function test_a_plan_scoped_shared_account_appears_on_the_list_only_for_that_plan(): void
    {
        $shared = SmtpAccount::factory()->global()->create(['name' => 'Business relay']);
        $business = Plan::where('slug', 'business')->firstOrFail();
        SmtpAssignment::create([
            'smtp_account_id' => $shared->id, 'scope' => 'plan', 'plan_id' => $business->id,
        ]);

        $this->asOwner();
        $this->get('/smtp')->assertOk()->assertDontSee('Business relay');

        $this->owner->account->subscription->update(['plan_id' => $business->id]);
        $this->owner->account->refresh();

        $this->get('/smtp')->assertOk()->assertSee('Business relay');
    }

    /**
     * A tenant that may not add its own SMTP and has none shared with it yet.
     * The screen must still show the section its mail would come from, and say
     * nothing has been assigned — that is the difference between "an
     * administrator has not done it yet" and "this product is broken", and it
     * is the exact case behind the report that admin SMTP accounts were not
     * showing up.
     */
    public function test_a_tenant_awaiting_an_assignment_is_told_what_is_missing(): void
    {
        $this->allow(['allow_custom_smtp' => false, 'max_smtp_accounts' => 0]);

        SmtpAccount::withoutGlobalScopes()->where('is_global', false)->delete();

        $this->asOwner();

        $this->get('/smtp')
            ->assertOk()
            ->assertSee('Provided by')
            ->assertSee('No shared accounts assigned yet')
            ->assertDontSee('Add SMTP account');
    }

    /**
     * The genuinely dead case — neither route allowed. Here there is nothing to
     * show and nothing for the tenant to do, so the screen says who to ask.
     */
    public function test_a_tenant_with_no_route_out_at_all_is_told_to_contact_support(): void
    {
        $this->allow([
            'allow_custom_smtp' => false, 'max_smtp_accounts' => 0, 'allow_admin_smtp' => false,
        ]);

        SmtpAccount::withoutGlobalScopes()->where('is_global', false)->delete();

        $this->asOwner();

        $this->get('/smtp')
            ->assertOk()
            ->assertSee('No SMTP account yet')
            ->assertSee('does not include your own SMTP accounts')
            ->assertDontSee('Add SMTP account');
    }

    /**
     * The same tenant once an administrator has shared an account with it: the
     * screen must stop saying sending is unavailable.
     */
    public function test_a_shared_account_replaces_that_message(): void
    {
        $this->allow(['allow_custom_smtp' => false, 'max_smtp_accounts' => 0]);
        SmtpAccount::withoutGlobalScopes()->where('is_global', false)->delete();

        $shared = SmtpAccount::factory()->global()->create(['name' => 'Platform relay']);
        SmtpAssignment::create(['smtp_account_id' => $shared->id, 'scope' => 'all']);

        $this->asOwner();

        $this->get('/smtp')
            ->assertOk()
            ->assertSee('Platform relay')
            ->assertDontSee('No SMTP account yet');
    }

    /**
     * The plan allows the platform's accounts but nobody has assigned one. The
     * section has to appear and explain itself, or the tenant cannot tell the
     * difference between "not set up yet" and "broken".
     */
    public function test_the_shared_section_explains_itself_when_nothing_is_assigned(): void
    {
        $this->get('/smtp')
            ->assertOk()
            ->assertSee('Provided by')
            ->assertSee('No shared accounts assigned yet');
    }

    public function test_one_account_cannot_reach_another_accounts_smtp(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@smtp.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = SmtpAccount::factory()->forAccount($other->account)->create();

        $this->asOwner();

        $this->get("/smtp/{$foreign->id}")->assertNotFound();
        $this->put("/smtp/{$foreign->id}", $this->payload())->assertNotFound();
        $this->delete("/smtp/{$foreign->id}")->assertNotFound();
    }

    // -------------------------------------------------------- permissions

    public function test_smtp_manage_is_required_to_change_anything(): void
    {
        $staffRole = Role::withoutGlobalScopes()->where('slug', Role::STAFF)->first();
        $staffRole->permissions()->syncWithoutDetaching(
            Permission::whereIn('slug', ['smtp.view'])->pluck('id')
        );
        $staff = User::factory()->forAccount($this->owner->account)->create(['role_id' => $staffRole->id]);

        $account = SmtpAccount::factory()->forAccount($this->owner->account)->create();

        $this->actingAs($staff->fresh());

        $this->get('/smtp')->assertOk();
        $this->get('/smtp/create')->assertForbidden();
        $this->post('/smtp', $this->payload())->assertForbidden();
        $this->delete("/smtp/{$account->id}")->assertForbidden();
    }

    // ------------------------------------------------------ test endpoint

    public function test_the_test_endpoint_reports_a_real_failure_as_json(): void
    {
        // Port 1 on localhost refuses immediately, so this exercises the real
        // handshake path without waiting on a network timeout.
        $account = SmtpAccount::factory()->forAccount($this->owner->account)->create([
            'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'password' => 'leak-check',
        ]);

        $response = $this->postJson("/smtp/{$account->id}/test");

        $response->assertStatus(422)
            ->assertJsonStructure(['ok', 'summary', 'detail', 'code', 'ms', 'tested_at'])
            ->assertJson(['ok' => false]);

        $this->assertStringNotContainsString('leak-check', $response->getContent(),
            'A failed test must never echo the credential back.');

        $account->refresh();

        $this->assertFalse((bool) $account->test_passed);
        $this->assertNotNull($account->last_tested_at);
        $this->assertNotNull($account->last_error);
        $this->assertStringNotContainsString('leak-check', (string) $account->last_error);
    }

    // -------------------------------------------------------------- admin

    public function test_an_admin_creates_a_global_account_that_reaches_nobody_until_assigned(): void
    {
        $this->asAdmin();

        $this->post('/admin/smtp', $this->payload(['name' => 'Platform relay']))->assertRedirect();

        $account = SmtpAccount::withoutGlobalScopes()->firstWhere('name', 'Platform relay');

        $this->assertTrue((bool) $account->is_global);
        $this->assertNull($account->account_id, 'A platform account must never belong to a tenant.');
        $this->assertSame(0, SmtpAssignment::where('smtp_account_id', $account->id)->count());
    }

    public function test_assignment_scope_can_be_switched(): void
    {
        $this->asAdmin();

        $account = SmtpAccount::factory()->global()->create();
        $business = Plan::where('slug', 'business')->firstOrFail();

        $this->put("/admin/smtp/{$account->id}/assignments", [
            'scope' => 'plan', 'plan_ids' => [$business->id],
        ])->assertRedirect();

        $this->assertSame(1, SmtpAssignment::where('smtp_account_id', $account->id)->where('scope', 'plan')->count());

        // Switching scope replaces the whole set rather than adding to it.
        $this->put("/admin/smtp/{$account->id}/assignments", ['scope' => 'all'])->assertRedirect();

        $assignments = SmtpAssignment::where('smtp_account_id', $account->id)->get();

        $this->assertCount(1, $assignments);
        $this->assertSame('all', $assignments->first()->scope);

        $this->put("/admin/smtp/{$account->id}/assignments", ['scope' => 'none'])->assertRedirect();

        $this->assertSame(0, SmtpAssignment::where('smtp_account_id', $account->id)->count());
    }

    public function test_a_plan_scope_without_plans_is_refused(): void
    {
        $this->asAdmin();

        $account = SmtpAccount::factory()->global()->create();
        SmtpAssignment::create(['smtp_account_id' => $account->id, 'scope' => 'all']);

        $this->put("/admin/smtp/{$account->id}/assignments", ['scope' => 'plan', 'plan_ids' => []])
            ->assertSessionHas('error');

        $this->assertSame(1, SmtpAssignment::where('smtp_account_id', $account->id)->count(),
            'A refused change must leave the previous assignment intact.');
    }

    public function test_the_admin_screens_do_not_manage_tenant_owned_accounts(): void
    {
        $tenantOwned = SmtpAccount::factory()->forAccount($this->owner->account)->create();

        $this->asAdmin();

        $this->get("/admin/smtp/{$tenantOwned->id}")->assertNotFound();
        $this->put("/admin/smtp/{$tenantOwned->id}", $this->payload())->assertNotFound();
        $this->delete("/admin/smtp/{$tenantOwned->id}")->assertNotFound();
    }

    public function test_an_account_user_cannot_reach_the_admin_smtp_screens(): void
    {
        $this->asOwner();

        $this->get('/admin/smtp')->assertForbidden();
        $this->post('/admin/smtp', $this->payload())->assertForbidden();
    }

    public function test_a_tenant_testing_a_shared_account_cannot_clear_its_cooldown(): void
    {
        // A shared account is in cooldown because the platform credential is
        // failing. One customer must not be able to lift that for everyone.
        $shared = SmtpAccount::factory()->global()->inCooldown()->create([
            'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none',
        ]);
        SmtpAssignment::create(['smtp_account_id' => $shared->id, 'scope' => 'all']);

        $before = $shared->fresh();

        $this->asOwner();
        $this->postJson("/smtp/{$shared->id}/test")->assertStatus(422);

        $after = $shared->fresh();

        $this->assertSame(
            (int) $before->consecutive_failures,
            (int) $after->consecutive_failures,
            'A tenant test must not touch the platform account health.'
        );
        $this->assertEquals($before->cooldown_until, $after->cooldown_until);
        $this->assertEquals($before->last_tested_at, $after->last_tested_at);
    }

    public function test_a_tenant_testing_its_own_account_does_record_the_result(): void
    {
        $account = SmtpAccount::factory()->forAccount($this->owner->account)->create([
            'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'test_passed' => true,
        ]);

        $this->postJson("/smtp/{$account->id}/test")->assertStatus(422);

        $fresh = $account->fresh();

        $this->assertFalse((bool) $fresh->test_passed);
        $this->assertNotNull($fresh->last_tested_at);
    }
}
