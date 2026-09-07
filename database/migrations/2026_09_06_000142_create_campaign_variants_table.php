<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A/B variants. A non-A/B campaign simply has no rows here and sends from
 * the campaign's own subject/sender/html.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->char('label', 1);
            $table->string('subject', 255)->nullable();
            $table->string('from_name', 191)->nullable();
            $table->string('from_email', 191)->nullable();
            $table->longText('html')->nullable();
            $table->unsignedTinyInteger('share_percent')->default(50);

            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('unique_opens')->default(0);
            $table->unsignedInteger('unique_clicks')->default(0);
            $table->boolean('is_winner')->default(false);
            $table->timestamps();

            $table->unique(['campaign_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_variants');
    }
};
