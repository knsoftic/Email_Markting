<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records what followed a tracked link, the way email_opens already records
 * what fetched the pixel.
 *
 * Opens were classified from the start because image proxies are the obvious
 * problem. Clicks were not — on the assumption that a click is always a
 * person. It is not: Mimecast, Proofpoint, Barracuda and Microsoft's Safe
 * Links follow every URL in a message to check it, and Slack, WhatsApp and
 * Twitter fetch them to build a preview. Those are the same agents the open
 * classifier already knows about, arriving by a different door.
 *
 * Without this column a security scanner inflates the click rate AND, because
 * a click implies an open, manufactures engagement for a contact who has not
 * seen the message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_clicks', function (Blueprint $table) {
            $table->string('device', 40)->nullable()->after('user_agent');

            // Reports group by it, so it is worth an index alongside the
            // campaign the report is for.
            $table->index(['campaign_id', 'device']);
        });
    }

    public function down(): void
    {
        Schema::table('email_clicks', function (Blueprint $table) {
            $table->dropIndex(['campaign_id', 'device']);
            $table->dropColumn('device');
        });
    }
};
