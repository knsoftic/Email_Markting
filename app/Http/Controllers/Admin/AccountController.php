<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\Scopes\AccountScope;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View
    {
        $accounts = Account::query()
            ->with(['owner', 'subscription.plan'])
            ->withCount([
                'users',
                'subscribers as subscribers_count',
                'campaigns as campaigns_count',
            ])
            ->when($request->filter('q'), function ($query, string $term) {
                $like = '%'.$term.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhereHas('owner', fn ($o) => $o->where('email', 'like', $like)));
            })
            ->when($request->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('plan_id'), fn ($q, $planId) => $q->whereHas(
                'subscription', fn ($s) => $s->where('plan_id', $planId)
            ))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.accounts.index', [
            'accounts' => $accounts,
            'plans' => Plan::ordered()->get(),
            'filters' => $request->filters(['q', 'status', 'plan_id']),
        ]);
    }

    public function show(Account $account): View
    {
        $account->load(['owner', 'subscription.plan', 'users.role']);

        $usage = UsageCounter::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->orderByDesc('period')
            ->limit(6)
            ->get();

        return view('admin.accounts.show', [
            'account' => $account,
            'plans' => Plan::active()->ordered()->get(),
            'usage' => $usage,
            'current' => $usage->firstWhere('period', UsageCounter::currentPeriod())
                ?? new UsageCounter(['period' => UsageCounter::currentPeriod()]),
            'counts' => [
                'subscribers' => Subscriber::withoutGlobalScopes()->where('account_id', $account->id)->count(),
                'campaigns' => Campaign::withoutGlobalScopes()->where('account_id', $account->id)->count(),
                'smtp' => SmtpAccount::withoutGlobalScope(AccountScope::class)->where('account_id', $account->id)->count(),
                'mailboxes' => Mailbox::withoutGlobalScopes()->where('account_id', $account->id)->count(),
                'users' => User::withoutGlobalScopes()->where('account_id', $account->id)->count(),
            ],
        ]);
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'company_name' => ['nullable', 'string', 'max:191'],
            'website' => ['nullable', 'url', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone'],
            'status' => ['required', Rule::in(['active', 'suspended', 'pending'])],
        ]);

        $account->update($data);

        ActivityLogger::log('admin.account.updated', "Updated account {$account->name}", [], $account);

        return back()->with('success', 'Account updated.');
    }

    /**
     * Suspending an account locks out every user inside it on their next
     * request — EnsureAccountIsActive checks the account, not just the user.
     */
    public function toggleStatus(Account $account): RedirectResponse
    {
        $account->update(['status' => $account->isSuspended() ? 'active' : 'suspended']);

        ActivityLogger::log(
            $account->isSuspended() ? 'admin.account.suspended' : 'admin.account.activated',
            ($account->isSuspended() ? 'Suspended' : 'Activated')." account {$account->name}",
            [], $account
        );

        return back()->with('success', $account->isSuspended()
            ? "{$account->name} suspended. All its users are locked out."
            : "{$account->name} reactivated.");
    }

    public function destroy(Account $account): RedirectResponse
    {
        $name = $account->name;
        $account->delete();

        ActivityLogger::log('admin.account.deleted', "Deleted account {$name}");

        return redirect()->route('admin.accounts.index')
            ->with('success', "{$name} deleted. Data is soft-deleted and recoverable.");
    }
}
