<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outgoing mail credentials.
 *
 * - is_global = true, account_id = null  -> admin SMTP, shared via smtp_assignments
 * - is_global = false, account_id set    -> a tenant's own SMTP
 *
 * The password column stores a Laravel-encrypted value (see the model's
 * `encrypted` cast) and is never exposed to the frontend or logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_global')->default(false);

            $table->string('name', 191);
            $table->string('provider', 40)->default('custom');
            $table->string('host', 191);
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('username', 191)->nullable();
            $table->text('password')->nullable();
            $table->enum('encryption', ['tls', 'ssl', 'none'])->default('tls');
            $table->boolean('verify_peer')->default(true);

            $table->string('from_name', 191);
            $table->string('from_email', 191);
            $table->string('reply_to', 191)->nullable();

            $table->unsignedInteger('hourly_limit')->nullable();
            $table->unsignedInteger('daily_limit')->nullable();
            $table->unsignedInteger('monthly_limit')->nullable();
            $table->unsignedSmallInteger('send_delay_ms')->default(0);

            $table->unsignedInteger('sent_this_hour')->default(0);
            $table->unsignedInteger('sent_today')->default(0);
            $table->unsignedInteger('sent_this_month')->default(0);
            $table->unsignedBigInteger('total_sent')->default(0);
            $table->unsignedBigInteger('total_failed')->default(0);
            $table->timestamp('hour_reset_at')->nullable();
            $table->timestamp('day_reset_at')->nullable();
            $table->timestamp('month_reset_at')->nullable();

            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('test_passed')->default(false);

            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'is_active']);
            $table->index(['is_global', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_accounts');
    }
};
