<?php

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\Role;
use App\Models\Scopes\AccountScope;
use App\Models\SmtpAccount;
use App\Models\SmtpAssignment;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The assignment form is the step that decides who can send at all, and the
 * ways it went wrong were all silent: the operator left believing they had
 * shared an account when they had not, or had un-shared one without touching
 * it. Both produce the same report from the customer — "the admin SMTP
 * accounts are not showing" — with nothing in any log to say why.
 */
class SmtpAssignmentFormTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $owner;

    protected SmtpAccount $global;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->admin = User::factory()->superAdmin()->create([
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::SUPER_ADMIN)->value('id'),
            'email_verified_at' => now(),
        ]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Customer Ltd', 'name' => 'Owner',
            'email' => 'customer@assign.test', 'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $this->global = SmtpAccount::factory()->global()->create(['name' => 'Platform relay']);

        $this->actingAs($this->admin);
        app(TenantManager::class)->forget();
    }

    protected function plan(string $slug): Plan
    {
        return Plan::withTrashed()->where('slug', $slug)->firstOrFail();
    }

    // ------------------------------------------------------- the guard bounce

    /**
     * Picking "accounts on selected plans" and forgetting to tick one bounced
     * back without the input, so the radio reverted to the saved scope while
     * the error still said to pick a plan. The operator fixes what the message
     * names, saves, and has changed nothing.
     */
    public function test_a_guard_bounce_keeps_the_scope_the_operator_chose(): void
    {
        SmtpAssignment::create(['smtp_account_id' => $this->global->id, 'scope' => 'all']);

        $this->from(route('admin.smtp.show', $this->global))
            ->put("/admin/smtp/{$this->global->id}/assignments", ['scope' => 'plan'])
            ->assertRedirect(route('admin.smtp.show', $this->global))
            ->assertSessionHas('error')
            ->assertSessionHasInput('scope', 'plan');
    }

    public function test_the_same_holds_for_the_account_scope(): void
    {
        $this->put("/admin/smtp/{$this->global->id}/assignments", ['scope' => 'account'])
            ->assertSessionHas('error')
            ->assertSessionHasInput('scope', 'account');
    }

    /** A bounce must not have changed anything on the way past. */
    public function test_a_guard_bounce_leaves_the_existing_assignment_alone(): void
    {
        SmtpAssignment::create(['smtp_account_id' => $this->global->id, 'scope' => 'all']);

        $this->put("/admin/smtp/{$this->global->id}/assignments", ['scope' => 'plan']);

        $this->assertSame(1, SmtpAssignment::where('scope', 'all')->count());
    }

    // ------------------------------------------------- the soft-deleted plan

    /**
     * The one that actually cuts customers off. A soft-deleted plan keeps its
     * accounts and its assignments keep matching, but the form did not fetch
     * it, so it could not draw the checkbox — and saving replaces the whole set
     * from what was ticked. Opening the page and pressing Save, changing
     * nothing, dropped the assignment.
     */
    public function test_reopening_and_saving_does_not_drop_an_assignment_to_a_deleted_plan(): void
    {
        $business = $this->plan('business');
        $this->owner->account->subscription->update(['plan_id' => $business->id]);

        SmtpAssignment::create([
            'smtp_account_id' => $this->global->id, 'scope' => 'plan', 'plan_id' => $business->id,
        ]);

        $business->delete();

        // The form still offers it, marked, so it survives a round trip.
        $this->get(route('admin.smtp.show', $this->global))
            ->assertOk()
            ->assertSee('Business')
            ->assertSee('deleted — still has accounts');

        $this->put("/admin/smtp/{$this->global->id}/assignments", [
            'scope' => 'plan', 'plan_ids' => [$business->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('smtp_assignments', [
            'smtp_account_id' => $this->global->id, 'scope' => 'plan', 'plan_id' => $business->id,
        ]);

        // And the customer on that plan still has a route out.
        $this->assertCount(1, app(\App\Services\Smtp\SmtpSelector::class)
            ->visibleSharedFor($this->owner->account->fresh()));
    }

    /**
     * A deleted plan nobody is assigned to stays off the list — it is only
     * shown because removing it would break something.
     */
    public function test_an_unrelated_deleted_plan_is_not_offered(): void
    {
        SmtpAssignment::create(['smtp_account_id' => $this->global->id, 'scope' => 'all']);

        $pro = $this->plan('pro');
        $pro->delete();

        $this->get(route('admin.smtp.show', $this->global))
            ->assertOk()
            ->assertDontSee('deleted — still has accounts');
    }

    // -------------------------------------------------------- the happy path

    public function test_setting_a_plan_scope_reaches_only_that_plan(): void
    {
        $business = $this->plan('business');

        $this->put("/admin/smtp/{$this->global->id}/assignments", [
            'scope' => 'plan', 'plan_ids' => [$business->id],
        ])->assertRedirect()->assertSessionHas('success');

        $selector = app(\App\Services\Smtp\SmtpSelector::class);

        $this->assertCount(0, $selector->visibleSharedFor($this->owner->account->fresh()));

        $this->owner->account->subscription->update(['plan_id' => $business->id]);

        $this->assertCount(1, $selector->visibleSharedFor($this->owner->account->fresh()));
    }

    public function test_scope_none_takes_it_away_from_everybody(): void
    {
        SmtpAssignment::create(['smtp_account_id' => $this->global->id, 'scope' => 'all']);

        $this->put("/admin/smtp/{$this->global->id}/assignments", ['scope' => 'none'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, SmtpAssignment::count());

        $this->assertCount(0, app(\App\Services\Smtp\SmtpSelector::class)
            ->visibleSharedFor($this->owner->account->fresh()));
    }

    public function test_only_a_super_admin_can_change_assignments(): void
    {
        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->put("/admin/smtp/{$this->global->id}/assignments", ['scope' => 'all'])
            ->assertForbidden();

        $this->assertSame(0, SmtpAssignment::count());
    }

    /** A tenant's own account is not a platform account and has no assignments. */
    public function test_a_tenant_owned_account_cannot_be_assigned(): void
    {
        $own = SmtpAccount::factory()->forAccount($this->owner->account)->create();

        $this->put("/admin/smtp/{$own->id}/assignments", ['scope' => 'all'])
            ->assertNotFound();

        $this->assertSame(0, SmtpAssignment::count());
    }

    public function test_a_bogus_plan_id_is_refused(): void
    {
        $this->put("/admin/smtp/{$this->global->id}/assignments", [
            'scope' => 'plan', 'plan_ids' => [999999],
        ])->assertSessionHasErrors('plan_ids.0');

        $this->assertSame(0, SmtpAssignment::count());
    }

    public function test_hostile_input_does_not_crash_it(): void
    {
        foreach ([
            ['scope' => 'nonsense'],
            ['scope' => 'plan', 'plan_ids' => 'abc'],
            ['scope' => 'account', 'account_ids' => [['x']]],
            [],
        ] as $payload) {
            $response = $this->put("/admin/smtp/{$this->global->id}/assignments", $payload);
            $this->assertNotSame(500, $response->status(), json_encode($payload));
        }
    }
}
