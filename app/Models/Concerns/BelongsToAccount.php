<?php

namespace App\Models\Concerns;

use App\Models\Account;
use App\Models\Scopes\AccountScope;
use App\Support\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model. It does two things:
 *
 *  1. Adds the AccountScope so reads can never cross an account boundary.
 *  2. Stamps account_id on create, so a developer forgetting to set it
 *     cannot accidentally write an unowned row.
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);

        static::creating(function ($model) {
            if ($model->account_id === null && $model->shouldStampAccountId()) {
                $model->account_id = app(TenantManager::class)->id();
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Models that also expose shared rows (account_id = NULL) override this.
     */
    public function accountScopeIncludesGlobal(): bool
    {
        return false;
    }

    /**
     * Whether this row should inherit the bound tenant on create.
     *
     * A model with genuinely platform-owned rows overrides this and returns
     * false for them — otherwise creating one while any tenant happens to be
     * bound silently turns it into that tenant's private row.
     */
    public function shouldStampAccountId(): bool
    {
        return true;
    }

    /**
     * Escape hatch for super-admin screens and console commands. Never call
     * this from a controller reachable by an account user.
     */
    public function scopeAcrossAccounts(Builder $query): Builder
    {
        return $query->withoutGlobalScope(AccountScope::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->withoutGlobalScope(AccountScope::class)
            ->where($this->qualifyColumn('account_id'), $accountId);
    }
}
