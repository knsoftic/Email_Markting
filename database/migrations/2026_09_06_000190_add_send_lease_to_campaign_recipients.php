<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-worker claim on a batch of recipients.
 *
 * Several workers drain the same campaign. Without a token identifying who
 * claimed what, a worker that flips rows to "sending" cannot tell its own rows
 * from another worker's, and both would send to the same people.
 *
 * locked_at is what lets a crashed worker's claim be reclaimed: rows stuck in
 * "sending" past the lease are returned to "pending" rather than stranded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->string('locked_by', 64)->nullable()->after('status');
            $table->timestamp('locked_at')->nullable()->after('locked_by');

            // The claim query filters on (campaign_id, status) and then reads
            // back by locked_by, so both paths are indexed.
            $table->index(['campaign_id', 'status', 'locked_by'], 'campaign_recipients_claim_index');
            $table->index(['status', 'locked_at'], 'campaign_recipients_stale_lease_index');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropIndex('campaign_recipients_claim_index');
            $table->dropIndex('campaign_recipients_stale_lease_index');
            $table->dropColumn(['locked_by', 'locked_at']);
        });
    }
};
