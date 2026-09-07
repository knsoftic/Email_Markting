<?php

namespace App\Services\Imap;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\MailboxFolder;
use App\Services\Inbox\ReplyMatcher;
use App\Support\PlanLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pulls new messages from a mailbox into the database.
 *
 * ── UIDVALIDITY is the rule that makes incremental sync safe ────────────────
 * IMAP UIDs are only meaningful while the folder's UIDVALIDITY is unchanged.
 * If the server renumbers a folder — a restore from backup, a migration
 * between servers, some providers on a whim — every UID we stored now points
 * at a different message or at nothing. Continuing from `last_uid` after that
 * silently skips everything, and the mailbox looks empty forever with no error
 * anywhere. So the first thing every folder sync does is compare UIDVALIDITY
 * and, if it moved, start again from zero.
 *
 * ── Why dedupe is a unique index, not a lookup ──────────────────────────────
 * `(mailbox_id, message_id)` is unique in the schema. Two syncs racing, or a
 * resync after a UIDVALIDITY reset, both try to insert the same message; the
 * index decides, not a SELECT that another process invalidates a millisecond
 * later. A message with no Message-ID of its own (drafts and some scanners
 * omit it) gets a synthetic one derived from the folder and UID, so it is
 * still stable across syncs.
 *
 * ── Nothing here trusts the remote content ──────────────────────────────────
 * The HTML body of an incoming email is the most hostile input this
 * application handles. It is stored raw — throwing away the original would
 * make the inbox lie about what arrived — and sanitised at render time, where
 * the context is known.
 */
class MailboxSyncer
{
    /** Messages pulled per folder per pass, unless the mailbox says less. */
    public const DEFAULT_LIMIT = 100;

    /** Hard ceiling regardless of what the row asks for. */
    public const MAX_LIMIT = 500;

    public function __construct(
        protected ImapGateway $gateway,
        protected FolderMapper $mapper,
        protected ReplyMatcher $replies,
    ) {}

    /**
     * Runs one pass over a mailbox.
     *
     * @return array{folders: int, fetched: int, stored: int, skipped: int, attachments: int, resets: int, errors: array<int, string>}
     */
    public function sync(Mailbox $mailbox): array
    {
        $result = ['folders' => 0, 'fetched' => 0, 'stored' => 0, 'skipped' => 0,
            'attachments' => 0, 'resets' => 0, 'errors' => []];

        $mailbox->forceFill([
            'last_sync_started_at' => now(),
            'last_sync_status' => 'running',
        ])->save();

        $folders = $this->mapper->reconcile($mailbox, $this->gateway->folders($mailbox));

        foreach ($folders->where('is_syncable', true) as $folder) {
            try {
                $folderResult = $this->syncFolder($mailbox, $folder);

                $result['folders']++;
                $result['fetched'] += $folderResult['fetched'];
                $result['stored'] += $folderResult['stored'];
                $result['skipped'] += $folderResult['skipped'];
                $result['attachments'] += $folderResult['attachments'];
                $result['resets'] += $folderResult['reset'] ? 1 : 0;
            } catch (Throwable $e) {
                // One unreadable folder must not abandon the others. A shared
                // mailbox commonly has one folder the account cannot open.
                $result['errors'][] = $folder->path.': '.mb_substr($e->getMessage(), 0, 200);
            }
        }

        $this->refreshCounts($mailbox);

        $mailbox->forceFill([
            'last_sync_at' => now(),
            'last_sync_status' => $result['errors'] === [] ? 'success' : 'failed',
            'status' => 'connected',
            'consecutive_failures' => 0,
            'last_error' => $result['errors'] === [] ? null : implode(' | ', $result['errors']),
            'last_error_at' => $result['errors'] === [] ? null : now(),
        ])->save();

        return $result;
    }

