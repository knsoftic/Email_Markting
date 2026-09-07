<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEmailIsVerifiedWhenRequired;
use App\Http\Middleware\EnsureHasAccount;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SetTenant runs on every web request, right after the session is
        // available, so the AccountScope is armed before any query runs.
        //
        // SecurityHeaders runs last so it sees the finished response, including
        // the stricter policy the three email-preview routes set for
        // themselves — which it deliberately leaves alone.
        $middleware->web(append: [
            SetTenant::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'tenant' => SetTenant::class,
            'account.active' => EnsureAccountIsActive::class,
            'super.admin' => EnsureSuperAdmin::class,
            'permission' => EnsurePermission::class,
            'has.account' => EnsureHasAccount::class,
            'verified' => EnsureEmailIsVerifiedWhenRequired::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
