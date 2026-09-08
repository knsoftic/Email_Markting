<?php

namespace App\Console\Commands;

use App\Jobs\Imap\SyncMailboxJob;
use App\Models\Scopes\AccountScope;
use App\Models\Mailbox;
use Illuminate\Console\Command;

/**
 * Queues a sync for every mailbox that is due for one.
 *
 * Runs every minute from the scheduler and does almost nothing most of the
 * time: each mailbox carries its own `sync_interval_minutes`, so this only
 * decides who is due. The work itself happens in the queue, because an IMAP
 * fetch can take a minute and the scheduler must not be sitting inside it when
 * the next tick arrives.
 */
class SyncMailboxes extends Command
{
    protected $signature = 'mailboxes:sync
                            {--mailbox= : Sync one mailbox by id, ignoring its schedule}
                            {--force : Ignore the interval and sync everything eligible}';

    protected $description = 'Queue an IMAP sync for every mailbox whose interval has elapsed';

    public function handle(): int
    {
        // Not withoutGlobalScopes(): that also lifts SoftDeletingScope, and a
        // deleted mailbox would go on being polled every minute — spending the
        // provider's rate limit to download mail into an inbox nobody can open.
        $query = Mailbox::withoutGlobalScope(AccountScope::class)
            ->syncable()
            // Nothing is fetched for a suspended account either. Its users
            // cannot open the inbox, so filling it costs the provider's rate
            // limit and the account's storage for mail nobody can read.
            ->whereHas('account', fn ($q) => $q->where('status', 'active'));

        if ($id = $this->option('mailbox')) {
            $query->whereKey((int) $id);
        }

        $due = $query->get()->filter(
            fn (Mailbox $mailbox) => $this->option('force') || $this->option('mailbox') || $mailbox->isDueForSync()
        );

        if ($due->isEmpty()) {
            $this->info('No mailbox is due for a sync.');

            return self::SUCCESS;
        }

        foreach ($due as $mailbox) {
            SyncMailboxJob::dispatch($mailbox->id);

            $this->line(sprintf(
                'Queued %s (every %d min, last synced %s)',
                $mailbox->email,
                $mailbox->sync_interval_minutes,
                $mailbox->last_sync_at?->diffForHumans() ?? 'never'
            ));
        }

        $this->info($due->count().' mailbox sync(es) queued.');

        return self::SUCCESS;
    }
}
