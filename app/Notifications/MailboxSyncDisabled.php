<?php

namespace App\Notifications;

/**
 * Automatic IMAP sync has been switched off for a mailbox.
 *
 * Sent from SyncMailboxJob once the failure streak reaches GIVE_UP_AFTER —
 * inside the same guard that flips `sync_enabled` off, which only runs while
 * it was still on, so it fires once and not on every subsequent pass.
 *
 * This one matters more than most: nothing else tells the customer. The
 * mailbox simply stops producing new mail, and without this the first sign is
 * somebody noticing that a reply never arrived.
 */
class MailboxSyncDisabled extends AccountNotification
{
    public function __construct(
        protected int $mailboxId,
        protected string $email,
        protected int $failures,
        protected string $summary = '',
    ) {}

    public function permission(): ?string
    {
        return 'mailboxes.view';
    }

    public function payload(): array
    {
        $email = trim($this->email) === '' ? 'A mailbox' : trim($this->email);
        $summary = trim(preg_replace('/\s+/', ' ', $this->summary) ?? '');

        $body = sprintf(
            'It failed to sync %d times in a row, so it is no longer being polled. No new mail will arrive from it until you fix the connection and switch sync back on.',
            $this->failures,
        );

        if ($summary !== '') {
            $body .= ' Reported: '.mb_substr($summary, 0, 180).(mb_strlen($summary) > 180 ? '…' : '');
        }

        return [
            'title' => 'Sync switched off for '.$email,
            'body' => $body,
            'level' => 'danger',
            'icon' => 'mailbox',
            'kind' => 'mailbox',
            'target_id' => $this->mailboxId,
            'route' => 'mailboxes.show',
            'route_param' => 'mailbox',
            'gone_note' => 'This mailbox has since been removed, so there is nothing left to reconnect.',
        ];
    }
}
