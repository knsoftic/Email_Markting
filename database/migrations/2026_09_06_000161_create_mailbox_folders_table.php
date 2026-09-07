<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMAP folders discovered on a mailbox. `last_uid` + `uid_validity` are what
 * make the sync incremental: we only ever ask the server for UIDs greater
 * than the highest one we already stored, instead of re-downloading the
 * whole folder on every pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->string('path', 191);
            $table->string('display_name', 191);
            $table->enum('type', ['inbox', 'sent', 'drafts', 'spam', 'trash', 'archive', 'custom'])->default('custom');
            $table->string('delimiter', 5)->default('/');
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('is_syncable')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'path']);
            $table->index(['mailbox_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_folders');
    }
};
