<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the four list screens that order by id within one account.
 *
 * ── What was wrong ──────────────────────────────────────────────────────────
 * Contacts, campaigns, automations and the email log all run
 * `WHERE account_id = ? ORDER BY id DESC LIMIT n`. Every one of those tables
 * already had an index beginning with `account_id` — but the next column was
 * `status` or `created_at`, and InnoDB appends the primary key AFTER those. So
 * the stored order is (account_id, status, id): with `status` unconstrained,
 * as it is on the default view everybody loads, the index cannot supply the
 * `id` ordering and MySQL sorts every matching row before taking the first
 * fifty.
 *
 * `EXPLAIN` said `Using filesort` on the email log for both the plain listing
 * and the status-filtered one — the comment in EmailLogController claiming
 * "id DESC is chronological and index-backed" was half right: it is
 * chronological, and it was not backed by anything.
 *
 * That costs nothing on a developer's machine and is the first thing to fail
 * in production: `campaign_logs` gains a row per delivered email, so a busy
 * account sorts millions of rows to render page one.
 *
 * ── Why (account_id, id) and not (account_id, created_at) ───────────────────
 * `id DESC` is what the screens actually order by, and it is the better
 * ordering to keep: it is stable where two rows share a timestamp, which at
 * send rates of thousands per minute is most of them.
 *
 * `campaign_logs` gets a second index for the status-filtered view, because
 * (account_id, status, created_at) cannot serve `ORDER BY id` either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->index(['account_id', 'id'], 'subscribers_account_listing_index');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->index(['account_id', 'id'], 'campaigns_account_listing_index');
        });

        Schema::table('automations', function (Blueprint $table) {
            $table->index(['account_id', 'id'], 'automations_account_listing_index');
        });

        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->index(['account_id', 'id'], 'campaign_logs_account_listing_index');
            $table->index(['account_id', 'status', 'id'], 'campaign_logs_account_status_listing_index');
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropIndex('subscribers_account_listing_index');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('campaigns_account_listing_index');
        });

        Schema::table('automations', function (Blueprint $table) {
            $table->dropIndex('automations_account_listing_index');
        });

        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->dropIndex('campaign_logs_account_listing_index');
            $table->dropIndex('campaign_logs_account_status_listing_index');
        });
    }
};
