<?php

namespace App\Services\Inbox;

use App\Models\Email;
use App\Models\Mailbox;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Listing, filtering and bulk actions for the inbox.
 *
 * ── The folders here are ours, not the server's ─────────────────────────────
 * `folder_type` is the normalised type the sync assigned; the remote folder is
 * kept alongside it. That is deliberate: a user with three mailboxes has three
 * different "Sent" folders on three different servers, and one Sent view is
 * what they want to look at. Moving a message here moves it in OUR copy — this
 * application never writes back over IMAP, and the UI says so rather than
 * implying the change reached the mail server.
 */
class InboxService
{
    /** The views the sidebar offers, and what each one means. */
    public const VIEWS = ['inbox', 'sent', 'drafts', 'starred', 'spam', 'trash', 'archive'];

    /**
     * One page of a folder.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(string $view, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($view, $filters)
            ->with(['mailbox:id,name,email'])
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(string $view, array $filters = []): Builder
    {
        $query = Email::query();

        // "Starred" is a flag, not a folder: a starred message is still in
        // whatever folder it was in. Trash is the one thing it must exclude —
        // showing deleted mail under a star is how people lose track of what
        // they actually still have.
        if ($view === 'starred') {
            $query->where('is_starred', true)->where('folder_type', '!=', 'trash');
        } else {
            $query->where('folder_type', $view === 'drafts' ? 'drafts' : $view);
        }

        $query
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->search($term))
            ->when(($filters['unread'] ?? null) === '1', fn ($q) => $q->where('is_read', false))
            ->when(($filters['attachments'] ?? null) === '1', fn ($q) => $q->where('has_attachments', true))
            ->when($filters['mailbox'] ?? null, fn ($q, $id) => $q->where('mailbox_id', (int) $id));

        // Drafts and sent mail are ordered by when we last touched them;
        // received mail by when it arrived. Sorting a draft by received_at
        // (which is null) puts every draft in a random pile.
        return in_array($view, ['drafts', 'sent'], true)
            ? $query->orderByRaw('COALESCE(sent_at, updated_at) DESC')
            : $query->orderByRaw('COALESCE(received_at, created_at) DESC');
    }

    /**
     * Unread and total counts for the folder rail, in one query rather than
     * one per folder.
     *
     * @return array<string, array{total: int, unread: int}>
     */
    public function counts(): array
    {
        $rows = Email::query()
            ->selectRaw('folder_type, COUNT(*) AS total, SUM(is_read = 0) AS unread')
            ->groupBy('folder_type')
            ->get();

        $counts = [];

        foreach (self::VIEWS as $view) {
            $counts[$view] = ['total' => 0, 'unread' => 0];
        }

        foreach ($rows as $row) {
            if (isset($counts[$row->folder_type])) {
                $counts[$row->folder_type] = [
                    'total' => (int) $row->total,
                    'unread' => (int) $row->unread,
                ];
            }
        }

        $counts['starred'] = [
            'total' => (int) Email::query()->where('is_starred', true)
                ->where('folder_type', '!=', 'trash')->count(),
            'unread' => (int) Email::query()->where('is_starred', true)
                ->where('folder_type', '!=', 'trash')->where('is_read', false)->count(),
        ];

        return $counts;
    }

    /**
     * The mailboxes this account can filter by.
     *
     * @return Collection<int, Mailbox>
     */
    public function mailboxes(): Collection
    {
        return Mailbox::query()->orderBy('name')->get(['id', 'name', 'email']);
    }

