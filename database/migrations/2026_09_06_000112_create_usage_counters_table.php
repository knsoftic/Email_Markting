<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per account per billing month (period = "YYYY-MM"). Counters are
 * incremented atomically so limit checks never need to COUNT() large tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->unsignedBigInteger('emails_sent')->default(0);
            $table->unsignedBigInteger('emails_failed')->default(0);
            $table->unsignedBigInteger('emails_received')->default(0);
            $table->unsignedInteger('campaigns_created')->default(0);
            $table->unsignedInteger('contacts_added')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
