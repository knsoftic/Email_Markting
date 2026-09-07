<?php

namespace App\Http\Controllers\Smtp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smtp\SmtpAccountRequest;
use App\Models\SmtpAccount;
use App\Models\SmtpUsage;
use App\Services\Smtp\SmtpTester;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use App\Support\SmtpProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The tenant's own SMTP accounts.
 *
 * Admin-provided (global) accounts are visible here as read-only entries —
 * a tenant can see what it may send through, but only the super admin can
 * change or delete them.
 */
class SmtpAccountController extends Controller
{
    public function __construct(
        protected SmtpTester $tester,
        protected \App\Services\Smtp\SmtpSelector $selector,
    ) {}

    public function index(Request $request): View
    {
        $limits = PlanLimits::for($request->user()->account);

        // Own accounts come from the tenant scope. Shared ones are NOT simply
        // "every global row" — the scope would happily return admin accounts
        // this tenant was never assigned, so they go through the selector's
        // assignment rules instead.
        $own = SmtpAccount::query()
            ->where('is_global', false)
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        $shared = $this->selector->visibleSharedFor($request->user()->account);

        return view('smtp.index', [
            'own' => $own,
            'shared' => $shared,
            'canAddCustom' => $limits->allows('allow_custom_smtp'),
            'smtpLimit' => $limits->limit('max_smtp_accounts'),
            'smtpUsed' => $own->count(),
            'rotationAllowed' => $limits->allows('allow_smtp_rotation'),
            'adminSmtpAllowed' => $limits->allows('allow_admin_smtp'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->assertMayAddCustom($request);

        return view('smtp.create', [
            'account' => new SmtpAccount([
                'provider' => 'custom',
                'port' => 587,
                'encryption' => 'tls',
                'verify_peer' => true,
                'is_active' => true,
                'from_name' => $request->user()->account?->name,
            ]),
            'providers' => SmtpProviders::all(),
            'ports' => SmtpProviders::commonPorts(),
        ]);
    }

    public function store(SmtpAccountRequest $request): RedirectResponse
    {
        $this->assertMayAddCustom($request);

        $account = SmtpAccount::create(array_merge($request->persistable(), [
            'user_id' => $request->user()->id,
            'is_global' => false,
        ]));

        ActivityLogger::log('smtp.created', "Added SMTP account {$account->name}", [], $account);

        return to_route('smtp.show', $account)
            ->with('success', "\"{$account->name}\" saved. Run a test to confirm it can connect.");
    }

    public function show(SmtpAccount $smtpAccount): View
    {
        $this->assertVisible($smtpAccount);

        return view('smtp.show', [
            'account' => $smtpAccount,
            'provider' => SmtpProviders::get($smtpAccount->provider),
            'editable' => ! $smtpAccount->is_global,
            'usage' => SmtpUsage::where('smtp_account_id', $smtpAccount->id)
                ->where('account_id', auth()->user()->account_id)
                ->orderByDesc('date')
                ->limit(30)
                ->get(),
        ]);
    }

    public function edit(SmtpAccount $smtpAccount): View
    {
        $this->assertOwned($smtpAccount);

        return view('smtp.edit', [
            'account' => $smtpAccount,
            'providers' => SmtpProviders::all(),
            'ports' => SmtpProviders::commonPorts(),
        ]);
    }

    public function update(SmtpAccountRequest $request, SmtpAccount $smtpAccount): RedirectResponse
    {
        $this->assertOwned($smtpAccount);

        $data = $request->persistable();

        // Changing where or who we connect as invalidates the previous test
        // result, so the badge cannot keep claiming a stale success.
        $connectionChanged = collect(['host', 'port', 'username', 'encryption'])
            ->contains(fn ($key) => ($data[$key] ?? null) != $smtpAccount->{$key})
            || array_key_exists('password', $data);

        $smtpAccount->update($data);

        if ($connectionChanged) {
            $smtpAccount->forceFill(['test_passed' => false, 'last_tested_at' => null])->save();
        }

        ActivityLogger::log('smtp.updated', "Updated SMTP account {$smtpAccount->name}", [], $smtpAccount);

        return to_route('smtp.show', $smtpAccount)->with('success', $connectionChanged
            ? 'Saved. The connection details changed, so test it again before sending.'
            : 'SMTP account updated.');
    }

    /** Real handshake against the provider. Returns JSON for the inline widget. */
    public function test(SmtpAccount $smtpAccount): JsonResponse
    {
        $this->assertVisible($smtpAccount);

        // A shared platform account is tested read-only: the tenant gets the
        // answer, but its health fields stay under the admin's control.
        $result = $this->tester->test($smtpAccount, persist: ! $smtpAccount->is_global);

        ActivityLogger::log(
            $result['ok'] ? 'smtp.test_passed' : 'smtp.test_failed',
            "SMTP test for {$smtpAccount->name}: ".($result['ok'] ? 'passed' : 'failed'),
            [],
            $smtpAccount
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function toggleStatus(SmtpAccount $smtpAccount): RedirectResponse
    {
        $this->assertOwned($smtpAccount);

        $smtpAccount->update(['is_active' => ! $smtpAccount->is_active]);

        return back()->with('success', $smtpAccount->is_active
            ? "\"{$smtpAccount->name}\" is active again."
            : "\"{$smtpAccount->name}\" is paused and will not be used for sending.");
    }

    /** Clears a cooldown after the operator has fixed the underlying problem. */
    public function resetCooldown(SmtpAccount $smtpAccount): RedirectResponse
    {
        $this->assertOwned($smtpAccount);

        $smtpAccount->forceFill([
            'consecutive_failures' => 0,
            'cooldown_until' => null,
        ])->save();

        ActivityLogger::log('smtp.cooldown_cleared', "Cleared cooldown on {$smtpAccount->name}", [], $smtpAccount);

        return back()->with('success', 'Cooldown cleared. Test the connection before the next send.');
    }

    public function destroy(SmtpAccount $smtpAccount): RedirectResponse
    {
        $this->assertOwned($smtpAccount);

        $name = $smtpAccount->name;

        // Soft delete: campaign_logs and campaigns reference this row, and the
        // send history must stay readable.
        $smtpAccount->delete();

        ActivityLogger::log('smtp.deleted', "Deleted SMTP account {$name}");

        return to_route('smtp.index')
            ->with('success', "\"{$name}\" removed. Past sending history is kept.");
    }

    // ----------------------------------------------------------- guards

    /**
     * A tenant may look at its own accounts and at the shared admin ones the
     * scope already filtered to.
     */
    protected function assertVisible(SmtpAccount $account): void
    {
        if ($account->is_global) {
            abort_unless(
                $this->selector->visibleSharedFor(auth()->user()->account)->contains('id', $account->id),
                404,
                'That SMTP account is not available to this account.'
            );

            return;
        }

        abort_unless($account->account_id === auth()->user()->account_id, 404);
    }

    /** Only the tenant's own accounts are editable here. */
    protected function assertOwned(SmtpAccount $account): void
    {
        abort_if(
            $account->is_global,
            403,
            'This SMTP account is provided by KN Softic and can only be changed by an administrator.'
        );

        abort_unless($account->account_id === auth()->user()->account_id, 404);
    }

    protected function assertMayAddCustom(Request $request): void
    {
        $limits = PlanLimits::for($request->user()->account);

        $limits->ensureFeature('allow_custom_smtp', 'your own SMTP accounts');
        $limits->ensure('max_smtp_accounts', 1, SmtpAccount::query()
            ->where('is_global', false)
            ->count());
    }
}
