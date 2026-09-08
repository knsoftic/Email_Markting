<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SmtpAccountController as AdminSmtpController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| KN Softic Super Admin Panel
|--------------------------------------------------------------------------
| Guarded by super.admin, so no account user can reach any of it. Super admins
| run with no tenant bound, which is why the controllers read across accounts
| explicitly with withoutGlobalScopes().
*/

Route::get('/', DashboardController::class)->name('dashboard');

// Plans -----------------------------------------------------------------
Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
Route::get('plans/create', [PlanController::class, 'create'])->name('plans.create');
Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
Route::post('plans/{plan}/duplicate', [PlanController::class, 'duplicate'])->name('plans.duplicate');
Route::delete('plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');

// Users -----------------------------------------------------------------
Route::get('users', [UserController::class, 'index'])->name('users.index');
Route::get('users/create', [UserController::class, 'create'])->name('users.create');
Route::post('users', [UserController::class, 'store'])->name('users.store');
Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
Route::patch('users/{user}/status', [UserController::class, 'toggleStatus'])->name('users.status');
Route::post('users/{user}/impersonate', [UserController::class, 'impersonate'])->name('users.impersonate');
Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

// Accounts --------------------------------------------------------------
Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
Route::put('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
Route::patch('accounts/{account}/status', [AccountController::class, 'toggleStatus'])->name('accounts.status');
Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

// Subscriptions ---------------------------------------------------------
Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
Route::post('accounts/{account}/subscription', [SubscriptionController::class, 'store'])->name('subscriptions.store');
Route::put('subscriptions/{subscription}', [SubscriptionController::class, 'update'])->name('subscriptions.update');
Route::put('subscriptions/{subscription}/overrides', [SubscriptionController::class, 'overrides'])->name('subscriptions.overrides');

// Roles & permissions ---------------------------------------------------
Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

// Settings --------------------------------------------------------------
Route::get('settings/branding', [SettingController::class, 'branding'])->name('settings.branding');
Route::put('settings/branding', [SettingController::class, 'updateBranding'])->name('settings.branding.update');
Route::get('settings/system', [SettingController::class, 'system'])->name('settings.system');
Route::put('settings/system', [SettingController::class, 'updateSystem'])->name('settings.system.update');
Route::get('settings/payment', [SettingController::class, 'payment'])->name('settings.payment');
Route::put('settings/payment', [SettingController::class, 'updatePayment'])->name('settings.payment.update');

// System ----------------------------------------------------------------
Route::get('system/health', [SystemController::class, 'healthReport'])->name('system.health');
Route::get('system/queue', [SystemController::class, 'queue'])->name('system.queue');
Route::post('system/queue/retry', [SystemController::class, 'retryFailed'])->name('system.queue.retry');
Route::post('system/queue/flush', [SystemController::class, 'flushFailed'])->name('system.queue.flush');
Route::post('system/cache/clear', [SystemController::class, 'clearCaches'])->name('system.cache.clear');

// Activity --------------------------------------------------------------
Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

// Admin (global) SMTP ---------------------------------------------------
// account_id = NULL rows, shared with tenants through smtp_assignments.
Route::get('smtp', [AdminSmtpController::class, 'index'])->name('smtp.index');
Route::get('smtp/create', [AdminSmtpController::class, 'create'])->name('smtp.create');
Route::post('smtp', [AdminSmtpController::class, 'store'])->name('smtp.store');
Route::get('smtp/{smtpAccount}', [AdminSmtpController::class, 'show'])->name('smtp.show');
Route::get('smtp/{smtpAccount}/edit', [AdminSmtpController::class, 'edit'])->name('smtp.edit');
Route::put('smtp/{smtpAccount}', [AdminSmtpController::class, 'update'])->name('smtp.update');
Route::put('smtp/{smtpAccount}/assignments', [AdminSmtpController::class, 'updateAssignments'])->name('smtp.assignments');
Route::post('smtp/{smtpAccount}/test', [AdminSmtpController::class, 'test'])->name('smtp.test');
Route::patch('smtp/{smtpAccount}/status', [AdminSmtpController::class, 'toggleStatus'])->name('smtp.status');
Route::post('smtp/{smtpAccount}/reset-cooldown', [AdminSmtpController::class, 'resetCooldown'])->name('smtp.reset-cooldown');
Route::delete('smtp/{smtpAccount}', [AdminSmtpController::class, 'destroy'])->name('smtp.destroy');

/*
|--------------------------------------------------------------------------
| The operator's guide
|--------------------------------------------------------------------------
| Behind the same super.admin gate as the rest of this file, and deliberately
| so: it explains how to suspend an account, how to sign in as a customer, and
| which buttons cannot be undone. That is an internal document, not a public
| one — the customer-facing guide lives at /guide and is open to anybody.
|
| A plain file rather than a Blade view: the guide's own text contains
| {{first_name}} and {{company_name}} as example placeholders, and Blade would
| try to evaluate them.
*/
Route::get('guide', function () {
    $path = public_path('admin-guide.html');

    abort_unless(is_file($path), 404);

    return response(file_get_contents($path), 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]);
})->name('guide');
