<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles are either system roles (account_id = null: super-admin, owner, staff)
 * or account-defined roles created by an account owner for their staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
