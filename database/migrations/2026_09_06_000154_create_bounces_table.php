<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bounce records, populated from SMTP-level rejections and from parsed
 * delivery-status notifications arriving in a connected mailbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bounces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 191);
            $table->enum('type', ['soft', 'hard'])->default('soft');
            $table->string('code', 20)->nullable();
            $table->text('description')->nullable();
            $table->enum('source', ['smtp', 'dsn', 'webhook', 'manual'])->default('smtp');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['account_id', 'email']);
            $table->index(['account_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bounces');
    }
};
