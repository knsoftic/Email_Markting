<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Single entry point for the activity log so every module records events the
 * same way. Logging must never break the action it is recording, so failures
 * are swallowed rather than thrown.
 */
class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(string $event, string $description, array $properties = [], ?Model $subject = null): void
    {
        try {
            $user = auth()->user();

            $accountId = $properties['account_id']
                ?? $user?->account_id
                ?? app(TenantManager::class)->id();

            $userId = $properties['user_id'] ?? $user?->id;

            unset($properties['account_id'], $properties['user_id']);

            ActivityLog::withoutGlobalScopes()->create([
                'account_id' => $accountId,
                'user_id' => $userId,
                'event' => $event,
                'description' => mb_substr($description, 0, 255),
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'properties' => $properties ?: null,
                'ip' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
            ]);
        } catch (Throwable) {
            // Never let auditing interrupt the user's action.
        }
    }
}
