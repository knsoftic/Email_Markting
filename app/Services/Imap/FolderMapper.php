<?php

namespace App\Services\Imap;

use App\Models\Mailbox;
use App\Models\MailboxFolder;
use Illuminate\Support\Collection;

/**
 * Works out which remote folder is the Inbox, which is Sent, and so on.
 *
 * ── Why names are the last resort, not the first ────────────────────────────
 * Every provider names these differently — "Sent", "Sent Items", "Sent Mail",
 * "[Gmail]/Sent Mail", "Enviados", "Gesendet". Matching on names is how an
 * inbox ends up showing a German user's drafts as their sent mail.
 *
 * RFC 6154 SPECIAL-USE solves this properly: the server itself tells us which
 * folder is \Sent, \Drafts, \Junk, \Trash or \Archive. Nearly every provider
 * worth syncing supports it. So the order here is:
 *
 *   1. the server's own SPECIAL-USE attribute      (authoritative)
 *   2. a known name, in several languages          (a good guess)
 *   3. "custom"                                     (honest about not knowing)
 *
 * Anything left as custom is still synced and still shown — it is simply not
 * claimed to be one of the five well-known folders.
 */
class FolderMapper
{
    /** RFC 6154 attributes, lower-cased, mapped to our folder types. */
    protected const SPECIAL_USE = [
        '\\inbox' => 'inbox',
        '\\sent' => 'sent',
        '\\drafts' => 'drafts',
        '\\junk' => 'spam',
        '\\trash' => 'trash',
        '\\archive' => 'archive',
        '\\all' => 'archive',
    ];

    /**
     * Fallback name matching. Lower-cased, delimiter-stripped, and matched on
     * the LAST path segment so "[Gmail]/Sent Mail" is judged on "sent mail".
     *
     * @var array<string, array<int, string>>
     */
    protected const NAMES = [
        'inbox' => ['inbox', 'inbox.'],
        'sent' => ['sent', 'sent items', 'sent mail', 'sent messages', 'enviados', 'gesendet',
            'envoyés', 'envoyes', 'inviati', 'verzonden', 'skickat', 'wyslane', 'gönderilmiş'],
        'drafts' => ['drafts', 'draft', 'borradores', 'entwürfe', 'entwurfe', 'brouillons',
            'bozze', 'concepten', 'utkast', 'taslaklar'],
        'spam' => ['spam', 'junk', 'junk e-mail', 'junk email', 'bulk mail', 'correo no deseado',
            'unerwünscht', 'pourriel', 'indésirables'],
        'trash' => ['trash', 'deleted', 'deleted items', 'deleted messages', 'bin', 'papelera',
            'papierkorb', 'corbeille', 'cestino', 'prullenbak', 'çöp'],
        'archive' => ['archive', 'archives', 'all mail', 'archivo', 'archiv', 'archivio'],
    ];

    /**
     * Folders that exist to hold other folders and cannot be opened, plus the
     * ones nobody wants synced into an inbox view.
     */
    protected const NEVER_SYNC = ['trash', 'spam'];

    /**
     * Reconciles the remote folder list into mailbox_folders.
     *
     * Folders that have disappeared remotely are left in place rather than
     * deleted: their messages are still in our database, and removing the row
     * would orphan them behind a foreign key. Their sync flag is cleared
     * instead, so we stop asking for a folder that is not there.
     *
     * @param  Collection<int, array{path: string, name: string, delimiter: string, attributes: array<int, string>, no_select: bool}>  $remote
     * @return Collection<int, MailboxFolder>
     */
    public function reconcile(Mailbox $mailbox, Collection $remote): Collection
    {
        $seen = [];

        foreach ($remote as $folder) {
            $path = (string) ($folder['path'] ?? '');

            if ($path === '') {
                continue;
            }

            $type = $this->typeFor($folder);
            $selectable = ! ($folder['no_select'] ?? false);

            $row = MailboxFolder::query()->firstOrNew([
                'mailbox_id' => $mailbox->id,
                'path' => $path,
            ]);

            $row->fill([
                'display_name' => (string) ($folder['name'] ?? $path),
                'delimiter' => mb_substr((string) ($folder['delimiter'] ?? '/'), 0, 5) ?: '/',
                // A container folder cannot be selected, so it can never be
                // synced — asking for its messages is an error, not an empty
                // result.
                'is_syncable' => $selectable && ! in_array($type, self::NEVER_SYNC, true),
            ]);

            // The type is only set on first sight. Re-deriving it every sync
            // would silently reclassify a folder an operator had corrected.
            if (! $row->exists) {
                $row->type = $type;
            }

            $row->save();

            $seen[] = $row->id;
        }

        // Gone from the server: stop syncing, keep the messages.
        MailboxFolder::query()
            ->where('mailbox_id', $mailbox->id)
            ->when($seen !== [], fn ($q) => $q->whereNotIn('id', $seen))
            ->update(['is_syncable' => false, 'updated_at' => now()]);

        return MailboxFolder::query()->where('mailbox_id', $mailbox->id)->orderBy('path')->get();
    }

    /**
     * @param  array{path?: string, name?: string, attributes?: array<int, string>}  $folder
     */
    public function typeFor(array $folder): string
    {
        // 1. What the server says about itself.
        foreach (($folder['attributes'] ?? []) as $attribute) {
            $key = mb_strtolower(trim((string) $attribute));

            if (isset(self::SPECIAL_USE[$key])) {
                return self::SPECIAL_USE[$key];
            }
        }

        $path = (string) ($folder['path'] ?? '');
        $name = mb_strtolower(trim((string) ($folder['name'] ?? '')));

        // INBOX is defined by RFC 3501 to be case-insensitive and always the
        // root mailbox, so it does not need the name table.
        if (mb_strtolower($path) === 'inbox') {
            return 'inbox';
        }

        // 2. A recognisable name, judged on the last segment only.
        $leaf = $name !== '' ? $name : mb_strtolower($this->leaf($path));

        foreach (self::NAMES as $type => $candidates) {
            if (in_array($leaf, $candidates, true)) {
                return $type;
            }
        }

        // 3. Honest about not knowing.
        return 'custom';
    }

    protected function leaf(string $path): string
    {
        foreach (['/', '.', '\\'] as $delimiter) {
            if (str_contains($path, $delimiter)) {
                $path = (string) mb_strrchr($path, $delimiter);
                $path = mb_substr($path, 1);
            }
        }

        return trim($path);
    }
}
