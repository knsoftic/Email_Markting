<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->string('color', 20)->default('#2563eb');
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('subscribers_count')->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
