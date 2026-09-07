<?php

use App\Http\Controllers\Analytics\AnalyticsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Analytics (Phase 6)
|--------------------------------------------------------------------------
| Mounted from routes/web.php inside the tenant group. The sidebar already
| expects analytics.index.
|
| Everything here is read-only except `rebuild`, which recomputes a campaign's
| counters from the events already recorded — it changes no history, only the
| cached totals, which is why it sits behind analytics.view rather than a
| campaign-editing permission.
*/

Route::get('analytics', [AnalyticsController::class, 'index'])
    ->middleware('permission:analytics.view')->name('analytics.index');

Route::get('analytics/campaigns/{campaign}', [AnalyticsController::class, 'campaign'])
    ->middleware('permission:analytics.view')->name('analytics.campaign');

Route::get('analytics/campaigns/{campaign}/export', [AnalyticsController::class, 'export'])
    ->middleware('permission:analytics.view')->name('analytics.export');

Route::post('analytics/campaigns/{campaign}/rebuild', [AnalyticsController::class, 'rebuild'])
    ->middleware('permission:analytics.view')->name('analytics.rebuild');
