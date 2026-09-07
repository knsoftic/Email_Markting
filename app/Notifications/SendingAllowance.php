<?php

namespace App\Notifications;

/**
 * The account has crossed a monthly-email-allowance threshold.
 *
 * Sent from AccountNotifier::sendingAllowance(), which compares the counter
 * before a chunk with the counter after it — so each threshold is announced on
 * the one send that crossed it, and never again that month.
 *
 * ── Why 80% and 100%, and nothing in between ────────────────────────────────
 * 80% is early enough to upgrade before a campaign stalls halfway through.
 * 100% is the moment sending actually stops, which is a different fact and
 * needs different words. A 90% step in the middle would add a line nobody
 * would act on differently.
 *
 * An account on an unlimited plan crosses nothing and is told nothing: there
 * is no allowance to be near the end of.
 */
class SendingAllowance extends AccountNotification
{
    /** Percentages of the monthly allowance worth telling somebody about. */
    public const THRESHOLDS = [80, 100];

    public function __construct(
        protected int $percent,
        protected int $used,
        protected int $limit,
    ) {}

    /**
     * Everybody in the account. Running out of allowance stops every send in
     * the product, so it is not news that belongs to one permission group —
     * and the screen it links to, the dashboard, is open to all of them.
     */
    public function permission(): ?string
    {
        return null;
    }

    public function payload(): array
    {
        $atCeiling = $this->percent >= 100;

        return [
            'title' => $atCeiling
                ? 'This month\'s email allowance is used up'
                : 'You have used '.$this->percent.'% of this month\'s email allowance',

            'body' => $atCeiling
                ? sprintf(
                    '%s of %s emails sent this month. Sending is paused until the allowance resets at the start of next month, or until the plan is upgraded. Campaigns part-way through will hold their remaining recipients rather than dropping them.',
                    number_format($this->used),
                    number_format($this->limit),
                )
                : sprintf(
                    '%s of %s emails sent this month, leaving %s. Sending stops when the allowance runs out.',
                    number_format($this->used),
                    number_format($this->limit),
                    number_format(max(0, $this->limit - $this->used)),
                ),

            'level' => $atCeiling ? 'danger' : 'warning',
            'icon' => 'plan',
            'kind' => '',
            'target_id' => 0,
            'route' => 'dashboard',
            'route_param' => '',
            'gone_note' => '',
        ];
    }
}
