<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves the signup plan from Starter to Free.
 *
 * ── Why a migration and not the seeder ──────────────────────────────────────
 * Starter used to cost nothing and was where every new signup landed. It now
 * costs $5, and a free plan exists below it. `PlanSeeder` sets `is_default` on
 * the Free row, but that flag is only the *fallback*: AccountProvisioner reads
 * the `system.default_plan_slug` setting first, and an installation that has
 * ever saved its settings has the literal string 'starter' sitting in the
 * settings table. On those installations the flag is never consulted, and
 * every new signup would silently be put on a paid plan.
 *
 * SettingSeeder cannot fix it, because it rewrites *every* default and would
 * take the operator's branding, support address and payment instructions with
 * it. So the change is made here, surgically, once.
 *
 * ── Why only 'starter' is moved ─────────────────────────────────────────────
 * An operator who deliberately chose Business or Pro as the signup plan meant
 * it, and this must not overrule them. Only the old shipped value is touched.
 * If an operator genuinely wanted paid-by-default they can set it back in
 * Admin → Settings, which is also why this is not reversed on rollback: by
 * then the value may be a considered choice rather than the one written here.
 *
 * ── It is safe to run before PlanSeeder ─────────────────────────────────────
 * Migrations run before seeders, so on a fresh install — and on a server where
 * `deploy.sh` has migrated but the operator has not re-seeded yet — no plan
 * with the slug 'free' exists. That is fine, and is why nothing here checks:
 * AccountProvisioner::defaultPlan() falls through an unresolvable slug to the
 * is_default flag and then to the cheapest active plan, so the worst case is
 * the behaviour that was already in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->whereNull('account_id')
            ->where('group', 'system')
            ->where('key', 'default_plan_slug')
            ->where('value', 'starter')
            ->update(['value' => 'free', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Deliberately empty. See the note above.
    }
};
