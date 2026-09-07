<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plans. Every limit column is nullable, where NULL means
 * "unlimited" and 0 means "not allowed at all". Every feature is an
 * independent boolean so the super admin can switch any capability on or
 * off per plan without code changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->enum('billing_period', ['monthly', 'yearly', 'lifetime'])->default('monthly');
            $table->unsignedInteger('trial_days')->default(0);

            // Limits — NULL = unlimited.
            $table->unsignedInteger('max_contacts')->nullable();
            $table->unsignedInteger('max_emails_per_month')->nullable();
            $table->unsignedInteger('max_emails_per_day')->nullable();
            $table->unsignedInteger('max_emails_received_per_month')->nullable();
            $table->unsignedInteger('max_campaigns_per_month')->nullable();
            $table->unsignedInteger('max_smtp_accounts')->nullable();
            $table->unsignedInteger('max_mailboxes')->nullable();
            $table->unsignedInteger('max_lists')->nullable();
            $table->unsignedInteger('max_templates')->nullable();
            $table->unsignedInteger('max_automations')->nullable();
            $table->unsignedInteger('max_team_members')->nullable();
            $table->unsignedInteger('max_storage_mb')->nullable();

            // Feature switches.
            $table->boolean('allow_custom_smtp')->default(false);
            $table->boolean('allow_smtp_rotation')->default(false);
            $table->boolean('allow_admin_smtp')->default(true);
            $table->boolean('allow_imap')->default(false);
            $table->boolean('allow_automation')->default(false);
            $table->boolean('allow_ab_testing')->default(false);
            $table->boolean('allow_advanced_analytics')->default(false);
            $table->boolean('allow_segments')->default(false);
            $table->boolean('allow_custom_fields')->default(false);
            $table->boolean('allow_attachments')->default(true);
            $table->boolean('allow_scheduling')->default(true);
            $table->boolean('allow_template_builder')->default(true);
            $table->boolean('allow_api')->default(false);

            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