    /**
     * Applies one action to a set of messages.
     *
     * Every id is re-resolved through the tenant-scoped model, so a posted id
     * belonging to another account matches nothing rather than being acted on.
     *
     * @param  array<int, int|string>  $ids
     * @return array{count: int, message: string}
     */
    public function bulk(string $action, array $ids): array
    {
        $ids = collect($ids)->filter(fn ($id) => is_scalar($id))->map(fn ($id) => (int) $id)
            ->filter()->unique()->values()->all();

        if ($ids === []) {
            return ['count' => 0, 'message' => 'Nothing was selected.'];
        }

        $query = Email::query()->whereIn('id', $ids);

        return match ($action) {
            'read' => $this->applied($query->update(['is_read' => true, 'updated_at' => now()]), 'marked as read'),
            'unread' => $this->applied($query->update(['is_read' => false, 'updated_at' => now()]), 'marked as unread'),
            'star' => $this->applied($query->update(['is_starred' => true, 'updated_at' => now()]), 'starred'),
            'unstar' => $this->applied($query->update(['is_starred' => false, 'updated_at' => now()]), 'unstarred'),
            'important' => $this->applied($query->update(['is_important' => true, 'updated_at' => now()]), 'flagged as important'),

            // The inverse. Without it the flag was one-way: a message could be
            // marked important and nothing anywhere could unmark it, which is
            // a control that only half works.
            'unimportant' => $this->applied($query->update(['is_important' => false, 'updated_at' => now()]), 'no longer flagged as important'),

            // Moving to trash is not deleting: the row stays, so it can come
            // back. Emptying the trash is the destructive one, and it is a
            // separate, explicit action.
            'trash' => $this->applied($query->update(['folder_type' => 'trash', 'updated_at' => now()]), 'moved to Trash'),
            'spam' => $this->applied($query->update(['folder_type' => 'spam', 'is_read' => true, 'updated_at' => now()]), 'moved to Spam'),
            'archive' => $this->applied($query->update(['folder_type' => 'archive', 'updated_at' => now()]), 'archived'),
            'inbox' => $this->applied($query->update(['folder_type' => 'inbox', 'updated_at' => now()]), 'moved back to the Inbox'),

            // Only from the trash, and it really is gone — forceDelete, not
            // delete: Email uses SoftDeletes, so an ordinary delete would leave
            // the row (and its attachment files, and the storage it occupies)
            // behind a label that promised otherwise.
            'delete' => $this->applied($this->purge($ids), 'deleted for good'),

            default => ['count' => 0, 'message' => 'That action is not available.'],
        };
    }

    /**
     * Removes messages permanently, with the files they carry.
     *
     * Scoped to the trash: "delete for good" from anywhere else would be an
     * action nobody could undo, offered from a screen that did not warn them.
     *
     * @param  array<int, int>  $ids
     */
    protected function purge(array $ids): int
    {
        $emails = Email::query()->whereIn('id', $ids)->where('folder_type', 'trash')
            ->with('attachments')->get();

        if ($emails->isEmpty()) {
            return 0;
        }

        foreach ($emails as $email) {
            foreach ($email->attachments as $attachment) {
                \Illuminate\Support\Facades\Storage::disk($attachment->disk)->delete($attachment->path);
            }
        }

        $ids = $emails->pluck('id')->all();

        DB::table('email_attachments')->whereIn('email_id', $ids)->delete();

        return Email::query()->whereIn('id', $ids)->forceDelete();
    }

    /**
     * @return array{count: int, message: string}
     */
    protected function applied(int $count, string $verb): array
    {
        return [
            'count' => $count,
            'message' => $count === 1
                ? '1 message '.$verb.'.'
                : number_format($count).' messages '.$verb.'.',
        ];
    }

    /**
     * Empties the trash for this account.
     *
     * Hard deletes: the attachments go with the rows through the foreign key,
     * and the files are removed separately by the caller. "Empty trash" that
     * leaves the files on disk is a storage quota that never goes down.
     *
     * @return array{messages: int, files: int}
     */
    public function emptyTrash(): array
    {
        $emails = Email::query()->where('folder_type', 'trash')->with('attachments')->get();

        $files = 0;

        foreach ($emails as $email) {
            foreach ($email->attachments as $attachment) {
                if (\Illuminate\Support\Facades\Storage::disk($attachment->disk)->exists($attachment->path)) {
                    \Illuminate\Support\Facades\Storage::disk($attachment->disk)->delete($attachment->path);
                    $files++;
                }
            }
        }

        $ids = $emails->pluck('id')->all();

        if ($ids !== []) {
            DB::table('email_attachments')->whereIn('email_id', $ids)->delete();
            Email::query()->whereIn('id', $ids)->forceDelete();
        }

        return ['messages' => count($ids), 'files' => $files];
    }
}
