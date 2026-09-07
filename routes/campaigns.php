<?php

use App\Http\Controllers\Campaigns\CampaignController;
use App\Http\Controllers\Campaigns\CampaignVariantController;
use App\Http\Controllers\Campaigns\EmailTemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Templates and campaigns (Phase 5)
|--------------------------------------------------------------------------
| Mounted from routes/web.php inside the tenant group. Route names match what
| the sidebar already expects: templates.index, campaigns.index,
| campaigns.create, campaigns.scheduled.
|
| Literal paths are declared before {wildcard} ones throughout.
*/

// =============================================================== templates
Route::get('templates', [EmailTemplateController::class, 'index'])
    ->middleware('permission:templates.view')->name('templates.index');

Route::get('templates/create', [EmailTemplateController::class, 'create'])
    ->middleware('permission:templates.create')->name('templates.create');
Route::post('templates', [EmailTemplateController::class, 'store'])
    ->middleware('permission:templates.create')->name('templates.store');

// Live compile for the builder. POST because the document is a JSON body.
// Both editors use it, and a role can hold campaigns.update without
// templates.view — gating on templates alone would break the campaign
// preview for that role. campaigns.create is on the list for the same
// reason: campaigns/create is gated on that slug alone, so a role holding
// only it can open the new-campaign builder, and without it here every
// preview on that screen would answer 403 and never render. It matches the
// list on templates.document below, which the same screen also calls.
Route::post('templates/compile', [EmailTemplateController::class, 'compile'])
    ->middleware('permission:templates.view,campaigns.view,campaigns.create,campaigns.update')
    ->name('templates.compile');

Route::get('templates/{template}/edit', [EmailTemplateController::class, 'edit'])
    ->middleware('permission:templates.update')->name('templates.edit');
Route::put('templates/{template}', [EmailTemplateController::class, 'update'])
    ->middleware('permission:templates.update')->name('templates.update');
Route::post('templates/{template}/duplicate', [EmailTemplateController::class, 'duplicate'])
    ->middleware('permission:templates.create')->name('templates.duplicate');

// The rendered email, served standalone for a sandboxed iframe.
Route::get('templates/{template}/preview', [EmailTemplateController::class, 'preview'])
    ->middleware('permission:templates.view')->name('templates.preview');

// The block document as JSON, so the campaign builder can load a template's
// content. Reachable by campaign editors too — that is the whole point of it.
Route::get('templates/{template}/document', [EmailTemplateController::class, 'document'])
    ->middleware('permission:templates.view,campaigns.create,campaigns.update')
    ->name('templates.document');

Route::delete('templates/{template}', [EmailTemplateController::class, 'destroy'])
    ->middleware('permission:templates.delete')->name('templates.destroy');

// =============================================================== campaigns
Route::get('campaigns', [CampaignController::class, 'index'])
    ->middleware('permission:campaigns.view')->name('campaigns.index');
Route::get('campaigns/scheduled', [CampaignController::class, 'scheduled'])
    ->middleware('permission:campaigns.view')->name('campaigns.scheduled');

Route::get('campaigns/create', [CampaignController::class, 'create'])
    ->middleware('permission:campaigns.create')->name('campaigns.create');
Route::post('campaigns', [CampaignController::class, 'store'])
    ->middleware('permission:campaigns.create')->name('campaigns.store');

Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])
    ->middleware('permission:campaigns.view')->name('campaigns.show');
Route::get('campaigns/{campaign}/preview', [CampaignController::class, 'preview'])
    ->middleware('permission:campaigns.view')->name('campaigns.preview');
Route::get('campaigns/{campaign}/progress', [CampaignController::class, 'progress'])
    ->middleware('permission:campaigns.view')->name('campaigns.progress');

Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])
    ->middleware('permission:campaigns.update')->name('campaigns.edit');
Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])
    ->middleware('permission:campaigns.update')->name('campaigns.update');
Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate'])
    ->middleware('permission:campaigns.create')->name('campaigns.duplicate');

// The confirmation screen stands between the button and a real send.
Route::get('campaigns/{campaign}/confirm', [CampaignController::class, 'confirm'])
    ->middleware('permission:campaigns.send')->name('campaigns.confirm');

Route::middleware('permission:campaigns.send')->group(function () {
    Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send'])->name('campaigns.send');
    Route::post('campaigns/{campaign}/schedule', [CampaignController::class, 'schedule'])->name('campaigns.schedule');
    Route::post('campaigns/{campaign}/unschedule', [CampaignController::class, 'unschedule'])->name('campaigns.unschedule');
    Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
    Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('campaigns.resume');
    Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
    Route::post('campaigns/{campaign}/test', [CampaignController::class, 'test'])->name('campaigns.test');
});

Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])
    ->middleware('permission:campaigns.delete')->name('campaigns.destroy');

// ========================================================= split tests (10.4)
// Appended for the A/B UI. The service (AbTestService) already assigns the
// sample, computes the rates and picks the winner; these routes only write the
// configuration down and offer the one manual override — ending a test early on
// a version the sender has decided to back.
//
// The versions are saved as one set with the settings, not one row at a time:
// the shares, the sample and the versions only mean anything together, and a
// per-row endpoint would let a campaign sit in a state — one version, or shares
// adding up to nothing — that the sender would have to guess a way out of.

Route::put('campaigns/{campaign}/split-test', [CampaignVariantController::class, 'update'])
    ->middleware('permission:campaigns.update')->name('campaigns.ab.update');

// Releasing the holdback sends real email, so this sits behind campaigns.send
// rather than campaigns.update.
Route::post('campaigns/{campaign}/split-test/decide', [CampaignVariantController::class, 'decide'])
    ->middleware('permission:campaigns.send')->name('campaigns.ab.decide');
