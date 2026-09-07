<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every individual message in the inbox module — received, sent, draft or
 * scheduled.
 *
 * Duplicate protection: (mailbox_id, message_id) is unique. MySQL allows many
 * NULLs in a unique index, so locally composed drafts without a Message-ID
 * still insert fine, while a re-sync of the same server message is rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('email_thread_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('direction', ['incoming', 'outgoing'])->default('incoming');
            $table->enum('folder_type', ['inbox', 'sent', 'drafts', 'spam', 'trash', 'archive'])->default('inbox');

            $table->string('message_id', 191)->nullable();
            $table->string('in_reply_to', 191)->nullable();
            $table->text('references')->nullable();
            $table->unsignedBigInteger('uid')->nullable();

            $table->string('from_name', 191)->nullable();
            $table->string('from_email', 191)->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('reply_to', 191)->nullable();

            $table->string('subject', 255)->nullable();
            $table->string('preview', 255)->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();

            $table->boolean('has_attachments')->default(false);
            $table->unsignedSmallInteger('attachments_count')->default(0);
            $table->unsignedBigInteger('size')->default(0);

            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->boolean('is_important')->default(false);
            $table->boolean('is_draft')->default(false);

            // Campaign reply linkage.
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_campaign_reply')->default(false);

            // Outgoing state.
            $table->foreignId('smtp_account_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('send_status', ['draft', 'scheduled', 'queued', 'sent', 'failed'])->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['mailbox_id', 'message_id']);
            $table->index(['account_id', 'folder_type', 'received_at']);
            $table->index(['mailbox_id', 'folder_type', 'is_read']);
            $table->index(['account_id', 'is_campaign_reply']);
            $table->index('email_thread_id');
            $table->index('in_reply_to');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
