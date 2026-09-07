<?php

use App\Http\Controllers\Imap\MailboxController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mailboxes & IMAP sync (Phase 7)
|--------------------------------------------------------------------------
| Mounted from routes/web.php inside the tenant group. The sidebar already
| expects mailboxes.index.
|
| Literal paths before {wildcard} ones, so "create" is never parsed as an id.
*/

Route::get('mailboxes', [MailboxController::class, 'index'])
    ->middleware('permission:mailboxes.view')->name('mailboxes.index');

Route::get('mailboxes/create', [MailboxController::class, 'create'])
    ->middleware('permission:mailboxes.manage')->name('mailboxes.create');
Route::post('mailboxes', [MailboxController::class, 'store'])
    ->middleware('permission:mailboxes.manage')->name('mailboxes.store');

Route::get('mailboxes/{mailbox}', [MailboxController::class, 'show'])
    ->middleware('permission:mailboxes.view')->name('mailboxes.show');

Route::get('mailboxes/{mailbox}/edit', [MailboxController::class, 'edit'])
    ->middleware('permission:mailboxes.manage')->name('mailboxes.edit');
Route::put('mailboxes/{mailbox}', [MailboxController::class, 'update'])
    ->middleware('permission:mailboxes.manage')->name('mailboxes.update');

// A real connection attempt. POST because it changes the stored health state.
Route::post('mailboxes/{mailbox}/test', [MailboxController::class, 'test'])
    ->middleware('permission:mailboxes.manage')->name('mailboxes.test');

Route::post('mailboxes/{mailbox}/sync', [MailboxController::class, 'sync'])
    ->middleware('permission:mailboxes.view')->name('mailboxes.sync');

Route::post('mailboxes/{mailbox}/resume', [MailboxController::class, 'resume'])
    ->middleware('permission:mailboxes.manage')->name('mailboxes.resume');

Route::post('mailboxes/{mailbox}/toggle', [MailboxController::class, 'toggleStatus'])
    ->middleware('permission:mailboxes.manage')->name('mailboxes.toggle');

Route::delete('mailboxes/{mailbox}', [MailboxController::class, 'destroy'])
    ->middleware('permission:mailboxes.manage')->name('mailboxes.destroy');
