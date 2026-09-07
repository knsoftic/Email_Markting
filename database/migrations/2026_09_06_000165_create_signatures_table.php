<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signature with mailbox_id set is that mailbox's default; one with
 * mailbox_id null is a personal signature available everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->longText('content');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['account_id', 'mailbox_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
