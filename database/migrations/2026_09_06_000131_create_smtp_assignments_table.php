<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may use an admin (global) SMTP account.
 *
 *   scope = all      -> every account
 *   scope = plan     -> accounts subscribed to plan_id
 *   scope = account  -> one specific account
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smtp_account_id')->constrained()->cascadeOnDelete();
            $table->enum('scope', ['all', 'plan', 'account'])->default('all');
            $table->foreignId('plan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['smtp_account_id', 'scope']);
            $table->index('plan_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_assignments');
    }
};
