<?php

namespace Tests\Feature\Smtp;

use App\Models\Plan;
use App\Models\Scopes\AccountScope;
use App\Models\SmtpAccount;
use App\Models\SmtpAssignment;
use App\Models\User;
use App\Services\AccountProvisioner;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The diagnostic has one job: when an account cannot send, say which of the
 * four independent things is wrong. A diagnostic that reports the symptom back
 * at you is worse than none, because it is trusted.
 */
class SmtpCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);
    }

    protected function provision(string $email = 'owner@check.test', string $company = 'Check Ltd'): User
    {
        return app(AccountProvisioner::class)->provision([
            'company_name' => $company, 'name' => 'Owner', 'email' => $email,
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
    }

    // ------------------------------------------------------- the usual cause

    public function test_it_names_the_missing_assignment_rather_than_the_symptom(): void
    {
        $this->provision();
        SmtpAccount::factory()->global()->create(['name' => 'Platform relay']);

        $this->artisan('kn:smtp-check')
            ->expectsOutputToContain('NO ASSIGNMENT')
            ->expectsOutputToContain('No assignment reaches this account')
            ->assertFailed();
    }

    public function test_an_assigned_platform_account_is_reported_as_working(): void
    {
        $this->provision();
        $global = SmtpAccount::factory()->global()->create();
        SmtpAssignment::create(['smtp_account_id' => $global->id, 'scope' => 'all']);

        $this->artisan('kn:smtp-check')
            ->expectsOutputToContain('Every active account has at least one way to send')
            ->assertSuccessful();
    }

    public function test_it_says_so_when_there_is_no_platform_smtp_at_all(): void
    {
        $this->provision();

        $this->artisan('kn:smtp-check')
            ->expectsOutputToContain('Nobody can send through a platform account')
            ->assertFailed();
    }

    // ------------------------------------------------------- the other three

    /**
     * A global account that somehow carries an account_id is invisible to every
     * query that looks for shared accounts, and no screen shows the column.
     */
    public function test_a_global_account_owned_by_a_tenant_is_flagged(): void
    {
        $owner = $this->provision();
        $global = SmtpAccount::factory()->global()->create();
        SmtpAssignment::create(['smtp_account_id' => $global->id, 'scope' => 'all']);

        SmtpAccount::withoutGlobalScope(AccountScope::class)
            ->where('id', $global->id)->update(['account_id' => $owner->account_id]);

        $this->artisan('kn:smtp-check')
            ->expectsOutputToContain('a global account must not')
            ->assertFailed();
    }

    /**
     * A soft-deleted plan keeps its accounts, and the selector matches on
     * plan_id without looking at deleted_at — so the assignment still works and
     * must not be reported as dangling. This is the same trap that made
     * Subscription::plan() resolve to null and hand out unlimited everything.
     */
    public function test_an_assignment_to_a_soft_deleted_plan_is_reported_as_still_working(): void
    {
        $owner = $this->provision();
        $global = SmtpAccount::factory()->global()->create();
        $plan = Plan::withoutGlobalScope(AccountScope::class)->where('slug', 'business')->firstOrFail();

        $owner->account->subscription->update(['plan_id' => $plan->id]);

        SmtpAssignment::create([
            'smtp_account_id' => $global->id, 'scope' => 'plan', 'plan_id' => $plan->id,
        ]);

        $plan->delete();

        // One expectation per output line: they are matched in order against
        // successive lines, so two substrings from the same line never both
        // match.
        $this->artisan('kn:smtp-check')
            ->expectsOutputToContain('Business (deleted')
            ->doesntExpectOutputToContain('no longer exists')
            // And it really does still work, which is the point of saying so.
            ->expectsOutputToContain('Every active account has at least one way to send')
            ->assertSuccessful();
    }

    /**
     * Force-deleting a plan cascades the assignment away with it, so the
     * dangling-row case cannot arise from that direction. Worth pinning: if the
     * foreign key ever loses its cascade, this starts failing and the command
     * needs the branch that reports a missing target.
     */
    public function test_force_deleting_a_plan_takes_its_assignments_with_it(): void
    {
        $this->provision();
        $global = SmtpAccount::factory()->global()->create();
        $plan = Plan::withoutGlobalScope(AccountScope::class)->where('slug', 'business')->firstOrFail();

        SmtpAssignment::create([
            'smtp_account_id' => $global->id, 'scope' => 'plan', 'plan_id' => $plan->id,
        ]);

        $plan->forceDelete();

        $this->assertSame(0, SmtpAssignment::count());
    }

    public function test_a_plan_that_forbids_both_routes_is_named_as_the_cause(): void
    {
        $owner = $this->provision();
        $global = SmtpAccount::factory()->global()->create();
        SmtpAssignment::create(['smtp_account_id' => $global->id, 'scope' => 'all']);

        $owner->account->subscription->update([
            'overrides' => ['allow_admin_smtp' => false, 'allow_custom_smtp' => false],
        ]);

        $this->artisan('kn:smtp-check')
            ->expectsOutputToContain('no route out by design')
            ->assertFailed();
    }

    /**
     * A suspended account cannot send, and that is the intended state — so it
     * must not be counted among the ones that need fixing.
     */
    public function test_a_suspended_account_is_explained_but_not_counted_as_broken(): void
    {
        $owner = $this->provision();
        $global = SmtpAccount::factory()->global()->create();
        SmtpAssignment::create(['smtp_account_id' => $global->id, 'scope' => 'all']);

        $owner->account->update(['status' => 'suspended']);

        $this->artisan('kn:smtp-check')
            ->expectsOutputToContain('nothing sends for it')
            ->assertSuccessful();
    }

    public function test_a_paused_platform_account_is_distinguished_from_an_unassigned_one(): void
    {
        $this->provision();
        $global = SmtpAccount::factory()->global()->create(['is_active' => false]);
        SmtpAssignment::create(['smtp_account_id' => $global->id, 'scope' => 'all']);

        $this->artisan('kn:smtp-check')
            ->expectsOutputToContain('paused')
            ->assertFailed();
    }

    // ------------------------------------------------------------- filtering

    public function test_it_can_be_pointed_at_one_account(): void
    {
        $this->provision('a@check.test', 'Alpha Ltd');
        $this->provision('b@check.test', 'Beta Ltd');

        $global = SmtpAccount::factory()->global()->create();
        SmtpAssignment::create(['smtp_account_id' => $global->id, 'scope' => 'all']);

        $this->artisan('kn:smtp-check', ['account' => 'alpha-ltd'])
            ->expectsOutputToContain('Alpha Ltd')
            ->doesntExpectOutputToContain('Beta Ltd')
            ->assertSuccessful();
    }

    public function test_an_unknown_account_is_not_an_error(): void
    {
        $this->provision();
        $global = SmtpAccount::factory()->global()->create();
        SmtpAssignment::create(['smtp_account_id' => $global->id, 'scope' => 'all']);

        $this->artisan('kn:smtp-check', ['account' => 'nope'])
            ->expectsOutputToContain('No account matches')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------- secrets

    /**
     * The output exists to be pasted into a support thread, so it must not
     * carry anything that would be a leak to paste.
     */
    public function test_it_prints_no_credentials(): void
    {
        $this->provision();

        SmtpAccount::factory()->global()->create([
            'username' => 'secret-user@relay.test',
            'password' => 'super-secret-password',
        ]);

        $this->artisan('kn:smtp-check')
            ->doesntExpectOutputToContain('secret-user@relay.test')
            ->doesntExpectOutputToContain('super-secret-password')
            ->assertFailed();
    }
}
