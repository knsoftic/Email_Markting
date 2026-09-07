<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts are the tenant boundary of the platform. Every piece of user data
 * in the system hangs off an account_id, and the BelongsToAccount global scope
 * makes sure one account can never read another's rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->string('company_name', 191)->nullable();
            $table->string('website', 191)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->enum('status', ['active', 'suspended', 'pending'])->default('active');
            $table->unsignedBigInteger('storage_used')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
