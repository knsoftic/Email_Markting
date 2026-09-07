<?php

use App\Http\Controllers\Contacts\CustomFieldController;
use App\Http\Controllers\Contacts\ExportController;
use App\Http\Controllers\Contacts\ImportController;
use App\Http\Controllers\Contacts\SegmentController;
use App\Http\Controllers\Contacts\SubscriberController;
use App\Http\Controllers\Contacts\SubscriberListController;
use App\Http\Controllers\Contacts\SuppressionController;
use App\Http\Controllers\Contacts\TagController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Contacts module (Phase 3)
|--------------------------------------------------------------------------
| Mounted from routes/web.php inside the tenant group, so every route here
| already has: auth, verified, account.active, has.account and a bound tenant.
| Permission slugs come from PermissionSeeder's "contacts" group.
|
| ORDERING RULE: within each resource, every literal path is declared before
| the {wildcard} one — otherwise /segments/create is captured by
| /segments/{segment} and route-model binding 404s on the word "create".
*/

// ============================================================= subscribers
Route::get('subscribers', [SubscriberController::class, 'index'])
    ->middleware('permission:contacts.view')->name('subscribers.index');

Route::get('subscribers/create', [SubscriberController::class, 'create'])
    ->middleware('permission:contacts.create')->name('subscribers.create');
Route::post('subscribers', [SubscriberController::class, 'store'])
    ->middleware('permission:contacts.create')->name('subscribers.store');

// One endpoint for every multi-row action; the action name is validated
// against an allow-list in the controller.
Route::post('subscribers/bulk', [SubscriberController::class, 'bulk'])
    ->middleware('permission:contacts.update')->name('subscribers.bulk');

Route::get('subscribers/{subscriber}', [SubscriberController::class, 'show'])
    ->middleware('permission:contacts.view')->name('subscribers.show');
Route::get('subscribers/{subscriber}/edit', [SubscriberController::class, 'edit'])
    ->middleware('permission:contacts.update')->name('subscribers.edit');
Route::put('subscribers/{subscriber}', [SubscriberController::class, 'update'])
    ->middleware('permission:contacts.update')->name('subscribers.update');
Route::delete('subscribers/{subscriber}', [SubscriberController::class, 'destroy'])
    ->middleware('permission:contacts.delete')->name('subscribers.destroy');

// ============================================================ custom fields
Route::get('custom-fields', [CustomFieldController::class, 'index'])
    ->middleware('permission:contacts.view')->name('custom-fields.index');
Route::post('custom-fields', [CustomFieldController::class, 'store'])
    ->middleware('permission:contacts.update')->name('custom-fields.store');
Route::put('custom-fields/{customField}', [CustomFieldController::class, 'update'])
    ->middleware('permission:contacts.update')->name('custom-fields.update');
Route::delete('custom-fields/{customField}', [CustomFieldController::class, 'destroy'])
    ->middleware('permission:contacts.delete')->name('custom-fields.destroy');

// =================================================================== lists
Route::get('lists', [SubscriberListController::class, 'index'])
    ->middleware('permission:contacts.view')->name('lists.index');

Route::get('lists/create', [SubscriberListController::class, 'create'])
    ->middleware('permission:contacts.create')->name('lists.create');
Route::post('lists', [SubscriberListController::class, 'store'])
    ->middleware('permission:contacts.create')->name('lists.store');

Route::get('lists/{list}', [SubscriberListController::class, 'show'])
    ->middleware('permission:contacts.view')->name('lists.show');
Route::get('lists/{list}/edit', [SubscriberListController::class, 'edit'])
    ->middleware('permission:contacts.update')->name('lists.edit');
Route::put('lists/{list}', [SubscriberListController::class, 'update'])
    ->middleware('permission:contacts.update')->name('lists.update');
Route::delete('lists/{list}/subscribers/{subscriber}', [SubscriberListController::class, 'detach'])
    ->middleware('permission:contacts.update')->name('lists.detach');
