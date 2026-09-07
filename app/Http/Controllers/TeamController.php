<?php

namespace App\Http\Controllers;

use App\Models\Scopes\AccountScope;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Account-side team management: staff users and the account's own roles.
 *
 * Every lookup is constrained to the signed-in user's account_id, so nothing
 * here can touch another tenant even with a guessed id.
 */
class TeamController extends Controller
{
    public function index(Request $request): View
    {
        $accountId = $request->user()->account_id;

        return view('team.index', [
            // Not withoutGlobalScopes(): the plural form also lifts
            // SoftDeletingScope, so people removed from the team went on being
            // listed as members of it.
            'members' => User::withoutGlobalScope(AccountScope::class)
                ->where('account_id', $accountId)
                ->with('role')
                ->orderByDesc('id')
                ->paginate(20),
            'roles' => $this->accountRoles($accountId),
            'seatLimit' => $request->user()->account?->subscription?->limit('max_team_members'),
            // A removed member must give their seat back. PlanLimits has
            // always counted seats with whereNull('deleted_at'), so this screen
            // and the plan page were answering the same question differently.
            'seatsUsed' => User::withoutGlobalScope(AccountScope::class)
                ->where('account_id', $accountId)->count(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->assertSeatAvailable($request);

        return view('team.form', [
            'member' => new User(['status' => 'active', 'timezone' => $request->user()->timezone]),
            'roles' => $this->accountRoles($request->user()->account_id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertSeatAvailable($request);

        $accountId = $request->user()->account_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'designation' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role_id' => ['required', Rule::exists('roles', 'id')->where(
                fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $accountId)
            )],
        ]);

        $this->assertRoleIsAssignable($data['role_id'], $accountId);

        $member = User::create([
            'account_id' => $accountId,
            'role_id' => $data['role_id'],
            'is_super_admin' => false,
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'designation' => $data['designation'] ?? null,
            'timezone' => $data['timezone'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        ActivityLogger::log('team.member_added', "Added team member {$member->email}", [], $member);

        return redirect()->route('team.index')->with('success', "{$member->name} added to the team.");
    }

    public function edit(Request $request, User $user): View
    {
        $this->assertSameAccount($request, $user);

        return view('team.form', [
            'member' => $user,
            'roles' => $this->accountRoles($request->user()->account_id),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->assertSameAccount($request, $user);

        $accountId = $request->user()->account_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'designation' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role_id' => ['required', Rule::exists('roles', 'id')->where(
                fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $accountId)
            )],
        ]);

        $this->assertRoleIsAssignable($data['role_id'], $accountId);

        // The owner's own role is fixed; demoting them would lock the account.
        $isOwner = $user->id === $request->user()->account?->owner_id;

        $user->fill([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'designation' => $data['designation'] ?? null,
            'timezone' => $data['timezone'],
        ]);

        if (! $isOwner) {
            $user->role_id = $data['role_id'];
        }

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        ActivityLogger::log('team.member_updated', "Updated team member {$user->email}", [], $user);

        return redirect()->route('team.index')->with('success', 'Team member updated.');
    }

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        $this->assertSameAccount($request, $user);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot suspend yourself.');
        }

        if ($user->id === $request->user()->account?->owner_id) {
            return back()->with('error', 'The account owner cannot be suspended.');
        }

        $user->update(['status' => $user->isSuspended() ? 'active' : 'suspended']);

        ActivityLogger::log('team.member_status', "{$user->email} is now {$user->status}", [], $user);

        return back()->with('success', "{$user->name} is now {$user->status}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->assertSameAccount($request, $user);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot remove yourself here.');
        }

        if ($user->id === $request->user()->account?->owner_id) {
            return back()->with('error', 'The account owner cannot be removed.');
        }

        $email = $user->email;
        $user->delete();

        ActivityLogger::log('team.member_removed', "Removed team member {$email}");

        return back()->with('success', "{$email} removed from the team.");
    }

    // ------------------------------------------------------------- roles

    public function createRole(Request $request): View
    {
        return view('team.role-form', [
            'role' => new Role,
            'groups' => $this->groupedPermissions(),
            'selected' => [],
        ]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $data = $this->validateRole($request);

        $role = Role::create([
            'account_id' => $request->user()->account_id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);

        $role->permissions()->sync($data['permissions'] ?? []);

        ActivityLogger::log('team.role_created', "Created role {$role->name}", [], $role);

        return redirect()->route('team.index')->with('success', "Role \"{$role->name}\" created.");
    }

    public function editRole(Request $request, Role $role): View
    {
        $this->assertOwnRole($request, $role);

        return view('team.role-form', [
            'role' => $role,
            'groups' => $this->groupedPermissions(),
            'selected' => $role->permissions()->pluck('permissions.id')->all(),
        ]);
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        $this->assertOwnRole($request, $role);

        $data = $this->validateRole($request, $role);

        $role->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
        ]);

        $role->permissions()->sync($data['permissions'] ?? []);

        ActivityLogger::log('team.role_updated', "Updated role {$role->name}", [], $role);

        return redirect()->route('team.index')->with('success', "Role \"{$role->name}\" updated.");
    }

    public function destroyRole(Request $request, Role $role): RedirectResponse
    {
        $this->assertOwnRole($request, $role);

        if ($role->users()->exists()) {
            return back()->with('error', 'Move the members on this role to another role first.');
        }

        $name = $role->name;
        $role->delete();

        ActivityLogger::log('team.role_deleted', "Deleted role {$name}");

        return back()->with('success', "Role \"{$name}\" deleted.");
    }

    // ----------------------------------------------------------- helpers

    protected function assertSameAccount(Request $request, User $user): void
    {
        abort_unless(
            $user->account_id !== null && $user->account_id === $request->user()->account_id,
            404
        );
    }

    /** A custom role must belong to this account; system roles are shared. */
    protected function assertRoleIsAssignable(int $roleId, ?int $accountId): void
    {
        $role = Role::withoutGlobalScopes()->findOrFail($roleId);

        abort_if($role->slug === Role::SUPER_ADMIN, 403, 'That role cannot be assigned here.');
        abort_unless($role->account_id === null || $role->account_id === $accountId, 404);
    }

    protected function assertOwnRole(Request $request, Role $role): void
    {
        abort_unless($role->account_id === $request->user()->account_id, 404);
    }

    protected function assertSeatAvailable(Request $request): void
    {
        $limit = $request->user()->account?->subscription?->limit('max_team_members');

        if ($limit === null) {
            return;
        }

        $used = User::withoutGlobalScope(AccountScope::class)
            ->where('account_id', $request->user()->account_id)->count();

        abort_if(
            $used >= (int) $limit,
            403,
            "Your plan allows {$limit} team member(s). Upgrade to add more."
        );
    }

    protected function accountRoles(?int $accountId)
    {
        return Role::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $accountId))
            ->where('slug', '!=', Role::SUPER_ADMIN)
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    protected function groupedPermissions()
    {
        return Permission::orderBy('group')->orderBy('id')->get()->groupBy('group');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateRole(Request $request, ?Role $role = null): array
    {
        $accountId = $request->user()->account_id;

        $request->merge([
            'slug' => $request->input('slug') ?: str($request->input('name'))->slug()->value(),
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('roles', 'slug')->where('account_id', $accountId)->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);
    }
}
