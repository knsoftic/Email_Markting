<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smtp\SmtpAccountRequest;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Scopes\AccountScope;
use App\Models\SmtpAccount;
use App\Models\SmtpAssignment;
use App\Models\SmtpUsage;
use App\Services\Smtp\SmtpTester;
use App\Support\ActivityLogger;
use App\Support\SmtpProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Platform-provided SMTP accounts (account_id = NULL, is_global = true).
 *
 * These are shared: one row can serve every tenant, tenants on a given plan,
 * or a hand-picked list, through the smtp_assignments table.
 */
class SmtpAccountController extends Controller
{
    public function __construct(protected SmtpTester $tester) {}

    public function index(Request $request): View
    {
        $accounts = SmtpAccount::withoutGlobalScope(AccountScope::class)
            ->where('is_global', true)
            ->withCount('assignments')
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        return view('admin.smtp.index', [
            'accounts' => $accounts,
            'tenantOwned' => SmtpAccount::withoutGlobalScope(AccountScope::class)
                ->where('is_global', false)
                ->with('account:id,name')
                ->orderByDesc('id')
                ->paginate(20, ['*'], 'tenant_page'),
        ]);
    }

    public function create(): View
    {
        return view('admin.smtp.create', [
            'account' => new SmtpAccount([
                'provider' => 'custom',
                'port' => 587,
                'encryption' => 'tls',
                'verify_peer' => true,
                'is_active' => true,
            ]),
            'providers' => SmtpProviders::all(),
            'ports' => SmtpProviders::commonPorts(),
        ]);
    }

    public function store(SmtpAccountRequest $request): RedirectResponse
    {
        $account = SmtpAccount::withoutGlobalScope(AccountScope::class)->create(array_merge($request->persistable(), [
            'account_id' => null,
            'user_id' => $request->user()->id,
            'is_global' => true,
        ]));

        // A new shared account reaches nobody until it is assigned — that is
        // safer than defaulting to "everyone".
        ActivityLogger::log('admin.smtp.created', "Created admin SMTP {$account->name}", [], $account);

        return to_route('admin.smtp.show', $account)
            ->with('success', "\"{$account->name}\" created. Assign it to accounts below, then test it.");
    }

    public function show(SmtpAccount $smtpAccount): View
    {
        $this->assertGlobal($smtpAccount);

        $smtpAccount->load('assignments.plan:id,name', 'assignments.account:id,name');

        $selectedPlans = $smtpAccount->assignments->where('scope', 'plan')->pluck('plan_id')->filter()->all();

        return view('admin.smtp.show', [
            'account' => $smtpAccount,
            'provider' => SmtpProviders::get($smtpAccount->provider),
            /*
             * Deleted plans are offered only when this account is already
             * assigned to one.
             *
             * Deleting a plan is a soft delete: it comes off the list for new
             * accounts, and everybody already on it keeps it. The selector
             * matches assignments on plan_id and never looks at deleted_at, so
             * those accounts go on sending through this SMTP — but the form
             * could not draw a checkbox for a plan it had not fetched, and
             * updateAssignments() replaces the whole set from what was ticked.
             * So opening this page and pressing Save, changing nothing, quietly
             * cut off every account still on that plan.
             */
            'plans' => Plan::withTrashed()->ordered()
                ->where(fn ($q) => $q->whereNull('deleted_at')->orWhereIn('id', $selectedPlans ?: [0]))
                ->get(['id', 'name', 'deleted_at']),
            'accounts' => Account::orderBy('name')->limit(500)->get(['id', 'name']),
            'scope' => $this->currentScope($smtpAccount),
            'selectedPlans' => $selectedPlans,
            'selectedAccounts' => $smtpAccount->assignments->where('scope', 'account')->pluck('account_id')->filter()->all(),
            'usage' => SmtpUsage::where('smtp_account_id', $smtpAccount->id)
                ->orderByDesc('date')
                ->limit(30)
                ->get(),
            'topTenants' => SmtpUsage::where('smtp_account_id', $smtpAccount->id)
                ->whereNotNull('account_id')
                ->select('account_id', DB::raw('SUM(sent) as total'))
                ->groupBy('account_id')
                ->orderByDesc('total')
                ->limit(10)
                ->with('account:id,name')
                ->get(),
        ]);
    }

    public function edit(SmtpAccount $smtpAccount): View
    {
        $this->assertGlobal($smtpAccount);

        return view('admin.smtp.edit', [
            'account' => $smtpAccount,
            'providers' => SmtpProviders::all(),
            'ports' => SmtpProviders::commonPorts(),
        ]);
    }

    public function update(SmtpAccountRequest $request, SmtpAccount $smtpAccount): RedirectResponse
    {
        $this->assertGlobal($smtpAccount);

        $data = $request->persistable();

        $connectionChanged = collect(['host', 'port', 'username', 'encryption'])
            ->contains(fn ($key) => ($data[$key] ?? null) != $smtpAccount->{$key})
            || array_key_exists('password', $data);

        $smtpAccount->update($data);

        if ($connectionChanged) {
            $smtpAccount->forceFill(['test_passed' => false, 'last_tested_at' => null])->save();
        }

        ActivityLogger::log('admin.smtp.updated', "Updated admin SMTP {$smtpAccount->name}", [], $smtpAccount);

        return to_route('admin.smtp.show', $smtpAccount)->with('success', $connectionChanged
            ? 'Saved. The connection details changed, so test it again before tenants send through it.'
            : 'Admin SMTP updated.');
    }

