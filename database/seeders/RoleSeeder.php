<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Creates the three system roles (account_id = null). Account owners can add
 * their own staff roles on top of these later.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Role::withoutGlobalScopes()->updateOrCreate(
            ['account_id' => null, 'slug' => Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'description' => 'Full platform access', 'is_system' => true],
        );

        $owner = Role::withoutGlobalScopes()->updateOrCreate(
            ['account_id' => null, 'slug' => Role::OWNER],
            ['name' => 'Account Owner', 'description' => 'Full access inside one account', 'is_system' => true],
        );

        $staff = Role::withoutGlobalScopes()->updateOrCreate(
            ['account_id' => null, 'slug' => Role::STAFF],
            ['name' => 'Staff', 'description' => 'Permission-based access inside one account', 'is_system' => true],
        );

        // Super admin and owner short-circuit in Role::hasPermission, but the
        // pivot rows are still attached so the admin UI can display them.
        $all = Permission::pluck('id')->all();
        $superAdmin->permissions()->sync($all);
        $owner->permissions()->sync($all);

        // A new staff role starts read-only; the owner grants more from the UI.
        $staff->permissions()->sync(
            Permission::whereIn('slug', [
                'contacts.view',
                'campaigns.view',
                'templates.view',
                'inbox.view',
                'analytics.view',
            ])->pluck('id')->all()
        );
    }
}
