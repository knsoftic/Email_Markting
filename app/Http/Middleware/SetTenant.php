<?php

namespace App\Http\Middleware;

use App\Support\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's account to the TenantManager for the rest of
 * the request, which is what makes the AccountScope filter every query.
 *
 * The tenant is set on EVERY request, including to null for guests and super
 * admins. That matters because TenantManager is a container singleton: in any
 * long-lived process (a queue worker, Octane, the test suite) an app instance
 * is reused across requests, so leaving a previous request's tenant in place
 * would let the next one read — or worse, stamp new rows with — the wrong
 * account. Setting it unconditionally makes the value deterministic.
 */
class SetTenant
{
    public function __construct(protected TenantManager $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $this->tenant->set(
            $user && ! $user->isSuperAdmin() ? $user->account_id : null
        );

        return $next($request);
    }
}