    /**
     * Replaces the whole assignment set in one transaction, so a shared
     * account is never briefly reachable by the wrong tenants.
     */
    public function updateAssignments(Request $request, SmtpAccount $smtpAccount): RedirectResponse
    {
        $this->assertGlobal($smtpAccount);

        $validated = $request->validate([
            'scope' => ['required', Rule::in(['none', 'all', 'plan', 'account'])],
            'plan_ids' => ['array'],
            'plan_ids.*' => ['integer', 'exists:plans,id'],
            'account_ids' => ['array'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
        ]);

        // withInput() is what keeps the operator's chosen scope selected. The
        // form reads old('scope'), so bouncing without it snapped the radio back
        // to whatever was saved before — leaving an error that says "pick at
        // least one plan" beside a form no longer set to plan scope.
        if ($validated['scope'] === 'plan' && empty($validated['plan_ids'])) {
            return back()->withInput()->with('error', 'Pick at least one plan, or choose a different scope.');
        }

        if ($validated['scope'] === 'account' && empty($validated['account_ids'])) {
            return back()->withInput()->with('error', 'Pick at least one account, or choose a different scope.');
        }

        DB::transaction(function () use ($smtpAccount, $validated) {
            SmtpAssignment::where('smtp_account_id', $smtpAccount->id)->delete();

            match ($validated['scope']) {
                'all' => SmtpAssignment::create([
                    'smtp_account_id' => $smtpAccount->id,
                    'scope' => 'all',
                ]),
                'plan' => collect($validated['plan_ids'])->each(fn ($planId) => SmtpAssignment::create([
                    'smtp_account_id' => $smtpAccount->id,
                    'scope' => 'plan',
                    'plan_id' => $planId,
                ])),
                'account' => collect($validated['account_ids'])->each(fn ($accountId) => SmtpAssignment::create([
                    'smtp_account_id' => $smtpAccount->id,
                    'scope' => 'account',
                    'account_id' => $accountId,
                ])),
                // 'none' leaves the table empty: reachable by nobody.
                default => null,
            };
        });

        ActivityLogger::log(
            'admin.smtp.assignments',
            "Set {$smtpAccount->name} assignment scope to {$validated['scope']}",
            ['scope' => $validated['scope']],
            $smtpAccount
        );

        return back()->with('success', match ($validated['scope']) {
            'all' => "\"{$smtpAccount->name}\" is now available to every account.",
            'plan' => "\"{$smtpAccount->name}\" is now available to accounts on the selected plan(s).",
            'account' => "\"{$smtpAccount->name}\" is now available to the selected account(s) only.",
            default => "\"{$smtpAccount->name}\" is no longer available to any account.",
        });
    }

    public function test(SmtpAccount $smtpAccount): JsonResponse
    {
        $this->assertGlobal($smtpAccount);

        $result = $this->tester->test($smtpAccount);

        ActivityLogger::log(
            $result['ok'] ? 'admin.smtp.test_passed' : 'admin.smtp.test_failed',
            "Admin SMTP test for {$smtpAccount->name}: ".($result['ok'] ? 'passed' : 'failed'),
            [],
            $smtpAccount
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function toggleStatus(SmtpAccount $smtpAccount): RedirectResponse
    {
        $this->assertGlobal($smtpAccount);

        $smtpAccount->update(['is_active' => ! $smtpAccount->is_active]);

        return back()->with('success', $smtpAccount->is_active
            ? "\"{$smtpAccount->name}\" is active again for every assigned account."
            : "\"{$smtpAccount->name}\" is paused. No account will send through it.");
    }

    public function resetCooldown(SmtpAccount $smtpAccount): RedirectResponse
    {
        $this->assertGlobal($smtpAccount);

        $smtpAccount->forceFill(['consecutive_failures' => 0, 'cooldown_until' => null])->save();

        ActivityLogger::log('admin.smtp.cooldown_cleared', "Cleared cooldown on {$smtpAccount->name}", [], $smtpAccount);

        return back()->with('success', 'Cooldown cleared.');
    }

    public function destroy(SmtpAccount $smtpAccount): RedirectResponse
    {
        $this->assertGlobal($smtpAccount);

        $name = $smtpAccount->name;

        DB::transaction(function () use ($smtpAccount) {
            SmtpAssignment::where('smtp_account_id', $smtpAccount->id)->delete();
            $smtpAccount->delete();
        });

        ActivityLogger::log('admin.smtp.deleted', "Deleted admin SMTP {$name}");

        return to_route('admin.smtp.index')
            ->with('success', "\"{$name}\" deleted. Accounts using it will fall back to their own SMTP.");
    }

    // ----------------------------------------------------------- helpers

    protected function assertGlobal(SmtpAccount $account): void
    {
        // This controller only ever manages platform-owned rows; a tenant's own
        // SMTP is theirs to manage, even for a super admin.
        abort_unless($account->is_global, 404);
    }

    protected function currentScope(SmtpAccount $account): string
    {
        $scopes = $account->assignments->pluck('scope')->unique();

        if ($scopes->isEmpty()) {
            return 'none';
        }

        return $scopes->contains('all') ? 'all' : (string) $scopes->first();
    }
}
