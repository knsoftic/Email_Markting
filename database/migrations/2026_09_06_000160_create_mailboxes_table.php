<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A connected IMAP mailbox. IMAP is used for receiving only; the linked
 * smtp_account_id is what actually sends replies from this address.
 *
 * imap_password is encrypted at rest via the model's `encrypted` cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 191);
            $table->string('email', 191);
            $table->string('provider', 40)->default('custom');

            $table->string('imap_host', 191);
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->enum('imap_encryption', ['ssl', 'tls', 'none'])->default('ssl');
            $table->string('imap_username', 191);
            $table->text('imap_password')->nullable();
            $table->boolean('imap_validate_cert')->default(true);

            $table->foreignId('smtp_account_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('sync_enabled')->default(true);
            $table->unsignedSmallInteger('sync_interval_minutes')->default(5);
            $table->unsignedSmallInteger('sync_limit')->default(100);
            $table->boolean('is_active')->default(true);

            $table->enum('status', ['pending', 'connected', 'error', 'disconnected'])->default('pending');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_sync_started_at')->nullable();
            $table->enum('last_sync_status', ['success', 'failed', 'running'])->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();

            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('unread_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'email']);
            $table->index(['is_active', 'sync_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailboxes');
    }
};
