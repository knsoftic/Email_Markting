<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends Laravel's default users table with tenancy, role, profile and
 * two-factor columns. A user belongs to exactly one account; super admins
 * have account_id = null and is_super_admin = true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('role_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('role_id');
            $table->string('phone', 40)->nullable()->after('email');
            $table->string('designation', 100)->nullable()->after('phone');
            $table->string('avatar_path', 255)->nullable()->after('designation');
            $table->string('timezone', 64)->default('UTC')->after('avatar_path');
            $table->enum('status', ['active', 'suspended', 'pending'])->default('active')->after('timezone');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->text('two_factor_secret')->nullable()->after('last_login_ip');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->softDeletes();

            $table->index(['account_id', 'status']);
            $table->index('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['role_id']);
            $table->dropIndex(['account_id', 'status']);
            $table->dropIndex(['is_super_admin']);
            $table->dropColumn([
                'account_id', 'role_id', 'is_super_admin', 'phone', 'designation',
                'avatar_path', 'timezone', 'status', 'last_login_at', 'last_login_ip',
                'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
                'deleted_at',
            ]);
        });
    }
};
