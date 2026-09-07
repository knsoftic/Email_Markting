<?php

use App\Http\Controllers\Automation\AutomationController;
use App\Http\Controllers\Automation\AutomationStepController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Automations (Phase 10)
|--------------------------------------------------------------------------
| Mounted from routes/web.php inside the tenant group. The sidebar already
| expects automations.index.
|
| Literal paths are declared before {wildcard} ones throughout, so "create"
| is never parsed as an automation id and "reorder" is never parsed as a
| step id.
|
| scopeBindings() on the step routes means {step} is resolved through
| $automation->steps(), not by bare id — so a step id from another
| automation (or another tenant, whose automation the AccountScope has
| already refused) is a 404 rather than an edit form for somebody else's
| step.
*/

// =============================================================== automations
Route::get('automations', [AutomationController::class, 'index'])
    ->middleware('permission:automation.view')->name('automations.index');

Route::get('automations/create', [AutomationController::class, 'create'])
    ->middleware('permission:automation.manage')->name('automations.create');
Route::post('automations', [AutomationController::class, 'store'])
    ->middleware('permission:automation.manage')->name('automations.store');

Route::get('automations/{automation}', [AutomationController::class, 'show'])
    ->middleware('permission:automation.view')->name('automations.show');
Route::get('automations/{automation}/runs', [AutomationController::class, 'runs'])
    ->middleware('permission:automation.view')->name('automations.runs');

Route::get('automations/{automation}/edit', [AutomationController::class, 'edit'])
    ->middleware('permission:automation.manage')->name('automations.edit');
Route::put('automations/{automation}', [AutomationController::class, 'update'])
    ->middleware('permission:automation.manage')->name('automations.update');

Route::middleware('permission:automation.manage')->group(function () {
    Route::post('automations/{automation}/activate', [AutomationController::class, 'activate'])
        ->name('automations.activate');
    Route::post('automations/{automation}/pause', [AutomationController::class, 'pause'])
        ->name('automations.pause');
    Route::post('automations/{automation}/duplicate', [AutomationController::class, 'duplicate'])
        ->name('automations.duplicate');
});

Route::delete('automations/{automation}', [AutomationController::class, 'destroy'])
    ->middleware('permission:automation.manage')->name('automations.destroy');

// ===================================================================== steps
Route::middleware('permission:automation.manage')->scopeBindings()->group(function () {
    Route::get('automations/{automation}/steps/create', [AutomationStepController::class, 'create'])
        ->name('automations.steps.create');
    Route::post('automations/{automation}/steps', [AutomationStepController::class, 'store'])
        ->name('automations.steps.store');

    Route::post('automations/{automation}/steps/reorder', [AutomationStepController::class, 'reorder'])
        ->name('automations.steps.reorder');

    Route::get('automations/{automation}/steps/{step}/edit', [AutomationStepController::class, 'edit'])
        ->name('automations.steps.edit');
    Route::put('automations/{automation}/steps/{step}', [AutomationStepController::class, 'update'])
        ->name('automations.steps.update');
    Route::delete('automations/{automation}/steps/{step}', [AutomationStepController::class, 'destroy'])
        ->name('automations.steps.destroy');
});
