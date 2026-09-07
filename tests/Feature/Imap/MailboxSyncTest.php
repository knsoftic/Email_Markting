<?php

namespace Tests\Feature\Imap;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\MailboxFolder;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Imap\ImapGateway;
use App\Services\Imap\MailboxSyncer;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * A scriptable IMAP server.
 *
 * The sync rules worth getting right — UIDVALIDITY resets, dedupe, threading,
 * attachment limits — are logic over message data. Testing them against a live
 * server would mean not testing them.
 */
class FakeImapGateway implements ImapGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $folderList = [];

    /** @var array<string, array{uid_validity: int, uid_next: int, messages: int}> */
    public array $states = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $messages = [];

    /** @var array<int, string> paths that throw when read */
    public array $brokenFolders = [];

    /** @var array<int, array{path: string, since: int, limit: int}> */
    public array $calls = [];

    public function folders(Mailbox $mailbox): Collection
    {
        return Collection::make($this->folderList);
    }

    public function folderState(Mailbox $mailbox, string $path): array
    {
        if (in_array($path, $this->brokenFolders, true)) {
            throw new RuntimeException("Folder {$path} is not selectable");
        }

        return $this->states[$path] ?? ['uid_validity' => 1, 'uid_next' => 1, 'messages' => 0];
    }

    public function messages(Mailbox $mailbox, string $path, int $sinceUid, int $limit): Collection
    {
        $this->calls[] = ['path' => $path, 'since' => $sinceUid, 'limit' => $limit];

        return Collection::make($this->messages[$path] ?? [])
            ->filter(fn ($m) => (int) $m['uid'] > $sinceUid)
            ->sortBy('uid')
            ->take($limit)
            ->values();
    }
}

class MailboxSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Mailbox $mailbox;

    protected FakeImapGateway $imap;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@imap.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->account->subscription->update(['overrides' => [
            'allow_imap' => true, 'max_mailboxes' => 5, 'max_storage_mb' => 512,
        ]]);
        $this->owner->account->refresh();

        app(TenantManager::class)->set($this->owner->account_id);

        $this->mailbox = Mailbox::create([
            'user_id' => $this->owner->id,
            'name' => 'Support',
            'email' => 'support@senders.test',
            'imap_host' => 'imap.senders.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@senders.test',
            'imap_password' => 'secret',
        ]);

        $this->imap = new FakeImapGateway;
        $this->app->instance(ImapGateway::class, $this->imap);

        $this->imap->folderList = [
            ['path' => 'INBOX', 'name' => 'INBOX', 'delimiter' => '/', 'attributes' => [], 'no_select' => false],
            ['path' => '[Gmail]/Sent Mail', 'name' => 'Sent Mail', 'delimiter' => '/', 'attributes' => ['\\Sent'], 'no_select' => false],
            ['path' => '[Gmail]', 'name' => '[Gmail]', 'delimiter' => '/', 'attributes' => ['\\Noselect'], 'no_select' => true],
        ];
    }

    protected function syncer(): MailboxSyncer
    {
        return $this->app->make(MailboxSyncer::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function message(int $uid, array $overrides = []): array
    {
        return array_merge([
            'uid' => $uid,
            'message_id' => "msg-{$uid}@example.com",
            'in_reply_to' => null,
            'references' => null,
            'subject' => "Message {$uid}",
            'from_name' => 'A Customer',
            'from_email' => 'customer@example.com',
            'to' => [['name' => 'Support', 'email' => 'support@senders.test']],
            'cc' => [],
            'reply_to' => null,
            'date' => Carbon::now()->subMinutes(60 - $uid),
            'body_html' => "<p>Body of {$uid}</p>",
            'body_text' => "Body of {$uid}",
            'size' => 2048,
            'seen' => false,
            'flagged' => false,
            'draft' => false,
            'attachments' => [],
        ], $overrides);
    }

    // ------------------------------------------------------------- folders

    public function test_folders_are_discovered_and_typed_by_what_the_server_says(): void
    {
        $this->syncer()->sync($this->mailbox);

        $folders = MailboxFolder::where('mailbox_id', $this->mailbox->id)->get()->keyBy('path');

        $this->assertSame('inbox', $folders['INBOX']->type);
        $this->assertSame('sent', $folders['[Gmail]/Sent Mail']->type,
            'RFC 6154 \\Sent is authoritative — the name "[Gmail]/Sent Mail" would never match a name table.');
        $this->assertFalse((bool) $folders['[Gmail]']->is_syncable,
            'A container folder cannot be opened, so asking for its messages is an error.');
    }

    public function test_a_folder_that_disappears_stops_syncing_but_keeps_its_messages(): void
    {
        $this->imap->messages['INBOX'] = [$this->message(1)];
        $this->syncer()->sync($this->mailbox);

        $this->assertSame(1, Email::count());

        // The server no longer reports INBOX (a contrived but real failure).
        $this->imap->folderList = [
            ['path' => 'Archive', 'name' => 'Archive', 'delimiter' => '/', 'attributes' => ['\\Archive'], 'no_select' => false],
        ];

        $this->syncer()->sync($this->mailbox->fresh());

        $this->assertFalse(
            (bool) MailboxFolder::where('path', 'INBOX')->value('is_syncable')
        );
        $this->assertSame(1, Email::count(), 'Deleting the folder row would orphan its messages behind a foreign key.');
    }

    // ------------------------------------------------------------ messages

    public function test_messages_are_stored_with_their_headers_and_body(): void
    {
        $this->imap->messages['INBOX'] = [$this->message(1), $this->message(2)];

        $result = $this->syncer()->sync($this->mailbox);

        $this->assertSame(2, $result['stored']);

        $email = Email::where('message_id', 'msg-1@example.com')->firstOrFail();

        $this->assertSame('customer@example.com', $email->from_email);
        $this->assertSame('Message 1', $email->subject);
        $this->assertSame('incoming', $email->direction);
        $this->assertSame('inbox', $email->folder_type);
        $this->assertStringContainsString('Body of 1', (string) $email->body_html);
        $this->assertSame('Body of 1', $email->preview);
        $this->assertFalse((bool) $email->is_read);
        $this->assertSame($this->owner->account_id, $email->account_id);
    }

    public function test_a_message_in_the_sent_folder_is_marked_outgoing(): void
    {
        $this->imap->messages['[Gmail]/Sent Mail'] = [$this->message(5, ['message_id' => 'sent-5@example.com'])];

        $this->syncer()->sync($this->mailbox);

        $email = Email::where('message_id', 'sent-5@example.com')->firstOrFail();

        $this->assertSame('outgoing', $email->direction);
        $this->assertSame('sent', $email->folder_type);
    }

    public function test_a_second_sync_stores_nothing_new(): void
    {
        $this->imap->messages['INBOX'] = [$this->message(1), $this->message(2)];

        $this->syncer()->sync($this->mailbox);
        $second = $this->syncer()->sync($this->mailbox->fresh());

        $this->assertSame(0, $second['fetched'], 'The UID cursor should mean nothing is even fetched.');
        $this->assertSame(2, Email::count());
    }

    public function test_the_same_message_arriving_twice_is_stored_once(): void
    {
        // Same Message-ID, different UID — what a server does when a message is
        // moved out of a folder and back again.
        $this->imap->messages['INBOX'] = [
            $this->message(1, ['message_id' => 'dup@example.com']),
            $this->message(2, ['message_id' => 'dup@example.com']),
        ];

        $result = $this->syncer()->sync($this->mailbox);

        $this->assertSame(1, $result['stored']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, Email::count());
    }

    public function test_a_message_with_no_message_id_still_gets_a_stable_identity(): void
    {
        $this->imap->messages['INBOX'] = [$this->message(7, ['message_id' => null])];

        $this->syncer()->sync($this->mailbox);

        $email = Email::firstOrFail();
        $this->assertStringContainsString('kn-uid-', (string) $email->message_id);

        // Force a re-read of the same UID; the synthetic id must match.
        MailboxFolder::where('path', 'INBOX')->update(['last_uid' => 0]);
        $this->syncer()->sync($this->mailbox->fresh());

        $this->assertSame(1, Email::count(), 'A synthetic id that changed per sync would duplicate every draft.');
    }

    // -------------------------------------------------------- uidvalidity

    public function test_a_uidvalidity_change_resyncs_the_folder_from_scratch(): void
    {
        $this->imap->states['INBOX'] = ['uid_validity' => 100, 'uid_next' => 3, 'messages' => 2];
        $this->imap->messages['INBOX'] = [$this->message(1), $this->message(2)];

        $this->syncer()->sync($this->mailbox);

        $this->assertSame(2, (int) MailboxFolder::where('path', 'INBOX')->value('last_uid'));

        // The server renumbers the folder: same messages, new UIDs.
        $this->imap->states['INBOX'] = ['uid_validity' => 999, 'uid_next' => 3, 'messages' => 2];
        $this->imap->calls = [];

        $result = $this->syncer()->sync($this->mailbox->fresh());

        $this->assertSame(1, $result['resets']);
        $this->assertSame(0, $this->imap->calls[0]['since'],
            'After a UIDVALIDITY change the stored cursor is meaningless and must be ignored.');
        $this->assertSame(999, (int) MailboxFolder::where('path', 'INBOX')->value('uid_validity'));
        $this->assertSame(2, Email::count(), 'The re-fetch is deduped by Message-ID, so nothing doubles.');
    }

    public function test_the_uid_cursor_only_ever_moves_forward(): void
    {
        $this->imap->messages['INBOX'] = [$this->message(10)];
        $this->syncer()->sync($this->mailbox);

        $this->assertSame(10, (int) MailboxFolder::where('path', 'INBOX')->value('last_uid'));

        // A batch that returns nothing must not rewind the cursor.
        $this->imap->messages['INBOX'] = [];
        $this->syncer()->sync($this->mailbox->fresh());

        $this->assertSame(10, (int) MailboxFolder::where('path', 'INBOX')->value('last_uid'));
    }

    public function test_the_fetch_is_limited_by_the_mailboxes_own_setting(): void
    {
        $this->mailbox->update(['sync_limit' => 2]);
        $this->imap->messages['INBOX'] = [$this->message(1), $this->message(2), $this->message(3), $this->message(4)];

        $result = $this->syncer()->sync($this->mailbox->fresh());

        $this->assertSame(2, $result['stored']);
        $this->assertSame(2, $this->imap->calls[0]['limit']);
        $this->assertSame(2, (int) MailboxFolder::where('path', 'INBOX')->value('last_uid'),
            'The next pass picks up from where this one stopped.');
    }

    // ------------------------------------------------------------ threads

    public function test_a_reply_joins_the_conversation_it_answers(): void
    {
        $this->imap->messages['INBOX'] = [
            $this->message(1, ['message_id' => 'root@example.com', 'subject' => 'Question about pricing']),
            $this->message(2, [
                'message_id' => 'reply@example.com',
                'subject' => 'Re: Question about pricing',
                'in_reply_to' => 'root@example.com',
                'references' => '<root@example.com>',
            ]),
        ];

        $this->syncer()->sync($this->mailbox);

        $this->assertSame(1, EmailThread::count());

        $thread = EmailThread::firstOrFail();

        $this->assertSame('Question about pricing', $thread->subject,
            'The first subject wins; "Re:" prefixes would rewrite the title on every reply.');
        $this->assertSame(2, (int) $thread->messages_count);
        $this->assertSame(2, Email::where('email_thread_id', $thread->id)->count());
    }

    public function test_a_long_chain_stays_one_conversation(): void
    {
        $this->imap->messages['INBOX'] = [
            $this->message(1, ['message_id' => 'a@example.com']),
            $this->message(2, ['message_id' => 'b@example.com', 'in_reply_to' => 'a@example.com',
                'references' => '<a@example.com>']),
            $this->message(3, ['message_id' => 'c@example.com', 'in_reply_to' => 'b@example.com',
                'references' => '<a@example.com> <b@example.com>']),
        ];

        $this->syncer()->sync($this->mailbox);

        $this->assertSame(1, EmailThread::count(),
            'Keying on the immediate parent would split this into a chain of two-message threads.');
        $this->assertSame(3, (int) EmailThread::first()->messages_count);
    }

    public function test_unrelated_messages_do_not_share_a_thread(): void
    {
        $this->imap->messages['INBOX'] = [
            $this->message(1, ['message_id' => 'x@example.com']),
            $this->message(2, ['message_id' => 'y@example.com']),
        ];

        $this->syncer()->sync($this->mailbox);

        $this->assertSame(2, EmailThread::count());
    }

    // -------------------------------------------------------- attachments

    public function test_attachments_are_stored_and_counted(): void
    {
        $this->imap->messages['INBOX'] = [$this->message(1, ['attachments' => [
            ['name' => 'invoice.pdf', 'mime' => 'application/pdf', 'size' => 12,
                'content' => 'PDF-CONTENT', 'content_id' => null, 'inline' => false],
        ]])];

        $result = $this->syncer()->sync($this->mailbox);

        $this->assertSame(1, $result['attachments']);

        $attachment = EmailAttachment::firstOrFail();

        $this->assertSame('invoice.pdf', $attachment->name);
        $this->assertSame('application/pdf', $attachment->mime_type);
        Storage::disk('local')->assertExists($attachment->path);

        $email = Email::firstOrFail();
        $this->assertTrue((bool) $email->has_attachments);
        $this->assertSame(1, (int) $email->attachments_count);
    }

    public function test_an_oversized_attachment_is_skipped_but_the_message_is_kept(): void
    {
        config()->set('knsoftic.max_attachment_kb', 1);

        $this->imap->messages['INBOX'] = [$this->message(1, ['attachments' => [
            ['name' => 'huge.zip', 'mime' => 'application/zip', 'size' => 5000,
                'content' => str_repeat('x', 5000), 'content_id' => null, 'inline' => false],
        ]])];

        $this->syncer()->sync($this->mailbox);

        $this->assertSame(0, EmailAttachment::count());
        $this->assertSame(1, Email::count(), 'A missing attachment is better than a missing email.');
        $this->assertFalse((bool) Email::first()->has_attachments);
    }

    public function test_the_filename_extension_never_comes_from_the_claimed_mime_type(): void
    {
        $this->imap->messages['INBOX'] = [$this->message(1, ['attachments' => [
            ['name' => 'payload.php', 'mime' => 'image/png', 'size' => 5,
                'content' => '<?php', 'content_id' => null, 'inline' => false],
        ]])];

        $this->syncer()->sync($this->mailbox);

        $path = EmailAttachment::firstOrFail()->path;

        $this->assertStringEndsWith('.php', $path,
            'The stored name is descriptive only — it is a UUID on a private disk, never served as code.');
        $this->assertStringNotContainsString('..', $path);
    }

    public function test_the_storage_allowance_stops_the_download(): void
    {
        $this->owner->account->subscription->update(['overrides' => [
            'allow_imap' => true, 'max_mailboxes' => 5, 'max_storage_mb' => 0,
        ]]);
        $this->owner->account->refresh();

        $this->imap->messages['INBOX'] = [$this->message(1, ['attachments' => [
            ['name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 2048,
                'content' => str_repeat('y', 2048), 'content_id' => null, 'inline' => false],
        ]])];

        $this->syncer()->sync($this->mailbox);

        $this->assertSame(0, EmailAttachment::count(),
            'max_storage_mb was advertised by every plan and never enforced until now.');
        $this->assertSame(1, Email::count());
    }

    // ------------------------------------------------------------ failures

    public function test_one_unreadable_folder_does_not_abandon_the_others(): void
    {
        $this->imap->brokenFolders = ['[Gmail]/Sent Mail'];
        $this->imap->messages['INBOX'] = [$this->message(1)];

        $result = $this->syncer()->sync($this->mailbox);

        $this->assertSame(1, $result['stored'], 'The inbox still synced.');
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('[Gmail]/Sent Mail', $result['errors'][0]);

        $this->assertSame('failed', $this->mailbox->fresh()->last_sync_status);
        $this->assertNotNull($this->mailbox->fresh()->last_error);
    }

    // ------------------------------------------------------------ counters

    public function test_the_mailbox_counters_reflect_what_was_stored(): void
    {
        $this->imap->messages['INBOX'] = [
            $this->message(1, ['seen' => true]),
            $this->message(2, ['seen' => false]),
            $this->message(3, ['seen' => false]),
        ];

        $this->syncer()->sync($this->mailbox);

        $mailbox = $this->mailbox->fresh();

        $this->assertSame(3, (int) $mailbox->messages_count);
        $this->assertSame(2, (int) $mailbox->unread_count);
        $this->assertSame('success', $mailbox->last_sync_status);
        $this->assertSame('connected', $mailbox->status);
        $this->assertNotNull($mailbox->last_sync_at);
    }
}
