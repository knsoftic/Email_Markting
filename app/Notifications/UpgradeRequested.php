<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Scopes\AccountScope;

/**
 * A customer has asked to change plan.
 *
 * This product takes no payments: money is collected outside it and the
 * operator assigns the plan by hand. That leaves one gap the customer cannot
 * cross on their own — telling somebody they want to. Before this, the only
 * route was the support address in the page footer, and a customer who missed
 * it had none at all.
 *
 * It goes to the super admins because a super admin belongs to no account, so
 * `AccountNotifier::send()` — which notifies the account's own people — can
 * never reach them.
 */
class UpgradeRequested extends AccountNotification
{
    public function __construct(
        protected int $accountId,
        protected ?int $planId,
        protected ?string $note = null,
    ) {}

    public function payload(): array
    {
        $account = Account::withoutGlobalScope(AccountScope::class)->find($this->accountId);

        $wanted = $this->planId
            ? Plan::withoutGlobalScope(AccountScope::class)->find($this->planId)
            : null;

        $name = $account?->name ?? 'An account';

        $body = $wanted
            ? "{$name} wants to move to the {$wanted->name} plan."
            : "{$name} has asked about changing plan.";

        if ($this->note) {
            $body .= ' They said: "'.mb_substr($this->note, 0, 160).'"';
        }

        $body .= ' Nothing has changed — assign the plan when you have been paid.';

        return [
            'title' => 'Plan change requested',
            'body' => $body,
            'level' => 'info',
            'icon' => 'plan',
            'kind' => 'account',
            'target_id' => $this->accountId,
            // Straight to the screen where the operator actually does it.
            'route' => 'admin.accounts.show',
            'route_param' => 'account',
            'gone_note' => 'That account has since been deleted.',
        ];
    }
}
