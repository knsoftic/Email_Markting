<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One subscriber's journey through one automation. The scheduler picks up
 * rows where status = waiting and next_run_at <= now, which is why
 * next_run_at carries its own index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_step_id')->nullable()->constrained('automation_steps')->nullOnDelete();
            $table->enum('status', ['running', 'waiting', 'completed', 'failed', 'cancelled'])->default('waiting');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('steps_completed')->default(0);
            $table->text('last_error')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'subscriber_id']);
            $table->index(['status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