Route::delete('lists/{list}', [SubscriberListController::class, 'destroy'])
    ->middleware('permission:contacts.delete')->name('lists.destroy');

// ==================================================================== tags
Route::get('tags', [TagController::class, 'index'])
    ->middleware('permission:contacts.view')->name('tags.index');
Route::post('tags', [TagController::class, 'store'])
    ->middleware('permission:contacts.create')->name('tags.store');

Route::get('tags/{tag}', [TagController::class, 'show'])
    ->middleware('permission:contacts.view')->name('tags.show');
Route::put('tags/{tag}', [TagController::class, 'update'])
    ->middleware('permission:contacts.update')->name('tags.update');
Route::delete('tags/{tag}', [TagController::class, 'destroy'])
    ->middleware('permission:contacts.delete')->name('tags.destroy');

// ================================================================ segments
Route::get('segments', [SegmentController::class, 'index'])
    ->middleware('permission:contacts.view')->name('segments.index');

Route::get('segments/create', [SegmentController::class, 'create'])
    ->middleware('permission:contacts.create')->name('segments.create');
Route::post('segments', [SegmentController::class, 'store'])
    ->middleware('permission:contacts.create')->name('segments.store');

// Live count / sample rows for the rule builder. POST because the rule set is
// a JSON body, not a query string.
Route::post('segments/preview', [SegmentController::class, 'preview'])
    ->middleware('permission:contacts.view')->name('segments.preview');

Route::get('segments/{segment}', [SegmentController::class, 'show'])
    ->middleware('permission:contacts.view')->name('segments.show');
Route::get('segments/{segment}/edit', [SegmentController::class, 'edit'])
    ->middleware('permission:contacts.update')->name('segments.edit');
Route::put('segments/{segment}', [SegmentController::class, 'update'])
    ->middleware('permission:contacts.update')->name('segments.update');
Route::post('segments/{segment}/recalculate', [SegmentController::class, 'recalculate'])
    ->middleware('permission:contacts.update')->name('segments.recalculate');
Route::delete('segments/{segment}', [SegmentController::class, 'destroy'])
    ->middleware('permission:contacts.delete')->name('segments.destroy');

// ================================================================= imports
Route::middleware('permission:contacts.import')->group(function () {
    Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
    Route::get('imports/create', [ImportController::class, 'create'])->name('imports.create');
    Route::post('imports', [ImportController::class, 'store'])->name('imports.store');

    Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::get('imports/{import}/map', [ImportController::class, 'map'])->name('imports.map');
    Route::post('imports/{import}/map', [ImportController::class, 'saveMapping'])->name('imports.map.save');
    Route::post('imports/{import}/start', [ImportController::class, 'start'])->name('imports.start');
    // Polled by the progress screen; returns JSON, never a view.
    Route::get('imports/{import}/progress', [ImportController::class, 'progress'])->name('imports.progress');
    Route::get('imports/{import}/errors', [ImportController::class, 'downloadErrors'])->name('imports.errors');
    Route::post('imports/{import}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');
    Route::delete('imports/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');
});

// ================================================================= exports
Route::middleware('permission:contacts.export')->group(function () {
    Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
    Route::post('exports', [ExportController::class, 'download'])->name('exports.download');
});

// ============================================================ suppressions
Route::get('suppressions', [SuppressionController::class, 'index'])
    ->middleware('permission:contacts.view')->name('suppressions.index');
Route::get('suppressions/export', [SuppressionController::class, 'export'])
    ->middleware('permission:contacts.export')->name('suppressions.export');
Route::post('suppressions', [SuppressionController::class, 'store'])
    ->middleware('permission:contacts.update')->name('suppressions.store');
Route::post('suppressions/bulk', [SuppressionController::class, 'bulk'])
    ->middleware('permission:contacts.update')->name('suppressions.bulk');
Route::delete('suppressions/{suppression}', [SuppressionController::class, 'destroy'])
    ->middleware('permission:contacts.delete')->name('suppressions.destroy');
