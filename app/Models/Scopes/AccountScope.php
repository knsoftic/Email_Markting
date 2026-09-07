<?php

namespace App\Models\Scopes;

use App\Support\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant-owned model to the active account.
 *
 * Models whose account_id may be NULL to mean "shared with everyone"
 * (system templates, admin SMTP, system roles, platform settings) opt in to
 * also seeing those rows via accountScopeIncludesGlobal().
 */
class AccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantManager::class);

        if (! $tenant->check()) {
            return;
        }

        $column = $model->qualifyColumn('account_id');
        $accountId = $tenant->id();

        if (method_exists($model, 'accountScopeIncludesGlobal') && $model->accountScopeIncludesGlobal()) {
            $builder->where(function (Builder $query) use ($column, $accountId) {
                $query->where($column, $accountId)->orWhereNull($column);
            });

            return;
        }

        $builder->where($column, $accountId);
    }
}
