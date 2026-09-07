<?php

namespace App\Notifications;

use App\Models\Scopes\AccountScope;
use App\Models\SmtpAccount;

/**
 * An SMTP account has been taken out of rotation after a run of failures.
 *
 * Sent from SmtpSender when SmtpSelector::recordFailure() reports that this
 * failure was the one that tripped the threshold — so it goes out once per
 * cooldown, not once per failed message.
 *
 * ── The reason is read back from the row, never taken from the exception ────
 * SmtpSelector::scrub() strips credentials out of the failure text before
 * storing it, because Symfony's SMTP exceptions quote the server dialogue and
 * a misconfigured server can echo the AUTH line back. Taking the message from
 * the live exception here would put the password the scrubber just removed
 * into a notification row, into the bell dropdown, and into every future page
 * render of that row. So this reads `last_error` — the scrubbed copy — and
 * nothing else.
 *
 * ── Shared accounts do not link to a page the tenant cannot open ────────────
 * A global SMTP account belongs to KN Softic, not to the customer. The
 * customer still needs to know their sending has stalled, so the notification
 * is sent either way, but for a shared account it points at the SMTP list
 * rather than at a detail screen that is not theirs.
 */
class SmtpAccountCooledDown extends AccountNotification
{
    public function __construct(
        protected int $smtpAccountId,
        protected int $cooldownMinutes,
    ) {}

    public function permission(): ?string
    {
        return 'smtp.view';
    }

    public function payload(): array
    {
        // See CampaignCompleted: the deleted-row fallback has to be reachable.
        $row = SmtpAccount::withoutGlobalScope(AccountScope::class)
            ->whereKey($this->smtpAccountId)
            ->first(['id', 'name', 'is_global', 'consecutive_failures', 'last_error']);

        if (! $row) {
            return [
                'title' => 'An SMTP account was paused',
                'body' => 'It failed repeatedly and was taken out of rotation, then removed before the details could be recorded.',
                'level' => 'warning',
                'icon' => 'smtp',
                'kind' => 'smtp_account',
                'target_id' => $this->smtpAccountId,
                'route' => 'smtp.show',
                'route_param' => 'smtpAccount',
                'gone_note' => 'This SMTP account has since been removed.',
            ];
        }

        $isGlobal = (bool) $row->is_global;
        $name = trim((string) $row->name) === '' ? 'An SMTP account' : trim((string) $row->name);
        $failures = max(1, (int) $row->consecutive_failures);
        $reason = trim(preg_replace('/\s+/', ' ', (string) $row->last_error) ?? '');

        $body = sprintf(
            'It failed %d %s in a row, so sending through it is paused for %d %s. Queued mail moves to your other SMTP accounts; if this is the only one, sending stops until the cooldown ends.',
            $failures,
            $failures === 1 ? 'time' : 'times',
            $this->cooldownMinutes,
            $this->cooldownMinutes === 1 ? 'minute' : 'minutes',
        );

        if ($reason !== '') {
            $body .= ' Last error: '.mb_substr($reason, 0, 180).(mb_strlen($reason) > 180 ? '…' : '');
        }

        if ($isGlobal) {
            $body .= ' This is a shared account provided by KN Softic, so it is not yours to edit.';
        }

        return [
            'title' => $name.' has been paused after repeated failures',
            'body' => $body,
            'level' => 'danger',
            'icon' => 'smtp',
            'kind' => $isGlobal ? '' : 'smtp_account',
            'target_id' => $isGlobal ? 0 : (int) $row->id,
            'route' => $isGlobal ? 'smtp.index' : 'smtp.show',
            'route_param' => $isGlobal ? '' : 'smtpAccount',
            'gone_note' => 'This SMTP account has since been removed.',
        ];
    }
}
