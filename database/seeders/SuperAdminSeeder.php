<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the platform's first super admin. Credentials come from the
 * environment so nothing sensitive is committed; the defaults below are for
 * local development only and must be changed before deployment.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::withoutGlobalScopes()->where('slug', Role::SUPER_ADMIN)->first();

        $email = env('KNS_ADMIN_EMAIL', 'admin@knsoftic.com');

        User::withoutGlobalScopes()->updateOrCreate(
            ['email' => $email],
            [
                'account_id' => null,
                'role_id' => $role?->id,
                'is_super_admin' => true,
                'name' => env('KNS_ADMIN_NAME', 'KN Softic Admin'),
                'password' => Hash::make(env('KNS_ADMIN_PASSWORD', 'password')),
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info("Super admin ready: {$email}");
    }
}
