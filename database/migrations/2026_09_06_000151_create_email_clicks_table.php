<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('clicked_at');
            $table->timestamps();

            $table->index(['campaign_id', 'clicked_at']);
            $table->index('campaign_link_id');
            $table->index('campaign_recipient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_clicks');
    }
};
