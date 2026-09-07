<?php

use App\Http\Controllers\Smtp\SmtpAccountController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SMTP accounts (Phase 4) — tenant side
|--------------------------------------------------------------------------
| Mounted from routes/web.php inside the tenant group, so auth, verified,
| account.active, has.account and the bound tenant are already applied.
|
| The route names match what the sidebar already expects: smtp.index.
| Literal paths are declared before {wildcard} ones.
*/

Route::get('smtp', [SmtpAccountController::class, 'index'])
    ->middleware('permission:smtp.view')->name('smtp.index');

Route::get('smtp/create', [SmtpAccountController::class, 'create'])
    ->middleware('permission:smtp.manage')->name('smtp.create');
Route::post('smtp', [SmtpAccountController::class, 'store'])
    ->middleware('permission:smtp.manage')->name('smtp.store');

Route::get('smtp/{smtpAccount}', [SmtpAccountController::class, 'show'])
    ->middleware('permission:smtp.view')->name('smtp.show');
Route::get('smtp/{smtpAccount}/edit', [SmtpAccountController::class, 'edit'])
    ->middleware('permission:smtp.manage')->name('smtp.edit');
Route::put('smtp/{smtpAccount}', [SmtpAccountController::class, 'update'])
    ->middleware('permission:smtp.manage')->name('smtp.update');

// A real handshake against the provider; returns JSON so the form can report
// without a page reload.
Route::post('smtp/{smtpAccount}/test', [SmtpAccountController::class, 'test'])
    ->middleware('permission:smtp.manage')->name('smtp.test');

Route::patch('smtp/{smtpAccount}/status', [SmtpAccountController::class, 'toggleStatus'])
    ->middleware('permission:smtp.manage')->name('smtp.status');
Route::post('smtp/{smtpAccount}/reset-cooldown', [SmtpAccountController::class, 'resetCooldown'])
    ->middleware('permission:smtp.manage')->name('smtp.reset-cooldown');

Route::delete('smtp/{smtpAccount}', [SmtpAccountController::class, 'destroy'])
    ->middleware('permission:smtp.manage')->name('smtp.destroy');
