<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an account to a plan for a period. The `overrides` JSON lets a super
 * admin raise or lower any single plan limit for one account without cloning
 * the whole plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['trial', 'active', 'expired', 'cancelled', 'pending'])->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('price_paid', 10, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('payment_reference', 191)->nullable();
            $table->json('overrides')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
