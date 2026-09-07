<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key/value settings. account_id = null holds the platform-wide KN Softic
 * settings (branding, system, payment); a non-null account_id holds that
 * tenant's own overrides. Reads go through SettingsService, which caches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('group', 50)->default('general');
            $table->string('key', 100);
            $table->longText('value')->nullable();
            $table->enum('type', ['string', 'text', 'integer', 'boolean', 'json', 'file'])->default('string');
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'group', 'key']);
            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
