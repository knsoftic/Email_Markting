<?php

namespace Tests\Feature\Plans;

use App\Models\Plan;
use App\Models\SmtpAccount;
use App\Models\SmtpAssignment;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\SettingsService;
use App\Services\Smtp\SmtpSelector;
use App\Support\PlanLimits;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The shape of the plan ladder, and the promises it makes.
 *
 * The ladder is Free → Starter ($5) → Business ($29) → Pro ($79), and the two
 * things that make it work are easy to break silently:
 *
 *   1. A new signup must land somewhere that costs nothing. There are two
 *      independent mechanisms for that — the `system.default_plan_slug` setting
 *      and the plan's own `is_default` flag — and the setting wins. Either one
 *      pointing at a paid plan puts every new customer on a bill they did not
 *      agree to, and nothing in the interface would say so.
 *
 *   2. A free account must actually be able to send. It has no SMTP of its
 *      own by design, so its only route out is a global SMTP account the
 *      operator has assigned to it. If that route is closed the free plan is
 *      not a free plan, it is a dead account.
 *
 * The rest of these are consistency checks between columns that can be edited
 * independently and contradict each other without any error being raised.
 */
class PlanLadderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);
    }

    protected function provision(string $email = 'owner@ladder.test'): User
    {
        $user = app(AccountProvisioner::class)->provision([
            'company_name' => 'Ladder Ltd', 'name' => 'Owner', 'email' => $email,
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    // ------------------------------------------------------------ the ladder

    public function test_the_ladder_starts_free_and_the_cheapest_paid_plan_is_five(): void
    {
        $prices = Plan::withoutGlobalScopes()->orderBy('sort_order')
            ->pluck('price', 'slug')->map(fn ($p) => (float) $p);

        $this->assertSame(0.0, $prices['free']);
        $this->assertSame(5.0, $prices['starter']);

        $paid = $prices->filter(fn ($p) => $p > 0);

        $this->assertSame(5.0, $paid->min(), 'Nothing may be cheaper than the $5 entry plan.');
    }

    /**
     * A ladder whose rungs are not in order is worse than no ladder: the
     * billing screen lists plans by sort_order, so an out-of-order row reads
     * as a more expensive plan offering less.
     */
    public function test_price_rises_with_sort_order(): void
    {
        $prices = Plan::withoutGlobalScopes()->orderBy('sort_order')
            ->pluck('price')->map(fn ($p) => (float) $p)->all();

        $sorted = $prices;
        sort($sorted);

        $this->assertSame($sorted, $prices);
    }

    // ------------------------------------------------- where a signup lands

    public function test_a_new_signup_lands_on_a_plan_that_costs_nothing(): void
    {
        $plan = $this->provision()->account->subscription->plan;

        $this->assertSame('free', $plan->slug);
        $this->assertSame(0.0, (float) $plan->price);
    }

    /**
     * Both mechanisms have to agree. The setting is what actually decides, so
     * a stale 'starter' in the settings table would send every new customer to
     * a paid plan while the flag on the Free row said otherwise.
     */
    public function test_the_setting_and_the_flag_both_point_at_free(): void
    {
        $this->assertSame('free', app(SettingsService::class)->get('system', 'default_plan_slug'));

        $flagged = Plan::withoutGlobalScopes()->where('is_default', true)->get();

        $this->assertCount(1, $flagged, 'Exactly one plan may be the default.');
        $this->assertSame('free', $flagged->first()->slug);
    }

    /**
     * The setting is a free-text slug with no foreign key behind it. If it
     * names a plan that does not exist the provisioner falls back silently,
     * which is safe but means the operator's choice is being ignored without
     * anybody being told.
     */
    public function test_the_default_plan_setting_names_a_plan_that_exists(): void
    {
        $slug = app(SettingsService::class)->get('system', 'default_plan_slug');

        $this->assertTrue(
            Plan::withoutGlobalScopes()->whereNull('deleted_at')->where('slug', $slug)->where('is_active', true)->exists(),
            "The default plan setting names '{$slug}', which is not an active plan."
        );
    }

    // ------------------------------------------- a free account can still send

    /**
     * The whole point of the free tier: no SMTP of its own, but a real route
     * out through one the operator lends it.
     */
    public function test_a_free_account_sends_through_an_assigned_global_smtp(): void
    {
        $owner = $this->provision();
        $this->actingAs($owner);
        app(TenantManager::class)->set($owner->account_id);

        $global = SmtpAccount::factory()->global()->create(['name' => 'Platform relay']);

        // Before the assignment exists, a global account reaches nobody. This
        // is the half operators get wrong: creating the SMTP is not sharing it.
        $this->assertCount(0, app(SmtpSelector::class)->candidatesFor($owner->account));

        SmtpAssignment::create(['smtp_account_id' => $global->id, 'scope' => 'all']);

        $candidates = app(SmtpSelector::class)->candidatesFor($owner->account->fresh());

        $this->assertCount(1, $candidates);
        $this->assertSame($global->id, $candidates->first()->id);
    }

    /**
     * An assignment scoped to the free plan reaches free accounts and nobody
     * else — which is how an operator lends a cheap relay to the free tier
     * without putting paying customers on it.
     */
    public function test_a_plan_scoped_assignment_reaches_only_that_plan(): void
    {
        $free = $this->provision('free@ladder.test');
        $paid = $this->provision('paid@ladder.test');

        $paid->account->subscription->update([
            'plan_id' => Plan::withoutGlobalScopes()->where('slug', 'business')->value('id'),
        ]);

        $global = SmtpAccount::factory()->global()->create(['name' => 'Free tier relay']);

        SmtpAssignment::create([
            'smtp_account_id' => $global->id,
            'scope' => 'plan',
            'plan_id' => Plan::withoutGlobalScopes()->where('slug', 'free')->value('id'),
        ]);

        $selector = app(SmtpSelector::class);

        $this->assertCount(1, $selector->candidatesFor($free->account->fresh()));
        $this->assertCount(0, $selector->candidatesFor($paid->account->fresh()));
    }

    public function test_a_free_account_cannot_add_smtp_of_its_own(): void
    {
        $owner = $this->provision();
        $limits = PlanLimits::for($owner->account);

        $this->assertFalse($limits->allows('allow_custom_smtp'));
        $this->assertSame(0, $limits->limit('max_smtp_accounts'));
    }

    public function test_the_first_paid_plan_is_what_unlocks_your_own_smtp(): void
    {
        $starter = Plan::withoutGlobalScopes()->where('slug', 'starter')->firstOrFail();

        $this->assertTrue($starter->allow_custom_smtp);
        $this->assertGreaterThan(0, $starter->max_smtp_accounts);
    }

    // -------------------------------------------------- column contradictions

    /**
     * assertMayAdd() checks allow_template_builder before *creating* a
     * template, so a plan that grants max_templates but switches the builder
     * off is one that cannot make a single one of the templates it sold.
     */
    public function test_no_plan_grants_templates_it_cannot_create(): void
    {
        foreach (Plan::withoutGlobalScopes()->get() as $plan) {
            if ($plan->max_templates !== 0) {
                $this->assertTrue(
                    $plan->allow_template_builder,
                    "{$plan->name} allows templates but cannot create one."
                );
            }
        }
    }

    /**
     * The two halves of the inbox: the feature switch and the mailbox count.
     * One without the other is a menu entry that leads to a wall.
     */
    public function test_the_inbox_switch_and_the_mailbox_count_agree(): void
    {
        foreach (Plan::withoutGlobalScopes()->get() as $plan) {
            $this->assertSame(
                $plan->allow_imap,
                $plan->max_mailboxes !== 0,
                "{$plan->name} has allow_imap and max_mailboxes disagreeing."
            );
        }
    }

    /**
     * Rotation means "spread across several accounts". A plan that grants it
     * while allowing one account is selling something it cannot do.
     */
    public function test_rotation_is_only_granted_where_there_is_something_to_rotate(): void
    {
        foreach (Plan::withoutGlobalScopes()->where('allow_smtp_rotation', true)->get() as $plan) {
            $this->assertTrue(
                $plan->max_smtp_accounts === null || $plan->max_smtp_accounts > 1,
                "{$plan->name} grants rotation but allows {$plan->max_smtp_accounts} SMTP accounts."
            );
        }
    }

    /**
     * Every plan can send at all. A plan with neither its own SMTP nor the
     * platform's is an account that can do everything except the one thing
     * this product is for.
     */
    public function test_every_plan_has_some_way_to_send(): void
    {
        foreach (Plan::withoutGlobalScopes()->get() as $plan) {
            $this->assertTrue(
                $plan->allow_admin_smtp || ($plan->allow_custom_smtp && $plan->max_smtp_accounts !== 0),
                "{$plan->name} has no route to an SMTP server at all."
            );
        }
    }

    // ------------------------------------------------------- re-seeding is safe

    /**
     * The seeder runs against live databases, so its result must not depend on
     * what somebody typed into the admin form last year. Every column is
     * written on every run — this proves it by corrupting one first.
     */
    public function test_reseeding_restores_a_hand_edited_plan(): void
    {
        Plan::withoutGlobalScopes()->where('slug', 'starter')->update([
            'price' => 999,
            'max_contacts' => 7,
            'allow_api' => true,
            'is_default' => true,
        ]);

        $this->seed(PlanSeeder::class);

        $starter = Plan::withoutGlobalScopes()->where('slug', 'starter')->firstOrFail();

        $this->assertSame(5.0, (float) $starter->price);
        $this->assertSame(2000, $starter->max_contacts);
        $this->assertFalse($starter->allow_api);
        $this->assertFalse($starter->is_default);
    }

    public function test_reseeding_does_not_duplicate_plans(): void
    {
        $before = Plan::withoutGlobalScopes()->count();

        $this->seed(PlanSeeder::class);

        $this->assertSame($before, Plan::withoutGlobalScopes()->count());
    }

    /**
     * The migration that moved existing installations off Starter must leave a
     * deliberate choice of Business or Pro alone.
     */
    public function test_the_migration_leaves_a_deliberate_choice_alone(): void
    {
        $this->storeDefaultPlanSlug('business');

        $this->runMigrationThatMovesTheDefault();

        $this->assertSame('business', $this->storedDefaultPlanSlug());
    }

    public function test_the_migration_moves_the_old_shipped_value(): void
    {
        $this->storeDefaultPlanSlug('starter');

        $this->runMigrationThatMovesTheDefault();

        $this->assertSame('free', $this->storedDefaultPlanSlug());
    }

    /**
     * A fresh install has no row at all — SettingsService falls back to its own
     * DEFAULTS — and the migration must not invent one, or it would write a
     * value the operator never chose and freeze it against future changes.
     */
    public function test_the_migration_does_not_create_a_setting_that_was_never_saved(): void
    {
        $this->runMigrationThatMovesTheDefault();

        $this->assertNull($this->storedDefaultPlanSlug());
        $this->assertSame('free', app(SettingsService::class)->get('system', 'default_plan_slug'));
    }

    /** Simulates an installation whose settings have been saved at some point. */
    protected function storeDefaultPlanSlug(string $value): void
    {
        DB::table('settings')->insert([
            'account_id' => null, 'group' => 'system', 'key' => 'default_plan_slug',
            'value' => $value, 'type' => 'string',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function storedDefaultPlanSlug(): ?string
    {
        return DB::table('settings')->whereNull('account_id')
            ->where('group', 'system')->where('key', 'default_plan_slug')->value('value');
    }

    protected function runMigrationThatMovesTheDefault(): void
    {
        $path = database_path('migrations/2026_09_08_000195_point_the_default_plan_at_free.php');

        $this->assertFileExists($path);

        (require $path)->up();
    }
}
