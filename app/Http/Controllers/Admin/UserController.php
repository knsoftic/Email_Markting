<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Campaign;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(protected AccountProvisioner $provisioner) {}

    public function index(Request $request): View
    {
        $users = User::withoutGlobalScopes()
            ->with(['account.subscription.plan', 'role'])
            ->when($request->filter('q'), function ($query, string $term) {
                $like = '%'.$term.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('account', fn ($a) => $a->where('name', 'like', $like)));
            })
            ->when($request->filter('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->filter('type') === 'super', fn ($q) => $q->where('is_super_admin', true))
            ->when($request->filter('type') === 'account', fn ($q) => $q->where('is_super_admin', false))
            ->when($request->integer('plan_id'), fn ($q, $planId) => $q->whereHas(
                'account.subscription', fn ($s) => $s->where('plan_id', $planId)
            ))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'plans' => Plan::ordered()->get(),
            'filters' => $request->filters(['q', 'status', 'type', 'plan_id']),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => new User(['status' => 'active', 'timezone' => 'UTC']),
            'plans' => Plan::active()->ordered()->get(),
            'roles' => $this->assignableRoles(),
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            if ($data['is_super_admin']) {
                return User::create([
                    'account_id' => null,
                    'role_id' => Role::withoutGlobalScopes()->where('slug', Role::SUPER_ADMIN)->value('id'),
                    'is_super_admin' => true,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'designation' => $data['designation'] ?? null,
                    'password' => Hash::make($data['password']),
                    'timezone' => $data['timezone'],
                    'status' => $data['status'],
                    'email_verified_at' => $data['email_verified'] ? now() : null,
                ]);
            }

            $user = $this->provisioner->provision([
                'company_name' => $data['company_name'],
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'timezone' => $data['timezone'],
                'plan_id' => $data['plan_id'] ?? null,
                'status' => $data['status'],
            ]);

            $user->forceFill([
                'phone' => $data['phone'] ?? null,
                'designation' => $data['designation'] ?? null,
                'email_verified_at' => $data['email_verified'] ? now() : null,
            ])->save();

            return $user;
        });

        ActivityLogger::log('admin.user.created', "Created user {$user->email}", [], $user);

        return redirect()->route('admin.users.show', $user)->with('success', 'User created.');
    }

    public function show(User $user): View
    {
        $user->loadMissing(['account.subscription.plan', 'account.owner', 'role']);

        $accountId = $user->account_id;

        return view('admin.users.show', [
            'user' => $user,
            'stats' => $accountId ? [
                'subscribers' => Subscriber::withoutGlobalScopes()->where('account_id', $accountId)->count(),
                'campaigns' => Campaign::withoutGlobalScopes()->where('account_id', $accountId)->count(),
                'team' => User::withoutGlobalScopes()->where('account_id', $accountId)->count(),
            ] : null,
            'recentActivity' => $user->activityLogs()->withoutGlobalScopes()->latest()->limit(15)->get(),
        ]);
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', [
            'user' => $user,
            'plans' => Plan::active()->ordered()->get(),
            'roles' => $this->assignableRoles($user->account_id),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        /*
         * The same guard Suspend and Delete already carry, which this form did
         * not. Suspending yourself here signs you out on the next request and
         * leaves nobody able to sign back in and undo it — the one mistake in
         * this panel that cannot be corrected from inside the product.
         */
        if ($user->is($request->user()) && $data['status'] !== 'active') {
            return back()
                ->withInput()
                ->with('error', 'You cannot suspend your own account — you would not be able to sign back in to undo it.');
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'designation' => $data['designation'] ?? null,
            'timezone' => $data['timezone'],
            'status' => $data['status'],
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        if (array_key_exists('role_id', $data) && $data['role_id']) {
            $user->role_id = $data['role_id'];
        }

        $user->email_verified_at = $data['email_verified']
            ? ($user->email_verified_at ?? now())
            : null;

        $user->save();

        ActivityLogger::log('admin.user.updated', "Updated user {$user->email}", [], $user);

        return redirect()->route('admin.users.show', $user)->with('success', 'User updated.');
    }

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot suspend your own account.');
        }

        $user->update(['status' => $user->isSuspended() ? 'active' : 'suspended']);

        ActivityLogger::log(
            $user->isSuspended() ? 'admin.user.suspended' : 'admin.user.activated',
            ($user->isSuspended() ? 'Suspended' : 'Activated')." user {$user->email}",
            [], $user
        );

        return back()->with('success', $user->isSuspended()
            ? "{$user->name} has been suspended."
            : "{$user->name} has been reactivated.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $email = $user->email;
        $user->delete();

        ActivityLogger::log('admin.user.deleted', "Deleted user {$email}");

        return redirect()->route('admin.users.index')
            ->with('success', "{$email} deleted. Their history is retained.");
    }

    /**
     * Signs the admin in as the target user for support work. The original
     * admin id is kept in the session so the switch is always reversible.
     */
    public function impersonate(Request $request, User $user): RedirectResponse
    {
        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Super admins cannot be impersonated.');
        }

        if (! $user->account || ! $user->account->isActive() || $user->isSuspended()) {
            return back()->with('error', 'This user cannot be impersonated while suspended.');
        }

        $request->session()->put('impersonator_id', $request->user()->id);

        ActivityLogger::log('admin.user.impersonated', "Started impersonating {$user->email}", [], $user);

        auth()->login($user);

        return redirect()->route('dashboard')
            ->with('warning', "You are now signed in as {$user->name}. Use \"Stop impersonating\" to return.");
    }

    public function stopImpersonating(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull('impersonator_id');

        abort_unless($adminId !== null, 403);

        $admin = User::withoutGlobalScopes()->findOrFail($adminId);
        $current = $request->user();

        auth()->login($admin);

        ActivityLogger::log('admin.user.impersonation_ended', "Stopped impersonating {$current?->email}");

        return redirect()->route('admin.users.index')->with('success', 'Back to your admin session.');
    }

    /**
     * @return \Illuminate\Support\Collection<int, Role>
     */
    protected function assignableRoles(?int $accountId = null)
    {
        return Role::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('account_id')->when($accountId, fn ($q2) => $q2->orWhere('account_id', $accountId)))
            ->orderBy('name')
            ->get();
    }
}
