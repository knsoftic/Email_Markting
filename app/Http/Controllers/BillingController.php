<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Scopes\AccountScope;
use App\Notifications\AccountNotifier;
use App\Notifications\UpgradeRequested;
use App\Services\SettingsService;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * What the customer's plan is, what it costs them, and how to change it.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 * This product takes no payments. There is no gateway, no checkout and no
 * card handling anywhere in it — money is collected outside, and the operator
 * assigns the plan by hand. That is a legitimate way to run a small platform,
 * but until now the customer's half of it was missing entirely: they could see
 * "List limit reached — upgrade your plan" and had no way to find out what the
 * other plans were, what they cost, how to pay, or how to ask.
 *
 * So this screen is not a shop. It is the honest version of a manual process:
 * here is your plan, here is what you are using, here is what else exists, here
 * is how payment works, and here is a button that tells the operator you want
 * to move.
 *
 * ── It gives the payment settings a reader ──────────────────────────────────
 * `Admin → Payment settings` has always stored a currency, bank details and
 * payment instructions, and nothing anywhere read them. This is what reads
 * them. If the operator has not filled them in, the screen says to get in touch
 * rather than showing an empty box pretending to be instructions.
 */
class BillingController extends Controller
{
    /** Limits worth showing a customer, in the order they care about them. */
    protected const SHOWN = [
        'max_contacts' => 'Contacts',
        'max_emails_per_month' => 'Emails this month',
        'max_lists' => 'Lists',
        'max_templates' => 'Templates',
        'max_automations' => 'Automations',
        'max_mailboxes' => 'Mailboxes',
        'max_team_members' => 'Team members',
        'max_smtp_accounts' => 'SMTP accounts',
    ];

    public function __construct(protected SettingsService $settings) {}

    public function index(Request $request): View
    {
        $account = $request->user()->account;
        $subscription = $account?->subscription;
        $limits = PlanLimits::for($account);

        $usage = [];

        foreach (self::SHOWN as $key => $label) {
            $limit = $limits->limit($key);

            // A limit of 0 is a switched-off capability, not a bar at zero.
            // Showing "0 of 0" beside a progress bar would read as a fault.
            if ($limit === 0 || $limit === false) {
                continue;
            }

            $used = $limits->usageFor($key);

            /*
             * An operator can grant one account more than its plan allows, and
             * that override wins. Without saying so the screen contradicts
             * itself: the usage bar reads "60 of 5,000" while the plan card
             * beside it says 2,000, and the customer has no way to tell which
             * is wrong. Neither is — so the screen says which number is theirs.
             */
            $planValue = $subscription?->plan?->{$key};
            $adjusted = $planValue !== $limit;

            $usage[] = [
                'label' => $label,
                'used' => $used,
                'limit' => $limit,
                'unlimited' => $limit === null,
                'adjusted' => $adjusted,
                'plan_value' => $planValue,
                'percent' => $limit === null || $limit <= 0
                    ? 0
                    : min(100, (int) round($used / $limit * 100)),
            ];
        }

        return view('billing.index', [
            'account' => $account,
            'subscription' => $subscription,
            'plan' => $subscription?->plan,
            'usage' => $usage,
            // Public plans only. A plan the operator keeps off the list is a
            // private arrangement, and advertising it to everybody else would
            // undo the reason it is private.
            'plans' => Plan::withoutGlobalScope(AccountScope::class)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('is_public', true)
                ->orderBy('sort_order')->orderBy('price')
                ->get(),
            'payment' => array_merge(
                SettingsService::DEFAULTS['payment'],
                $this->settings->group('payment')
            ),
            'supportEmail' => $this->settings->brand('support_email'),
            'shown' => self::SHOWN,
        ]);
    }

    /**
     * Tells the operator this customer wants to move.
     *
     * Changes nothing about the account. The screen says so, because a button
     * that looks like it bought something and did not is worse than no button.
     */
    public function requestUpgrade(Request $request): RedirectResponse
    {
        $account = $request->user()->account;

        abort_unless($account, 404);

        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')->where(
                fn ($q) => $q->whereNull('deleted_at')->where('is_active', true)->where('is_public', true)
            )],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $planId = $data['plan_id'] ?? null;
        $note = $data['note'] ?? null;

        $wanted = $planId
            ? Plan::withoutGlobalScope(AccountScope::class)->find($planId)
            : null;

        // Recorded first, and separately from the notification. The activity
        // log is the operator's fallback: if notifications are broken, the
        // request is still written down somewhere they can find it.
        ActivityLogger::log(
            'billing.upgrade_requested',
            $wanted
                ? "Asked to move to the {$wanted->name} plan"
                : 'Asked about changing plan',
            ['plan_id' => $planId, 'note' => $note],
            $account
        );

        AccountNotifier::sendToSuperAdmins(new UpgradeRequested($account->id, $planId, $note));

        return back()->with('success',
            'Your request has been sent. Nothing on your account has changed yet — '
            .'we will be in touch about payment, and your new plan starts once that is settled.');
    }
}
