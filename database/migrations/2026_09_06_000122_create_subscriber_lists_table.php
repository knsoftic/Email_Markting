<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counters are denormalised and maintained by ListService so list screens
 * stay fast with hundreds of thousands of members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriber_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('description', 255)->nullable();
            $table->string('from_name', 191)->nullable();
            $table->string('from_email', 191)->nullable();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('active_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_lists');
    }
};
