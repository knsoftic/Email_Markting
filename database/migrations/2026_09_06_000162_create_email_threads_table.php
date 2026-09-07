<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups a campaign send and every reply/counter-reply into one conversation.
 *
 * thread_key is derived from the root Message-ID where headers allow it, and
 * falls back to a normalised subject + participant hash. campaign_id and
 * subscriber_id are filled in when the thread started from a campaign, which
 * is what powers the Campaign Replies screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('thread_key', 191);
            $table->string('subject', 255)->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->json('participants')->nullable();
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('unread_count')->default(0);
            $table->enum('reply_status', ['new', 'read', 'replied', 'closed'])->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'thread_key']);
            $table->index(['account_id', 'last_message_at']);
            $table->index(['campaign_id', 'reply_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_threads');
    }
};
