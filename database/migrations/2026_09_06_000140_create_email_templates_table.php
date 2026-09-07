<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * account_id = null marks a system template shipped with the platform; those
 * are readable by every tenant and only editable by a super admin.
 *
 * `blocks` stores the visual builder's JSON schema; `html` stores the
 * compiled, responsive output actually used when sending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 191);
            $table->string('subject', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('category', 50)->default('general');
            $table->json('blocks')->nullable();
            $table->longText('html')->nullable();
            $table->longText('plain_text')->nullable();
            $table->string('thumbnail_path', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'is_active']);
            $table->index(['is_system', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
