<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $subscriptions = Subscription::withoutGlobalScopes()
            ->with(['account.owner', 'plan'])
            ->when($request->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('plan_id'), fn ($q, $id) => $q->where('plan_id', $id))
            ->when($request->filter('q'), function ($query, string $term) {
                $like = '%'.$term.'%';
                $query->whereHas('account', fn ($a) => $a->where('name', 'like', $like));
            })
            ->when($request->boolean('expiring'), fn ($q) => $q
                ->whereNotNull('ends_at')
                ->whereBetween('ends_at', [now(), now()->addDays(14)]))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.subscriptions.index', [
            'subscriptions' => $subscriptions,
            'plans' => Plan::ordered()->get(),
            'filters' => $request->filters(['q', 'status', 'plan_id', 'expiring']),
            'summary' => [
                'active' => Subscription::withoutGlobalScopes()->where('status', 'active')->count(),
                'trial' => Subscription::withoutGlobalScopes()->where('status', 'trial')->count(),
                'expired' => Subscription::withoutGlobalScopes()->where('status', 'expired')->count(),
                'expiring_soon' => Subscription::withoutGlobalScopes()
                    ->whereIn('status', ['active', 'trial'])
                    ->whereNotNull('ends_at')
                    ->whereBetween('ends_at', [now(), now()->addDays(14)])
                    ->count(),
            ],
        ]);
    }

    /** Assign (or replace) the plan on an account. */
    public function store(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'status' => ['required', Rule::in(['trial', 'active', 'expired', 'cancelled', 'pending'])],
            'ends_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);

        /*
         * Per-account limit overrides live on the SUBSCRIPTION row, and assigning
         * a plan writes a new one. Without this they would be silently dropped:
         * an operator who had given a customer 50,000 contacts on a 20,000 plan
         * would move them to a bigger plan and, in the same click, take the
         * override away — with nothing on screen saying so.
         *
         * Carried forward and stated in the message instead. An operator who
         * wants them gone can clear them on the overrides card, which is a
         * deliberate act rather than a side effect of a different one.
         */
        $carried = $account->subscription?->overrides ?: null;

        $subscription = Subscription::withoutGlobalScopes()->create([
            'account_id' => $account->id,
            'plan_id' => $plan->id,
            'status' => $data['status'],
            'starts_at' => now(),
            'ends_at' => $data['ends_at'] ?? ($plan->billing_period === 'lifetime' ? null : now()->addMonth()),
            'trial_ends_at' => $data['status'] === 'trial' ? now()->addDays(max(1, $plan->trial_days)) : null,
            'currency' => $plan->currency,
            'notes' => $data['notes'] ?? null,
            'overrides' => $carried,
        ]);

        ActivityLogger::log(
            'admin.subscription.assigned',
            "Assigned {$plan->name} to {$account->name}",
            ['account_id' => $account->id, 'carried_overrides' => $carried ? array_keys($carried) : []],
            $subscription
        );

        $message = "{$account->name} is now on the {$plan->name} plan.";

        if ($carried) {
            $message .= ' '.count($carried).' per-account '
                .\Illuminate\Support\Str::plural('override', count($carried))
                .' were kept — clear them below if this plan should decide on its own.';
        }

        return back()->with('success', $message);
    }

    /** Change status, extend the term or edit per-account limit overrides. */
    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['trial', 'active', 'expired', 'cancelled', 'pending'])],
            'ends_at' => ['nullable', 'date'],
            'extend_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $endsAt = $data['ends_at'] ? \Illuminate\Support\Carbon::parse($data['ends_at']) : $subscription->ends_at;

        if (! empty($data['extend_days'])) {
            $base = $endsAt && $endsAt->isFuture() ? $endsAt : now();
            $endsAt = $base->copy()->addDays((int) $data['extend_days']);
        }

        $subscription->update([
            'status' => $data['status'],
            'ends_at' => $endsAt,
            'cancelled_at' => $data['status'] === 'cancelled' ? now() : null,
            'notes' => $data['notes'] ?? $subscription->notes,
        ]);

        ActivityLogger::log(
            'admin.subscription.updated',
            "Updated subscription for {$subscription->account?->name}",
            ['account_id' => $subscription->account_id],
            $subscription
        );

        return back()->with('success', 'Subscription updated.');
    }

    /**
     * Per-account limit overrides. Any key left blank falls back to the plan.
     */
    public function overrides(Request $request, Subscription $subscription): RedirectResponse
    {
        $rules = [];
        foreach (Plan::LIMIT_KEYS as $key) {
            $rules["overrides.$key"] = ['nullable', 'integer', 'min:0', 'max:100000000'];
        }
        foreach (Plan::FEATURE_KEYS as $key) {
            $rules["overrides.$key"] = ['nullable', 'in:0,1'];
        }

        $request->validate($rules);

        $overrides = collect($request->input('overrides', []))
            ->reject(fn ($value) => $value === null || $value === '')
            ->map(fn ($value, $key) => in_array($key, Plan::FEATURE_KEYS, true) ? (bool) $value : (int) $value)
            ->all();

        $subscription->update(['overrides' => $overrides ?: null]);

        ActivityLogger::log(
            'admin.subscription.overrides',
            "Changed limit overrides for {$subscription->account?->name}",
            ['account_id' => $subscription->account_id, 'overrides' => $overrides],
            $subscription
        );

        return back()->with('success', 'Limit overrides saved.');
    }
}
