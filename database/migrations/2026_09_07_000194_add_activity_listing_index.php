<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The same index the four list screens got in 2026_09_07_000193, for the
 * account's own activity screen.
 *
 * `activity_logs` had `(account_id, created_at)` and `(event, created_at)`.
 * Neither can serve `WHERE account_id = ? ORDER BY id DESC`, because InnoDB
 * appends the primary key AFTER `created_at`, so `EXPLAIN` reported
 * `Using filesort` — the whole account's history sorted to render fifty rows.
 *
 * Ordering by `id` rather than `created_at`, which the existing index would
 * have served, is deliberate: an audit trail records bursts of events inside
 * the same second, and `created_at` alone gives no stable order for them.
 * Paginating an unstable ordering shows some rows twice and skips others,
 * which in an audit trail is worse than being slow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['account_id', 'id'], 'activity_logs_account_listing_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_account_listing_index');
        });
    }
};
