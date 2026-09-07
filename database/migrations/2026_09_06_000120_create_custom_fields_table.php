<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-defined extra subscriber attributes. Values live in the
 * subscribers.custom JSON column, keyed by this table's `key`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('key', 64);
            $table->enum('type', ['text', 'number', 'date', 'select', 'boolean', 'url'])->default('text');
            $table->json('options')->nullable();
            $table->string('default_value', 255)->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
