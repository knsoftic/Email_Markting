<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An ordered list of steps. `config` shape depends on `type`, e.g.
 *   send_email -> {template_id, subject, from_name, html}
 *   wait       -> {amount: 1, unit: "days"}
 *   condition  -> {field, operator, value, on_false: "stop"|"skip"}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->enum('type', [
                'send_email', 'wait', 'condition', 'add_tag', 'remove_tag',
                'move_list', 'unsubscribe',
            ]);
            $table->string('label', 191)->nullable();
            $table->json('config')->nullable();
            $table->foreignId('email_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamps();

            $table->index(['automation_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_steps');
    }
};
