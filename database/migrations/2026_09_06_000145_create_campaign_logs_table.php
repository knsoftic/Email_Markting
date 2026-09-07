<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The searchable sending log required by the brief: every send attempt,
 * campaign or one-off, with the SMTP account used and the provider response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('smtp_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('recipient_email', 191);
            $table->string('sender_email', 191)->nullable();
            $table->string('subject', 255)->nullable();
            $table->enum('type', ['campaign', 'automation', 'transactional', 'test', 'reply'])->default('campaign');
            $table->enum('status', ['sent', 'failed', 'bounced', 'deferred'])->default('sent');
            $table->text('error')->nullable();
            $table->text('response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status', 'created_at']);
            $table->index(['campaign_id', 'status']);
            $table->index('recipient_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_logs');
    }
};
