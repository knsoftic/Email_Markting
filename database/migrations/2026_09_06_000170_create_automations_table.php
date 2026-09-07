<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 191);
            $table->string('description', 255)->nullable();

            $table->enum('trigger_type', [
                'subscriber_added', 'tag_added', 'list_joined', 'campaign_opened',
                'link_clicked', 'campaign_not_opened', 'specific_date',
            ]);
            $table->json('trigger_config')->nullable();

            $table->enum('status', ['draft', 'active', 'paused', 'completed'])->default('draft');
            $table->foreignId('smtp_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_name', 191)->nullable();
            $table->string('from_email', 191)->nullable();
            $table->boolean('allow_reentry')->default(false);

            $table->unsignedInteger('entered_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('emails_sent')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'status']);
            $table->index(['status', 'trigger_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};
