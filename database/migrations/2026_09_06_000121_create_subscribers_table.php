<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The contact record. Email is unique per account, never globally, so two
 * tenants can each hold the same address independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('email', 191);
            $table->string('name', 191)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('company', 191)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->enum('status', ['active', 'pending', 'unsubscribed', 'bounced', 'blocked'])->default('active');
            $table->string('source', 100)->nullable();
            $table->enum('consent_status', ['explicit', 'implied', 'unknown'])->default('unknown');
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->unsignedInteger('bounce_count')->default(0);
            $table->json('custom')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'email']);
            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'created_at']);
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
