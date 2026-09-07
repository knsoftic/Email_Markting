<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A marketing campaign. Result counters are denormalised on the row so the
 * campaign index and progress bar never aggregate campaign_recipients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 191);
            $table->string('subject', 255);
            $table->string('preview_text', 255)->nullable();
            $table->string('from_name', 191);
            $table->string('from_email', 191);
            $table->string('reply_to', 191)->nullable();

            $table->foreignId('email_template_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('html')->nullable();
            $table->longText('plain_text')->nullable();
            $table->json('blocks')->nullable();

            $table->foreignId('smtp_account_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('use_smtp_rotation')->default(false);

            $table->enum('status', [
                'draft', 'scheduled', 'queued', 'sending', 'paused',
                'completed', 'failed', 'cancelled',
            ])->default('draft');

            $table->json('audience')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paused_at')->nullable();

            $table->boolean('track_opens')->default(true);
            $table->boolean('track_clicks')->default(true);

            $table->boolean('is_ab_test')->default(false);
            $table->enum('ab_test_type', ['subject', 'sender', 'content'])->nullable();
            $table->unsignedTinyInteger('ab_sample_percent')->default(20);
            $table->enum('ab_winner_metric', ['opens', 'clicks'])->default('opens');
            $table->unsignedInteger('ab_decide_after_minutes')->default(240);
            $table->unsignedBigInteger('ab_winner_variant_id')->nullable();
            $table->timestamp('ab_decided_at')->nullable();

            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('unique_opens')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('unique_clicks')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->unsignedInteger('replied_count')->default(0);

            $table->text('last_error')->nullable();
            $table->string('batch_id', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'status']);
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
