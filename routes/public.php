<?php

use App\Http\Controllers\Public\TrackingController;
use App\Http\Controllers\Public\UnsubscribeController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public campaign links (Phase 5)
|--------------------------------------------------------------------------
| Reached from inside an email: no session, no logged-in user, no tenant.
| The URL signature is the only authorisation, so every route here carries the
| `signed` middleware and the controller resolves its rows unscoped.
|
| Mounted OUTSIDE the auth group deliberately — an unsubscribe link that
| requires a login is not an unsubscribe link.
|
| The literal /preview paths are declared first so they are not swallowed by
| the {subscriber} wildcard.
*/

Route::get('unsubscribe/preview', [UnsubscribeController::class, 'preview'])->name('unsubscribe.preview');
Route::get('preferences/preview', [UnsubscribeController::class, 'preview'])->name('preferences.preview');

Route::middleware('signed')->group(function () {
    Route::get('unsubscribe/{subscriber}/{campaign?}', [UnsubscribeController::class, 'show'])
        ->whereNumber(['subscriber', 'campaign'])
        ->name('unsubscribe.show');

    // A GET must never opt anybody out: scanners and mail clients pre-fetch
    // links, and people would be unsubscribed by a robot rather than a choice.
    Route::post('unsubscribe/{subscriber}/{campaign?}', [UnsubscribeController::class, 'confirm'])
        ->whereNumber(['subscriber', 'campaign'])
        ->name('unsubscribe.confirm');

    // A real preferences page, not a second door onto the unsubscribe screen:
    // it lets someone leave one list instead of the sender entirely.
    Route::get('preferences/{subscriber}/{campaign?}', [UnsubscribeController::class, 'preferences'])
        ->whereNumber(['subscriber', 'campaign'])
        ->name('preferences.show');

    Route::post('preferences/{subscriber}/{campaign?}', [UnsubscribeController::class, 'updatePreferences'])
        ->whereNumber(['subscriber', 'campaign'])
        ->name('preferences.update');
});

// RFC 8058 one-click. Mail clients POST here with no browser session, so CSRF
// cannot apply — the URL signature is what protects it.
Route::post('unsubscribe-one-click/{subscriber}/{campaign?}', [UnsubscribeController::class, 'oneClick'])
    ->middleware('signed')
    ->whereNumber(['subscriber', 'campaign'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('unsubscribe.one-click');

/*
|--------------------------------------------------------------------------
| Open and click tracking (Phase 6)
|--------------------------------------------------------------------------
| Reached from inside a delivered email. Short paths on purpose: every tracked
| link in every message carries one, and a long path is bytes multiplied by the
| size of the list -- which also pushes a large campaign closer to Gmail's
| 102 KB clip.
|
| The /t/c/ segment is what TrackingLinkRewriter::isTrackable() looks for, so
| preparing an already-prepared document cannot wrap a tracking link inside
| another tracking link.
*/
Route::middleware('signed')->group(function () {
    Route::get('t/o/{recipient}', [TrackingController::class, 'open'])
        ->whereNumber('recipient')->name('track.open');

    Route::get('t/c/{link}/{recipient}', [TrackingController::class, 'click'])
        ->whereNumber(['link', 'recipient'])->name('track.click');
});
