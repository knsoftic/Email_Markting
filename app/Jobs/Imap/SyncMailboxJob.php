<?php

namespace App\Jobs\Imap;

use App\Models\Scopes\AccountScope;
use App\Models\Mailbox;
use App\Notifications\AccountNotifier;
use App\Notifications\MailboxSyncDisabled;
use App\Services\Imap\ImapFailureClassifier;
use App\Services\Imap\MailboxSyncer;
use App\Support\ActivityLogger;
use App\Support\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls new mail for one mailbox.
 *
 * ── Why overlapping is prevented per mailbox ────────────────────────────────
 * Two syncs of the same mailbox at once would both read `last_uid`, both fetch
 * the same messages, and both try to insert them. The unique index would stop
 * the duplicates, but only after two full downloads — and most providers limit
 * simultaneous IMAP connections per account, so the second one often just
 * fails. Different mailboxes still sync in parallel.
 *
 * ── Failure is counted, not just logged ─────────────────────────────────────
 * A mailbox whose password changed will fail every five minutes forever. The
 * failure streak is what lets the UI say "this has been broken since Tuesday"
 * instead of showing the same error afresh each time, and it is what
 * eventually marks the mailbox as needing attention.
 */
class SyncMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $backoff = 60;

    /** Consecutive failures after which the mailbox stops being polled. */
    public const GIVE_UP_AFTER = 10;

    public function __construct(public int $mailboxId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('mailbox-sync:'.$this->mailboxId))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }

    public function handle(TenantManager $tenant, MailboxSyncer $syncer, ImapFailureClassifier $classifier): void
    {
        // See SyncMailboxes: the plural form would sync a deleted mailbox.
        $mailbox = Mailbox::withoutGlobalScope(AccountScope::class)->find($this->mailboxId);

        if (! $mailbox || ! $mailbox->sync_enabled || ! $mailbox->is_active) {
            return;
        }

        // A worker is long-lived, so the tenant is bound explicitly rather than
        // inherited from whatever job ran before this one.
        $tenant->runAs($mailbox->account_id, function () use ($mailbox, $syncer, $classifier) {
            try {
                $result = $syncer->sync($mailbox);

                if ($result['stored'] > 0 || $result['resets'] > 0) {
                    ActivityLogger::log(
                        'mailbox.synced',
                        sprintf(
                            'Synced %s: %d new message(s) across %d folder(s)%s',
                            $mailbox->email,
                            $result['stored'],
                            $result['folders'],
                            $result['resets'] > 0
                                ? sprintf(', %d folder(s) re-synced after the server renumbered them', $result['resets'])
                                : ''
                        ),
                        ['account_id' => $mailbox->account_id],
                        $mailbox
                    );
                }
            } catch (Throwable $e) {
                $this->recordFailure($mailbox, $classifier, $e);

                throw $e;
            }
        });
    }

    protected function recordFailure(Mailbox $mailbox, ImapFailureClassifier $classifier, Throwable $e): void
    {
        $failure = $classifier->classify($e, $mailbox);

        // Atomic: two failing folders in the same pass must not both read the
        // old count and both write count + 1.
        // last_sync_at is stamped here too. A failed pass IS a pass: without
        // this the row kept the timestamp of the last SUCCESSFUL sync, and a
        // card showed "last sync failed 9 hours ago" directly above "failed 2
        // minutes ago" — two lines about the same event, hours apart.
        DB::update(
            'UPDATE mailboxes
                SET consecutive_failures = consecutive_failures + 1,
                    status = ?,
                    last_sync_status = ?,
                    last_sync_at = ?,
                    last_error = ?,
                    last_error_at = ?,
                    updated_at = ?
              WHERE id = ?',
            [
                'error', 'failed', now(),
                mb_substr($failure['summary'].' — '.$failure['message'], 0, 1000),
                now(), now(), $mailbox->id,
            ]
        );

        $failures = (int) Mailbox::withoutGlobalScopes()->whereKey($mailbox->id)->value('consecutive_failures');

        // Polling a mailbox that has failed ten times running is spending the
        // provider's rate limit to relearn the same fact. The row keeps its
        // credentials and the operator can switch it back on after fixing it.
        if ($failures >= self::GIVE_UP_AFTER && $mailbox->sync_enabled) {
            Mailbox::withoutGlobalScopes()->whereKey($mailbox->id)->update([
                'sync_enabled' => false,
                'updated_at' => now(),
            ]);

            ActivityLogger::log(
                'mailbox.sync_disabled',
                sprintf(
                    'Automatic sync switched off for %s after %d consecutive failures: %s',
                    $mailbox->email, $failures, $failure['summary']
                ),
                ['account_id' => $mailbox->account_id],
                $mailbox
            );

            // Inside the same guard as the sync_enabled flip, so it is sent on
            // the pass that gives up and not on every pass after it. Nothing
            // else tells the customer: a mailbox that has stopped being polled
            // simply goes quiet, and the first sign is a reply that never
            // arrived.
            AccountNotifier::send($mailbox->account_id, new MailboxSyncDisabled(
                (int) $mailbox->id,
                (string) $mailbox->email,
                $failures,
                (string) ($failure['summary'] ?? ''),
            ));
        }
    }

    /**
     * After the last retry. The per-attempt bookkeeping already happened in
     * recordFailure(); this only makes sure a job killed by the timeout — which
     * never reaches the catch — does not leave the row saying "running".
     */
    public function failed(Throwable $exception): void
    {
        DB::update(
            "UPDATE mailboxes SET last_sync_status = 'failed', updated_at = ?
              WHERE id = ? AND last_sync_status = 'running'",
            [now(), $this->mailboxId]
        );
    }
}
