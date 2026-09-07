<?php

namespace App\Support;

use Closure;

/**
 * Holds the account (tenant) the current request or job is acting for.
 *
 * Web requests get it from the authenticated user via the SetTenant
 * middleware. Queued jobs must set it explicitly, because there is no session
 * inside a worker — every job that touches tenant data carries its own
 * account id and calls TenantManager::set() (or runAs()) before querying.
 *
 * When no tenant is set the AccountScope does not filter at all. That is
 * deliberate: it is the mode super admins and console commands run in.
 */
class TenantManager
{
    protected ?int $accountId = null;

    public function set(?int $accountId): static
    {
        $this->accountId = $accountId;

        return $this;
    }

    public function id(): ?int
    {
        return $this->accountId;
    }

    public function check(): bool
    {
        return $this->accountId !== null;
    }

    public function forget(): static
    {
        $this->accountId = null;

        return $this;
    }

    /**
     * Run a callback scoped to a specific account, then restore whatever
     * tenant was active before. Used by jobs, imports and the super admin
     * when acting inside one account.
     */
    public function runAs(?int $accountId, Closure $callback): mixed
    {
        $previous = $this->accountId;
        $this->accountId = $accountId;

        try {
            return $callback();
        } finally {
            $this->accountId = $previous;
        }
    }

    /**
     * Run a callback with no tenant filtering at all.
     */
    public function withoutTenant(Closure $callback): mixed
    {
        return $this->runAs(null, $callback);
    }
}
