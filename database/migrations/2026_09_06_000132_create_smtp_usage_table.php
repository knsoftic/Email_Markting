<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-day history for each SMTP account. The live counters on smtp_accounts
 * drive limit checks; this table keeps the chart data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smtp_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamps();

            $table->unique(['smtp_account_id', 'account_id', 'date'], 'smtp_usage_unique');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_usage');
    }
};
