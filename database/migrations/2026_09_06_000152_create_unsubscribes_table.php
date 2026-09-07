<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail of every opt-out, kept separately from the suppression list so
 * the reason, source campaign and IP survive even if the contact is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unsubscribes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 191);
            $table->enum('method', ['link', 'one_click', 'manual', 'import', 'complaint'])->default('link');
            $table->string('reason', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('unsubscribed_at');
            $table->timestamps();

            $table->index(['account_id', 'email']);
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unsubscribes');
    }
};
