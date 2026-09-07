<?php

namespace App\Services\Imap;

use App\Models\Mailbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

/**
 * The real IMAP gateway: webklex/php-imap on one side, plain arrays on the
 * other.
 *
 * Everything library-shaped stops here. If webklex changes its Message API
 * again, this file changes and nothing else does.
 *
 * The client is opened once per gateway instance and reused across folders in
 * the same sync — reconnecting per folder would triple the round trips and, on
 * providers that limit simultaneous IMAP connections, get us throttled.
 */
class WebklexImapGateway implements ImapGateway
{
    protected ?Client $client = null;

    protected ?int $clientFor = null;

    public function __construct(protected ImapClientFactory $factory) {}

    public function folders(Mailbox $mailbox): Collection
    {
        $folders = $this->client($mailbox)->getFolders(false);

        return Collection::make($folders)->map(fn ($folder) => [
            'path' => (string) $folder->path,
            'name' => (string) ($folder->name ?: $folder->path),
            'delimiter' => (string) ($folder->delimiter ?: '/'),
            'attributes' => array_map('strval', (array) ($folder->attributes ?? [])),
            'no_select' => (bool) ($folder->no_select ?? false),
        ])->values();
    }

    public function folderState(Mailbox $mailbox, string $path): array
    {
        $folder = $this->client($mailbox)->getFolderByPath($path);

        // EXAMINE rather than SELECT: read-only, so merely looking at a folder
        // cannot clear anybody's \Recent flags.
        $status = $folder->examine();

        return [
            'uid_validity' => (int) ($status['uidvalidity'] ?? 0),
            'uid_next' => (int) ($status['uidnext'] ?? 0),
            'messages' => (int) ($status['exists'] ?? 0),
        ];
    }

    public function messages(Mailbox $mailbox, string $path, int $sinceUid, int $limit): Collection
    {
        $folder = $this->client($mailbox)->getFolderByPath($path);

        // "UID sinceUid+1:*" is the standard incremental fetch. A server that
        // has nothing new answers with the last message rather than an empty
        // set — that is what the UID filter below is for.
        $from = max(1, $sinceUid + 1);

        $query = $folder->query()
            ->whereUid($from.':*')
            ->leaveUnread()
            ->setFetchOrder('asc')
            ->limit($limit);

        return Collection::make($query->get())
            ->map(fn (Message $message) => $this->normalise($message))
            ->filter(fn (array $message) => $message['uid'] >= $from)
            ->values();
    }

    /**
     * One message, as plain data.
     *
     * Every accessor is wrapped: a single malformed header — an unparseable
     * date, a broken encoded-word in a subject — must not abort the sync of
     * the other 99 messages in the batch.
     *
     * @return array<string, mixed>
     */
    protected function normalise(Message $message): array
    {
        return [
            'uid' => (int) $this->safe(fn () => $message->getUid(), 0),
            'message_id' => $this->cleanId($this->safe(fn () => (string) $message->getMessageId(), '')),
            'in_reply_to' => $this->cleanId($this->safe(fn () => (string) $message->getInReplyTo(), '')),
            'references' => $this->safe(fn () => (string) $message->getReferences(), '') ?: null,

            'subject' => $this->safe(fn () => (string) $message->getSubject(), '') ?: null,
            'from_name' => $this->safe(fn () => (string) ($message->getFrom()[0]->personal ?? ''), '') ?: null,
            'from_email' => mb_strtolower($this->safe(fn () => (string) ($message->getFrom()[0]->mail ?? ''), '')) ?: null,
            'to' => $this->addresses($message, 'getTo'),
            'cc' => $this->addresses($message, 'getCc'),
            'reply_to' => mb_strtolower($this->safe(fn () => (string) ($message->getReplyTo()[0]->mail ?? ''), '')) ?: null,

            'date' => $this->date($message),
            'body_html' => $this->safe(fn () => $message->getHTMLBody(), '') ?: null,
            'body_text' => $this->safe(fn () => $message->getTextBody(), '') ?: null,
            'size' => (int) $this->safe(fn () => (int) $message->getSize(), 0),

            'seen' => (bool) $this->safe(fn () => $message->getFlags()->has('seen'), false),
            'flagged' => (bool) $this->safe(fn () => $message->getFlags()->has('flagged'), false),
            'draft' => (bool) $this->safe(fn () => $message->getFlags()->has('draft'), false),

            'attachments' => $this->attachments($message),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function attachments(Message $message): array
    {
        return $this->safe(function () use ($message) {
            $out = [];

            foreach ($message->getAttachments() as $attachment) {
                $out[] = [
                    'name' => (string) ($attachment->name ?: 'attachment'),
                    'mime' => (string) ($attachment->content_type ?: 'application/octet-stream'),
                    'size' => (int) ($attachment->size ?: 0),
                    'content' => (string) $attachment->content,
                    'content_id' => $this->cleanId((string) ($attachment->id ?? '')),
                    'inline' => ($attachment->disposition ?? '') === 'inline',
                ];
            }

            return $out;
        }, []);
    }

    /**
     * @return array<int, array{name: ?string, email: string}>
     */
    protected function addresses(Message $message, string $method): array
    {
        return $this->safe(function () use ($message, $method) {
            $out = [];

            foreach ($message->{$method}() as $address) {
                $email = mb_strtolower((string) ($address->mail ?? ''));

                if ($email !== '') {
                    $out[] = ['name' => ((string) ($address->personal ?? '')) ?: null, 'email' => $email];
                }
            }

            return $out;
        }, []);
    }

    protected function date(Message $message): ?Carbon
    {
        return $this->safe(function () use ($message) {
            $date = $message->getDate()?->first();

            return $date ? Carbon::parse((string) $date) : null;
        }, null);
    }

    /** Strips the angle brackets a Message-ID is carried in. */
    protected function cleanId(?string $value): ?string
    {
        $value = trim((string) $value, " \t\n\r\0\x0B<>");

        return $value !== '' ? mb_substr($value, 0, 191) : null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    protected function safe(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback() ?? $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }

    protected function client(Mailbox $mailbox): Client
    {
        if ($this->client !== null && $this->clientFor === $mailbox->id) {
            return $this->client;
        }

        $this->disconnect();

        $this->client = $this->factory->for($mailbox);
        $this->client->connect();
        $this->clientFor = $mailbox->id;

        return $this->client;
    }

    public function disconnect(): void
    {
        try {
            $this->client?->disconnect();
        } catch (Throwable) {
            // Already closed. Nothing useful to report.
        }

        $this->client = null;
        $this->clientFor = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
