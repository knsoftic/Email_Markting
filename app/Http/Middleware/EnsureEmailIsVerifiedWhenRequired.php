<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Email verification, but switchable from Super Admin → System Settings.
 *
 * When "require_email_verification" is on this behaves exactly like Laravel's
 * `verified` middleware; when an admin turns it off, unverified users can
 * still work. Super admins are always exempt.
 */
class EnsureEmailIsVerifiedWhenRequired
{
    public function __construct(protected SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $this->settings->get('system', 'require_email_verification', '1')) {
            return $next($request);
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? abort(403, 'Your email address is not verified.')
                : redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
