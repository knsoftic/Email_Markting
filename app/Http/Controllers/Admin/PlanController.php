<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanRequest;
use App\Models\Plan;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        return view('admin.plans.index', [
            'plans' => Plan::withCount(['subscriptions as active_subscriptions' => fn ($q) => $q->whereIn('status', ['active', 'trial'])])
                ->ordered()
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.plans.form', [
            'plan' => new Plan([
                'currency' => 'USD',
                'billing_period' => 'monthly',
                'trial_days' => 14,
                'is_active' => true,
                'is_public' => true,
                'allow_admin_smtp' => true,
                'allow_attachments' => true,
                'allow_scheduling' => true,
                'allow_template_builder' => true,
            ]),
        ]);
    }

    public function store(PlanRequest $request): RedirectResponse
    {
        $plan = DB::transaction(function () use ($request) {
            $plan = Plan::create($request->validated());
            $this->enforceSingleDefault($plan);

            return $plan;
        });

        ActivityLogger::log('plan.created', "Plan {$plan->name} created", [], $plan);

        return redirect()->route('admin.plans.index')
            ->with('success', "Plan \"{$plan->name}\" created.");
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans.form', ['plan' => $plan]);
    }

    public function update(PlanRequest $request, Plan $plan): RedirectResponse
    {
        DB::transaction(function () use ($request, $plan) {
            $plan->update($request->validated());
            $this->enforceSingleDefault($plan);
        });

        ActivityLogger::log('plan.updated', "Plan {$plan->name} updated", [], $plan);

        return redirect()->route('admin.plans.index')
            ->with('success', "Plan \"{$plan->name}\" updated.");
    }

    public function duplicate(Plan $plan): RedirectResponse
    {
        $copy = $plan->replicate(['deleted_at']);
        $copy->name = $plan->name.' (copy)';
        $copy->slug = $plan->slug.'-copy-'.substr((string) time(), -4);
        $copy->is_default = false;
        $copy->is_active = false;
        $copy->save();

        ActivityLogger::log('plan.duplicated', "Plan {$plan->name} duplicated", [], $copy);

        return redirect()->route('admin.plans.edit', $copy)
            ->with('success', 'Plan duplicated. It is inactive until you enable it.');
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        // A plan with live subscriptions is deactivated instead of deleted, so
        // existing customers never lose the limits they are running under.
        $inUse = $plan->subscriptions()->whereIn('status', ['active', 'trial'])->exists();

        if ($inUse) {
            $plan->update(['is_active' => false, 'is_public' => false]);

            ActivityLogger::log('plan.deactivated', "Plan {$plan->name} deactivated (in use)", [], $plan);

            return redirect()->route('admin.plans.index')
                ->with('warning', "\"{$plan->name}\" has active subscriptions, so it was deactivated instead of deleted.");
        }

        $name = $plan->name;
        $plan->delete();

        ActivityLogger::log('plan.deleted', "Plan {$name} deleted");

        return redirect()->route('admin.plans.index')->with('success', "Plan \"{$name}\" deleted.");
    }

    /** Only one plan may carry the is_default flag. */
    protected function enforceSingleDefault(Plan $plan): void
    {
        if ($plan->is_default) {
            Plan::where('id', '!=', $plan->id)->update(['is_default' => false]);
        }
    }
}
