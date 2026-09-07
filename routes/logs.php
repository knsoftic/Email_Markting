<?php

use App\Http\Controllers\Logs\EmailLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email log (Phase 10.6)
|--------------------------------------------------------------------------
| Mounted from routes/web.php inside the tenant group. The sidebar already
| expects logs.index behind permission logs.view.
|
| The whole module is read-only: a sending log that can be edited is not a
| log. There is deliberately no delete and no "clear" — rows leave only with
| the account.
|
| Literal paths are declared before {wildcard} ones, so "export" is never
| parsed as a log id.
*/

Route::middleware('permission:logs.view')->group(function () {
    Route::get('logs', [EmailLogController::class, 'index'])->name('logs.index');

    Route::get('logs/export', [EmailLogController::class, 'export'])->name('logs.export');

    Route::get('logs/{log}', [EmailLogController::class, 'show'])->name('logs.show');
});
