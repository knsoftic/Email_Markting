<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per person a campaign will be sent to. This is the send queue's
 * source of truth and the anchor for tracking and reply matching.
 *
 * - message_id  : the Message-ID header we generated, used to match replies
 *                 arriving with In-Reply-To / References.
 * - reply_token : a short opaque token embedded in the Reply-To address
 *                 (reply+TOKEN@domain) as a second, more reliable match path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('smtp_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('email', 191);
            $table->string('name', 191)->nullable();

            $table->enum('status', [
                'pending', 'queued', 'sending', 'sent', 'failed', 'bounced', 'skipped',
            ])->default('pending');

            $table->string('message_id', 191)->nullable();
            $table->string('reply_token', 40)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();

            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamp('first_clicked_at')->nullable();
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->enum('bounce_type', ['soft', 'hard'])->nullable();
            $table->timestamp('replied_at')->nullable();

            $table->timestamps();

            $table->unique(['campaign_id', 'subscriber_id']);
            $table->unique('reply_token');
            $table->index(['campaign_id', 'status']);
            $table->index('message_id');
            $table->index('subscriber_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};
