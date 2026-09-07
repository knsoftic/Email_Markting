<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw open events from the tracking pixel. Open tracking is approximate:
 * many clients block or proxy remote images, so these numbers are a floor,
 * not an exact count. The UI states this wherever opens are shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device', 40)->nullable();
            $table->timestamp('opened_at');
            $table->timestamps();

            $table->index(['campaign_id', 'opened_at']);
            $table->index('campaign_recipient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_opens');
    }
};
