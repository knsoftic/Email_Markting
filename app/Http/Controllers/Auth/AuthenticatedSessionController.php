<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        // A suspended login is rejected here rather than after the redirect,
        // so a blocked user never gets an authenticated session at all.
        if ($user->isSuspended() || ($user->account && ! $user->account->isActive())) {
            $message = $user->isSuspended()
                ? 'Your user account has been suspended. Please contact support.'
                : 'This account is currently suspended. Please contact support.';

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => $message]);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        ActivityLogger::log('auth.login', "{$user->name} logged in");

        // Super admins land in the platform panel once Phase 2 registers it;
        // until then everyone lands on the account dashboard.
        $target = $user->isSuperAdmin() && Route::has('admin.dashboard')
            ? route('admin.dashboard', absolute: false)
            : route('dashboard', absolute: false);

        return redirect()->intended($target);
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            ActivityLogger::log('auth.logout', "{$user->name} logged out");
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
