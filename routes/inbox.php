<?php

use App\Http\Controllers\Inbox\AttachmentController;
use App\Http\Controllers\Inbox\CampaignReplyController;
use App\Http\Controllers\Inbox\ComposeController;
use App\Http\Controllers\Inbox\InboxController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inbox & composer (Phase 8)
|--------------------------------------------------------------------------
| Mounted from routes/web.php inside the tenant group. The sidebar already
| expects inbox.index, inbox.sent, inbox.drafts, inbox.starred, inbox.spam
| and inbox.trash.
|
| Literal paths are declared before {wildcard} ones throughout, so "compose"
| is never parsed as a message id.
*/

Route::middleware('permission:inbox.view')->group(function () {
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('inbox/sent', [InboxController::class, 'sent'])->name('inbox.sent');
    Route::get('inbox/drafts', [InboxController::class, 'drafts'])->name('inbox.drafts');
    Route::get('inbox/starred', [InboxController::class, 'starred'])->name('inbox.starred');
    Route::get('inbox/spam', [InboxController::class, 'spam'])->name('inbox.spam');
    Route::get('inbox/trash', [InboxController::class, 'trash'])->name('inbox.trash');
    Route::get('inbox/archive', [InboxController::class, 'archive'])->name('inbox.archive');
});

// ------------------------------------------------------------------ writing
// Declared before inbox/{email} so "compose" is not read as an id.
Route::middleware('permission:inbox.send')->group(function () {
    Route::post('inbox/compose', [ComposeController::class, 'create'])->name('inbox.compose.create');
    Route::post('inbox/{email}/reply/{mode}', [ComposeController::class, 'respond'])
        ->whereIn('mode', ['reply', 'reply_all', 'forward'])
        ->name('inbox.compose.respond');

    Route::get('inbox/compose/{draft}', [ComposeController::class, 'edit'])->name('inbox.compose.edit');
    Route::put('inbox/compose/{draft}', [ComposeController::class, 'update'])->name('inbox.compose.update');
    Route::post('inbox/compose/{draft}/send', [ComposeController::class, 'send'])->name('inbox.compose.send');
    Route::post('inbox/compose/{draft}/schedule', [ComposeController::class, 'schedule'])->name('inbox.compose.schedule');
    Route::post('inbox/compose/{draft}/unschedule', [ComposeController::class, 'unschedule'])->name('inbox.compose.unschedule');
    Route::delete('inbox/compose/{draft}', [ComposeController::class, 'destroy'])->name('inbox.compose.destroy');

    Route::post('inbox/compose/{draft}/attach', [ComposeController::class, 'attach'])->name('inbox.compose.attach');
    Route::delete('inbox/compose/{draft}/attach/{attachment}', [ComposeController::class, 'detach'])
        ->name('inbox.compose.detach');
});

// ------------------------------------------------------------------ actions
Route::middleware('permission:inbox.view')->group(function () {
    Route::post('inbox/actions', [InboxController::class, 'bulk'])->name('inbox.bulk');
    Route::post('inbox/empty-trash', [InboxController::class, 'emptyTrash'])->name('inbox.empty-trash');

    Route::get('inbox/{email}', [InboxController::class, 'show'])->name('inbox.show');

    // The message body, served standalone for the sandboxed frame.
    Route::get('inbox/{email}/body', [InboxController::class, 'body'])->name('inbox.body');

    Route::get('inbox/{email}/attachments/{attachment}', [AttachmentController::class, 'download'])
        ->name('inbox.attachment');
    Route::get('inbox/{email}/inline/{attachment}', [AttachmentController::class, 'inline'])
        ->name('inbox.attachment.inline');

    Route::post('inbox/{email}/star', [InboxController::class, 'toggleStar'])->name('inbox.star');
    Route::post('inbox/{email}/read', [InboxController::class, 'toggleRead'])->name('inbox.read');
});

/*
|--------------------------------------------------------------------------
| Campaign replies (Phase 9)
|--------------------------------------------------------------------------
| A campaign reply has a state the rest of the inbox does not — somebody either
| has or has not dealt with it — so it gets its own screen and its own route
| names. The sidebar already expects campaign-replies.index.
*/
Route::middleware('permission:inbox.view')->group(function () {
    Route::get('campaign-replies', [CampaignReplyController::class, 'index'])
        ->name('campaign-replies.index');

    Route::post('campaign-replies/rematch', [CampaignReplyController::class, 'rematch'])
        ->name('campaign-replies.rematch');

    Route::get('campaign-replies/{thread}', [CampaignReplyController::class, 'show'])
        ->name('campaign-replies.show');

    Route::post('campaign-replies/{thread}/status', [CampaignReplyController::class, 'updateStatus'])
        ->name('campaign-replies.status');

    Route::post('campaign-replies/messages/{email}/detach', [CampaignReplyController::class, 'detach'])
        ->name('campaign-replies.detach');
});
