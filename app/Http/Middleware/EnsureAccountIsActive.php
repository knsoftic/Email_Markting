<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a suspended user, or a user whose whole account has been suspended,
 * from reaching any tenant screen. Super admins are exempt.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        if ($user->isSuspended()) {
            return $this->reject($request, 'Your user account has been suspended. Please contact support.');
        }

        if (! $user->account) {
            return $this->reject($request, 'No account is linked to this login. Please contact support.');
        }

        if (! $user->account->isActive()) {
            return $this->reject($request, 'This account is currently suspended. Please contact KN Softic support.');
        }

        return $next($request);
    }

    protected function reject(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
