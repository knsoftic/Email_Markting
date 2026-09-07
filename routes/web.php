<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'))->name('home');

// Public campaign links (unsubscribe, preferences). No auth by design:
// an unsubscribe link that requires a login is not an unsubscribe link.
require __DIR__.'/public.php';

/*
|--------------------------------------------------------------------------
| Account (tenant) routes
|--------------------------------------------------------------------------
| Everything behind this group runs with the tenant bound by SetTenant and
| the account/user suspension check applied. Module route files are required
| in as each phase lands.
*/
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Profile ----------------------------------------------------------
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar'])->name('profile.avatar.delete');
    // Throttled like the guest password forms: this one takes the current
    // password, so it is a place a borrowed session can be used to guess it.
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
        ->middleware('throttle:6,1')
        ->name('profile.password');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Contacts module (Phase 3) ----------------------------------------
    Route::middleware('has.account')->group(base_path('routes/contacts.php'));

    // SMTP accounts (Phase 4) ------------------------------------------
    Route::middleware('has.account')->group(base_path('routes/smtp.php'));

    // Templates & campaigns (Phase 5) ----------------------------------
    Route::middleware('has.account')->group(base_path('routes/campaigns.php'));

    // Tracking & analytics (Phase 6) -----------------------------------
    Route::middleware('has.account')->group(base_path('routes/analytics.php'));

    // Mailboxes & IMAP sync (Phase 7) ----------------------------------
    Route::middleware('has.account')->group(base_path('routes/mailboxes.php'));

    // Inbox & composer (Phase 8) ---------------------------------------
    Route::middleware('has.account')->group(base_path('routes/inbox.php'));

    // Global search (Phase 11.4) ----------------------------------------
    // No permission slug here: GlobalSearch decides group by group what this
    // user may look at. A slug on the route would have to be the union of every
    // module's — refusing somebody who can see contacts because they cannot see
    // the inbox — or the intersection, which is nothing at all.
    Route::middleware('has.account')->get('search', [\App\Http\Controllers\SearchController::class, 'index'])
        ->name('search.index');

    // In-app notifications (Phase 10.5) ---------------------------------
    Route::middleware('has.account')->group(base_path('routes/notifications.php'));

    // Automations (Phase 10) -------------------------------------------
    Route::middleware('has.account')->group(base_path('routes/automations.php'));

    // Email log (Phase 10) ---------------------------------------------
    Route::middleware('has.account')->group(base_path('routes/logs.php'));

    // Team & account roles ---------------------------------------------
    Route::middleware(['has.account', 'permission:team.manage'])->group(function () {
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::get('/team/create', [TeamController::class, 'create'])->name('team.create');
        Route::post('/team', [TeamController::class, 'store'])->name('team.store');
        Route::get('/team/{user}/edit', [TeamController::class, 'edit'])->name('team.edit');
        Route::put('/team/{user}', [TeamController::class, 'update'])->name('team.update');
        Route::patch('/team/{user}/status', [TeamController::class, 'toggleStatus'])->name('team.status');
        Route::delete('/team/{user}', [TeamController::class, 'destroy'])->name('team.destroy');

        Route::get('/team/roles/create', [TeamController::class, 'createRole'])->name('team.roles.create');
        Route::post('/team/roles', [TeamController::class, 'storeRole'])->name('team.roles.store');
        Route::get('/team/roles/{role}/edit', [TeamController::class, 'editRole'])->name('team.roles.edit');
        Route::put('/team/roles/{role}', [TeamController::class, 'updateRole'])->name('team.roles.update');
        Route::delete('/team/roles/{role}', [TeamController::class, 'destroyRole'])->name('team.roles.destroy');
    });
});

/*
| Ends an impersonation session. Deliberately outside the super.admin group:
| while impersonating, the signed-in user is the customer, not the admin.
*/
Route::post('/stop-impersonating', [AdminUserController::class, 'stopImpersonating'])
    ->middleware('auth')
    ->name('impersonate.stop');

/*
|--------------------------------------------------------------------------
| Super Admin panel
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified', 'super.admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(base_path('routes/admin.php'));

require __DIR__.'/auth.php';
