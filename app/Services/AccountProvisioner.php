<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a tenant and its owner in one transaction.
 *
 * Used by public registration and by the super admin's "create user" screen,
 * so both paths produce an identical, fully set-up account: owner role,
 * default plan subscription and a usage counter for the current period.
 */
class AccountProvisioner
{
    public function __construct(
        protected SettingsService $settings,
        protected TenantManager $tenant,
    ) {}

    /**
     * @param  array{company_name:string,name:string,email:string,password:string,timezone?:string,plan_id?:int|null,status?:string}  $data
     */
    public function provision(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $account = Account::create([
                'name' => $data['company_name'],
                'slug' => $this->uniqueSlug($data['company_name']),
                'company_name' => $data['company_name'],
                'timezone' => $data['timezone'] ?? 'UTC',
                'status' => $data['status'] ?? 'active',
            ]);

            $ownerRole = Role::withoutGlobalScopes()
                ->where('slug', Role::OWNER)
                ->whereNull('account_id')
                ->first();

            $user = User::create([
                'account_id' => $account->id,
                'role_id' => $ownerRole?->id,
                'is_super_admin' => false,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'timezone' => $data['timezone'] ?? 'UTC',
                'status' => $data['status'] ?? 'active',
            ]);

            $account->forceFill(['owner_id' => $user->id])->save();

            $this->attachPlan($account, $data['plan_id'] ?? null);

            UsageCounter::withoutGlobalScopes()->firstOrCreate([
                'account_id' => $account->id,
                'period' => UsageCounter::currentPeriod(),
            ]);

            return $user->setRelation('account', $account);
        });
    }

    /**
     * Gives the account its starting subscription. Falls back through the
     * configured default plan slug, then any plan flagged is_default, then
     * the cheapest active plan.
     */
    public function attachPlan(Account $account, ?int $planId = null): ?Subscription
    {
        $plan = $planId
            ? Plan::find($planId)
            : $this->defaultPlan();

        if (! $plan) {
            return null;
        }

        $trialDays = (int) ($plan->trial_days ?: 0);

        return Subscription::withoutGlobalScopes()->create([
            'account_id' => $account->id,
            'plan_id' => $plan->id,
            'status' => $trialDays > 0 ? 'trial' : 'active',
            'starts_at' => now(),
            'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
            'ends_at' => $plan->billing_period === 'lifetime'
                ? null
                : now()->addDays(max($trialDays, 30)),
            'price_paid' => 0,
            'currency' => $plan->currency,
        ]);
    }

    public function defaultPlan(): ?Plan
    {
        $slug = (string) $this->settings->get('system', 'default_plan_slug', 'starter');

        return Plan::active()->where('slug', $slug)->first()
            ?? Plan::active()->where('is_default', true)->first()
            ?? Plan::active()->ordered()->first();
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'account';
        $slug = $base;
        $i = 1;

        while (Account::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
