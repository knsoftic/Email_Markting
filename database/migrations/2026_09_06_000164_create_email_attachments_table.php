<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('disk', 40)->default('local');
            $table->string('path', 255);
            $table->string('content_id', 191)->nullable();
            $table->boolean('is_inline')->default(false);
            $table->timestamps();

            $table->index('email_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};
