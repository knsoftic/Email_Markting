<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved, reusable filter. `rules` holds an array of
 * {field, operator, value} objects compiled to SQL by SegmentCompiler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('description', 255)->nullable();
            $table->enum('match_type', ['all', 'any'])->default('all');
            $table->json('rules');
            $table->unsignedInteger('cached_count')->default(0);
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
