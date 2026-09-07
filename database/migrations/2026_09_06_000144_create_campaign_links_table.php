<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every trackable link found in a campaign body, stored once and referenced
 * by a short hash in the rewritten redirect URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('hash', 40);
            $table->string('label', 191)->nullable();
            $table->unsignedInteger('click_count')->default(0);
            $table->unsignedInteger('unique_click_count')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_links');
    }
};
