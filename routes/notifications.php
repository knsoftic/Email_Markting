<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| In-app notifications (Phase 10.5)
|--------------------------------------------------------------------------
| Mounted from routes/web.php inside the tenant group. The sidebar and the top
| bar already expect notifications.index.
|
| No permission middleware, deliberately. A notification row belongs to the
| person it was written for, not to a module — AccountNotifier has already
| decided who was allowed to be told, and gating the screen on a slug would
| leave those people with a bell they cannot open. There is also no
| notifications.* slug in PermissionSeeder, and this is not the place to invent
| one.
|
| Literal paths are declared before {wildcard} ones, and the wildcard is
| constrained to a uuid — the ids are Laravel's notification uuids, so nothing
| else can even be tried.
*/

Route::get('notifications', [NotificationController::class, 'index'])
    ->name('notifications.index');

Route::post('notifications/read-all', [NotificationController::class, 'readAll'])
    ->name('notifications.readAll');

Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
    ->whereUuid('notification')
    ->name('notifications.read');

Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])
    ->whereUuid('notification')
    ->name('notifications.destroy');
