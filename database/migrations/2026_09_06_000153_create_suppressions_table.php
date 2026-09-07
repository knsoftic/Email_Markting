<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The do-not-send list, checked at recipient-generation time AND again
 * immediately before each send. Unique per account + email so a lookup is
 * a single indexed hit even with millions of rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('email', 191);
            $table->enum('reason', [
                'unsubscribed', 'hard_bounce', 'soft_bounce', 'spam_complaint',
                'manual', 'invalid', 'import',
            ])->default('manual');
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'email']);
            $table->index(['account_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
