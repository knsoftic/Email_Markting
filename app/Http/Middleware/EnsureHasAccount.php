<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards tenant-only screens. A super admin has no account of their own, so
 * account modules (team, contacts, campaigns, inbox…) are not theirs to open —
 * they use the admin panel, or impersonate a user to see an account's view.
 */
class EnsureHasAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->account_id === null) {
            return redirect()
                ->route($user->isSuperAdmin() ? 'admin.dashboard' : 'dashboard')
                ->with('warning', 'That section belongs to a customer account. Use the admin panel, or sign in as one of its users.');
        }

        return $next($request);
    }
}