    /**
     * @return array{fetched: int, stored: int, skipped: int, attachments: int, reset: bool}
     */
    public function syncFolder(Mailbox $mailbox, MailboxFolder $folder): array
    {
        $state = $this->gateway->folderState($mailbox, $folder->path);
        $reset = false;

        // The one check that keeps incremental sync honest.
        if ($folder->uid_validity !== null
            && (int) $folder->uid_validity !== 0
            && (int) $folder->uid_validity !== (int) $state['uid_validity']) {
            $folder->forceFill(['last_uid' => 0])->save();
            $reset = true;
        }

        $folder->forceFill([
            'uid_validity' => (int) $state['uid_validity'],
            'messages_count' => (int) $state['messages'],
        ])->save();

        $limit = min(self::MAX_LIMIT, max(1, (int) ($mailbox->sync_limit ?: self::DEFAULT_LIMIT)));

        $messages = $this->gateway->messages($mailbox, $folder->path, (int) $folder->last_uid, $limit);

        $stored = 0;
        $skipped = 0;
        $attachments = 0;
        $highestUid = (int) $folder->last_uid;

        foreach ($messages as $message) {
            $uid = (int) ($message['uid'] ?? 0);
            $highestUid = max($highestUid, $uid);

            $email = $this->store($mailbox, $folder, $message);

            if ($email === null) {
                $skipped++;

                continue;
            }

            $stored++;
            $attachments += $this->storeAttachments($mailbox, $email, (array) ($message['attachments'] ?? []));

            // Attributed as it arrives, not on a later pass: a reply that sits
            // unlinked until somebody runs a backfill is a reply nobody sees
            // in the campaign it answers. A failure here must not lose the
            // message — it is already stored, and the matcher can be re-run.
            try {
                $this->replies->apply($email);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $folder->forceFill([
            // Only ever forward. A partial batch must not rewind the cursor and
            // re-fetch what has already been stored.
            'last_uid' => max((int) $folder->last_uid, $highestUid),
            'last_sync_at' => now(),
        ])->save();

        return [
            'fetched' => $messages->count(),
            'stored' => $stored,
            'skipped' => $skipped,
            'attachments' => $attachments,
            'reset' => $reset,
        ];
    }

    /**
     * Stores one message, or returns null when it is already known.
     *
     * @param  array<string, mixed>  $message
     */
    protected function store(Mailbox $mailbox, MailboxFolder $folder, array $message): ?Email
    {
        $messageId = $this->messageIdFor($folder, $message);

        // The unique index is the arbiter, not this check — but asking first
        // avoids throwing on every message of an already-synced folder.
        if (Email::withoutGlobalScopes()
            ->where('mailbox_id', $mailbox->id)
            ->where('message_id', $messageId)
            ->exists()) {
            return null;
        }

        $received = $message['date'] instanceof Carbon ? $message['date'] : now();
        $thread = $this->threadFor($mailbox, $message, $messageId, $received);

        try {
            $email = Email::withoutGlobalScopes()->create([
                'account_id' => $mailbox->account_id,
                'mailbox_id' => $mailbox->id,
                'mailbox_folder_id' => $folder->id,
                'email_thread_id' => $thread->id,
                'user_id' => $mailbox->user_id,

                'direction' => $folder->type === 'sent' ? 'outgoing' : 'incoming',
                'folder_type' => in_array($folder->type, ['inbox', 'sent', 'drafts', 'spam', 'trash', 'archive'], true)
                    ? $folder->type
                    : 'inbox',

                'message_id' => $messageId,
                'in_reply_to' => $message['in_reply_to'] ?? null,
                'references' => $message['references'] ?? null,
                'uid' => (int) ($message['uid'] ?? 0),

                'from_name' => $this->clip($message['from_name'] ?? null, 191),
                'from_email' => $this->clip($message['from_email'] ?? null, 191),
                'to' => $message['to'] ?? [],
                'cc' => $message['cc'] ?? [],
                'reply_to' => $this->clip($message['reply_to'] ?? null, 191),

                'subject' => $this->clip($message['subject'] ?? null, 255),
                'preview' => $this->preview($message),
                // Stored raw and sanitised at render time: throwing away the
                // original would make the inbox lie about what arrived.
                'body_html' => $message['body_html'] ?? null,
                'body_text' => $message['body_text'] ?? null,

                'size' => (int) ($message['size'] ?? 0),
                'is_read' => (bool) ($message['seen'] ?? false),
                'is_starred' => (bool) ($message['flagged'] ?? false),
                'is_draft' => (bool) ($message['draft'] ?? false) || $folder->type === 'drafts',

                'received_at' => $received,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Another worker got there first. Not an error — the message is in
            // the database, which is the outcome we wanted.
            return null;
        }

        $this->touchThread($thread, $email);

        return $email;
    }

    /**
     * A stable identity for the message.
     *
     * Drafts and some gateways send no Message-ID at all. Falling back to
     * something derived from the folder and UID keeps the unique index doing
     * its job, and keeps a re-sync from storing the same message twice.
     *
     * @param  array<string, mixed>  $message
     */
    protected function messageIdFor(MailboxFolder $folder, array $message): string
    {
        $id = trim((string) ($message['message_id'] ?? ''));

        if ($id !== '') {
            return mb_substr($id, 0, 191);
        }

        return 'kn-uid-'.$folder->id.'-'.(int) ($message['uid'] ?? 0).'@local';
    }

    /**
     * Finds or creates the conversation this message belongs to.
     *
     * The key is the ROOT of the reference chain — the first id in References,
     * falling back to In-Reply-To, falling back to the message's own id. Using
     * the immediate parent instead would split a long thread into a chain of
     * two-message conversations.
     *
     * @param  array<string, mixed>  $message
     */
    protected function threadFor(Mailbox $mailbox, array $message, string $messageId, Carbon $received): EmailThread
    {
        $key = $this->threadKey($message, $messageId);

        $thread = EmailThread::withoutGlobalScopes()->firstOrNew([
            'account_id' => $mailbox->account_id,
            'thread_key' => $key,
        ]);

        if (! $thread->exists) {
            $thread->fill([
                'mailbox_id' => $mailbox->id,
                // The subject of the first message seen wins; replies carry
                // "Re:" prefixes that would rewrite the thread title each time.
                'subject' => $this->clip($message['subject'] ?? null, 255),
                'reply_status' => 'new',
                'last_message_at' => $received,
            ]);

            $thread->save();
        }

        return $thread;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function threadKey(array $message, string $messageId): string
    {
        $references = trim((string) ($message['references'] ?? ''));

        if ($references !== '' && preg_match('/<([^>]+)>/', $references, $m)) {
            return mb_substr($m[1], 0, 191);
        }

        $inReplyTo = trim((string) ($message['in_reply_to'] ?? ''));

        if ($inReplyTo !== '') {
            return mb_substr(trim($inReplyTo, '<> '), 0, 191);
        }

        return $messageId;
    }

    protected function touchThread(EmailThread $thread, Email $email): void
    {
        DB::update(
            'UPDATE email_threads
                SET messages_count = messages_count + 1,
                    unread_count = unread_count + ?,
                    last_message_at = GREATEST(COALESCE(last_message_at, ?), ?),
                    updated_at = ?
              WHERE id = ?',
            [
                $email->is_read ? 0 : 1,
                $email->received_at, $email->received_at,
                now(), $thread->id,
            ]
        );
    }

    /**
     * Downloads the attachments, within the limits the plan and the config set.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     */
    protected function storeAttachments(Mailbox $mailbox, Email $email, array $attachments): int
    {
        if ($attachments === []) {
            return 0;
        }

        $maxBytes = max(1, (int) config('knsoftic.max_attachment_kb', 10240)) * 1024;
        $limits = PlanLimits::for($mailbox->account);
        $stored = 0;

        foreach ($attachments as $attachment) {
            $content = (string) ($attachment['content'] ?? '');
            $size = strlen($content);

            if ($size === 0) {
                continue;
            }

            // Too big for the install's own ceiling. The message is kept — a
            // missing attachment is better than a missing email — and the
            // omission is visible because the counts will not agree.
            if ($size > $maxBytes) {
                continue;
            }

            // The plan's storage allowance, checked per file rather than once
            // per message: a single message can carry twenty attachments.
            if (! $limits->hasRoomFor('max_storage_mb', (int) ceil($size / 1048576))) {
                break;
            }

            $name = $this->clip((string) ($attachment['name'] ?? 'attachment'), 191) ?: 'attachment';
            $path = sprintf(
                'attachments/%d/%d/%s',
                $mailbox->account_id,
                $email->id,
                Str::uuid().'.'.$this->extensionFor($name)
            );

            Storage::disk('local')->put($path, $content);

            EmailAttachment::withoutGlobalScopes()->create([
                'email_id' => $email->id,
                'account_id' => $mailbox->account_id,
                'name' => $name,
                'mime_type' => $this->clip((string) ($attachment['mime'] ?? ''), 127) ?: 'application/octet-stream',
                'size' => $size,
                'disk' => 'local',
                'path' => $path,
                'content_id' => $this->clip((string) ($attachment['content_id'] ?? ''), 191),
                'is_inline' => (bool) ($attachment['inline'] ?? false),
            ]);

            $stored++;
        }

        if ($stored > 0) {
            $email->forceFill([
                'has_attachments' => true,
                'attachments_count' => $stored,
            ])->save();
        }

        return $stored;
    }

    /**
     * The filename extension, from the name only.
     *
     * Never from the MIME type the sender claimed: a message asserting
     * `image/png` for a `.php` payload should not get us to write a `.png`
     * that is really a script. The file is stored on the private local disk
     * under a UUID name anyway, so its extension is descriptive, not load
     * bearing.
     */
    protected function extensionFor(string $name): string
    {
        $extension = mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) ? $extension : 'bin';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function preview(array $message): ?string
    {
        $text = (string) ($message['body_text'] ?? '');

        if ($text === '') {
            $text = strip_tags((string) ($message['body_html'] ?? ''));
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text !== '' ? mb_substr($text, 0, 255) : null;
    }

    protected function clip(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $length) : null;
    }

    /** Recomputes the mailbox's message and unread totals from the rows. */
    public function refreshCounts(Mailbox $mailbox): void
    {
        $counts = DB::table('emails')
            ->where('mailbox_id', $mailbox->id)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) AS total, SUM(is_read = 0) AS unread')
            ->first();

        $mailbox->forceFill([
            'messages_count' => (int) ($counts->total ?? 0),
            'unread_count' => (int) ($counts->unread ?? 0),
        ])->save();
    }
}
