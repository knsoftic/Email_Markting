<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * System roles (account_id = null) live here. Super Admin and Account Owner
 * are absolute — they short-circuit in Role::hasPermission — so their
 * permission matrix is shown read-only. Staff is fully editable, and extra
 * system roles can be added.
 */
class RoleController extends Controller
{
    public function index(): View
    {
        return view('admin.roles.index', [
            'roles' => Role::withoutGlobalScopes()
                ->whereNull('account_id')
                ->withCount(['users', 'permissions'])
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->get(),
            'accountRoles' => Role::withoutGlobalScopes()
                ->whereNotNull('account_id')
                ->with('account')
                ->withCount('users')
                ->orderBy('account_id')
                ->limit(50)
                ->get(),
            'permissionCount' => Permission::count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.form', [
            'role' => new Role(['is_system' => true]),
            'groups' => $this->groupedPermissions(),
            'selected' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateRole($request);

        $role = Role::withoutGlobalScopes()->create([
            'account_id' => null,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_system' => true,
        ]);

        $role->permissions()->sync($data['permissions'] ?? []);

        ActivityLogger::log('admin.role.created', "Created role {$role->name}", [], $role);

        return redirect()->route('admin.roles.index')->with('success', "Role \"{$role->name}\" created.");
    }

    public function edit(Role $role): View
    {
        abort_if($role->account_id !== null, 404);

        return view('admin.roles.form', [
            'role' => $role,
            'groups' => $this->groupedPermissions(),
            'selected' => $role->permissions()->pluck('permissions.id')->all(),
            'locked' => in_array($role->slug, [Role::SUPER_ADMIN, Role::OWNER], true),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->account_id !== null, 404);

        if (in_array($role->slug, [Role::SUPER_ADMIN, Role::OWNER], true)) {
            return back()->with('error', 'Super Admin and Account Owner always hold every permission and cannot be edited.');
        }

        $data = $this->validateRole($request, $role);

        $role->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
        ]);

        $role->permissions()->sync($data['permissions'] ?? []);

        ActivityLogger::log('admin.role.updated', "Updated role {$role->name}", [], $role);

        return redirect()->route('admin.roles.index')->with('success', "Role \"{$role->name}\" updated.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->account_id !== null, 404);

        if (in_array($role->slug, [Role::SUPER_ADMIN, Role::OWNER, Role::STAFF], true)) {
            return back()->with('error', 'The three built-in roles cannot be deleted.');
        }

        if ($role->users()->exists()) {
            return back()->with('error', 'This role is still assigned to users. Move them to another role first.');
        }

        $name = $role->name;
        $role->delete();

        ActivityLogger::log('admin.role.deleted', "Deleted role {$name}");

        return redirect()->route('admin.roles.index')->with('success', "Role \"{$name}\" deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateRole(Request $request, ?Role $role = null): array
    {
        $request->merge([
            'slug' => $request->input('slug') ?: str($request->input('name'))->slug()->value(),
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('roles', 'slug')->whereNull('account_id')->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);
    }

    protected function groupedPermissions()
    {
        return Permission::orderBy('group')->orderBy('id')->get()->groupBy('group');
    }
}
