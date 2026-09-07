<?php

namespace App\Services\Imap;

use App\Models\Mailbox;
use Illuminate\Support\Collection;

/**
 * The only surface the sync logic is allowed to see of an IMAP server.
 *
 * ── Why an interface and not webklex directly ──────────────────────────────
 * Two reasons, both practical.
 *
 * First, testing. The sync rules worth getting right — UIDVALIDITY resets,
 * Message-ID dedupe, threading, attachment limits — are all pure logic over
 * message data. Coupling them to webklex's object graph would mean the only
 * way to test them is against a live mail server, which is to say they would
 * not be tested.
 *
 * Second, drift. webklex/php-imap's Message API changes between majors. One
 * adapter is a small thing to fix; a syncer built on `$message->getHeader()
 * ->get('x')->first()` in forty places is not.
 *
 * Everything below returns plain arrays and Carbon dates. Nothing returns a
 * library object.
 */
interface ImapGateway
{
    /**
     * Every folder on the server.
     *
     * @return Collection<int, array{path: string, name: string, delimiter: string, attributes: array<int, string>, no_select: bool}>
     */
    public function folders(Mailbox $mailbox): Collection;

    /**
     * The folder's current UIDVALIDITY and UIDNEXT.
     *
     * UIDVALIDITY is the one that matters: if it differs from what we stored,
     * every UID we have for that folder now refers to a different message, or
     * to nothing. It is the server's way of saying "forget what you knew".
     *
     * @return array{uid_validity: int, uid_next: int, messages: int}
     */
    public function folderState(Mailbox $mailbox, string $path): array;

    /**
     * Messages in the folder with a UID greater than $sinceUid, oldest first.
     *
     * @return Collection<int, array<string, mixed>>  see MailboxSyncer for the shape
     */
    public function messages(Mailbox $mailbox, string $path, int $sinceUid, int $limit): Collection;
}
