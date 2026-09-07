<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\Mailbox;
use App\Models\Role;
use App\Models\SmtpAccount;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The inbox screens through HTTP.
 *
 * The line that matters most here: a stranger's HTML must never reach the
 * application's own DOM. It is delivered by a separate route into a sandboxed
 * frame, and these check that the separation actually holds.
 */
class InboxScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Mailbox $mailbox;

    protected Email $message;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@inbox.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();
        $this->owner->account->subscription->update(['overrides' => [
            'allow_imap' => true, 'max_mailboxes' => 5, 'max_storage_mb' => 512,
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 1000,
        ]]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        SmtpAccount::factory()->forAccount($this->owner->account)->create();

        $this->mailbox = Mailbox::create([
            'user_id' => $this->owner->id, 'name' => 'Support', 'email' => 'support@senders.test',
            'imap_host' => 'imap.senders.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@senders.test', 'imap_password' => 'secret',
        ]);

        $this->message = $this->makeEmail();
    }

    protected function makeEmail(array $overrides = []): Email
    {
        static $seq = 0;
        $seq++;

        return Email::create(array_merge([
            'mailbox_id' => $this->mailbox->id,
            'message_id' => "msg{$seq}@example.com",
            'subject' => "Message {$seq}",
            'from_name' => 'A Customer',
            'from_email' => 'customer@example.com',
            'to' => [['name' => null, 'email' => 'support@senders.test']],
            'body_html' => '<p>Body of the message</p>',
            'body_text' => 'Body of the message',
            'preview' => 'Body of the message',
            'direction' => 'incoming',
            'folder_type' => 'inbox',
            'received_at' => now()->subMinutes($seq),
        ], $overrides));
    }

    // ------------------------------------------------------------ rendering

    public function test_every_folder_renders(): void
    {
        foreach (['/inbox', '/inbox/sent', '/inbox/drafts', '/inbox/starred',
            '/inbox/spam', '/inbox/trash', '/inbox/archive'] as $url) {
            $this->get($url)->assertOk("Died on {$url}");
        }
    }

    public function test_the_reading_screen_renders(): void
    {
        $this->get("/inbox/{$this->message->id}")
            ->assertOk()
            ->assertSee($this->message->subject);
    }

    public function test_a_message_with_almost_nothing_in_it_still_renders(): void
    {
        $bare = Email::create([
            'mailbox_id' => $this->mailbox->id,
            'message_id' => 'bare@example.com',
            'subject' => null,
            'from_email' => null,
            'from_name' => null,
            'to' => [],
            'body_html' => null,
            'body_text' => null,
            'received_at' => null,
            'folder_type' => 'inbox',
        ]);

        $this->get('/inbox')->assertOk();
        $this->get("/inbox/{$bare->id}")->assertOk();
        $this->get("/inbox/{$bare->id}/body")->assertOk();
    }

    public function test_a_malformed_query_string_does_not_crash_a_folder(): void
    {
        foreach (['/inbox?q[]=x', '/inbox?unread[]=1', '/inbox?mailbox[]=1', '/inbox?page=999'] as $url) {
            $this->get($url)->assertOk("Crashed on {$url}");
        }
    }

    // ------------------------------------------------------ the safety line

    public function test_the_body_is_served_by_its_own_route_not_inlined_in_the_page(): void
    {
        $hostile = $this->makeEmail([
            'body_html' => '<p>Hi</p><script>alert(document.cookie)</script>',
        ]);

        $page = $this->get("/inbox/{$hostile->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('alert(document.cookie)', $page,
            'A stranger HTML must never reach the application own DOM.');
        $this->assertStringContainsString('/body', $page, 'The body comes from its own route.');
        $this->assertMatchesRegularExpression('/<iframe[^>]*\bsandbox\b/i', $page,
            'The frame is the second of three layers protecting the reader.');
    }

    public function test_the_body_route_carries_its_own_locked_down_policy(): void
    {
        $response = $this->get("/inbox/{$this->message->id}/body")->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString('no-referrer', (string) $response->headers->get('Referrer-Policy'));
        $this->assertStringNotContainsString('<script', $response->getContent());
    }

    public function test_remote_images_are_held_back_until_asked_for(): void
    {
        $tracked = $this->makeEmail([
            'body_html' => '<p>Hi</p><img src="https://tracker.example.com/pixel.gif">',
        ]);

        $blocked = $this->get("/inbox/{$tracked->id}/body")->assertOk()->getContent();

        // The property that matters is that the browser never FETCHES it. The
        // address stays in a data- attribute on purpose, so "show images" is a
        // re-render rather than a second trip to the message.
        $this->assertDoesNotMatchRegularExpression('/<img[^>]*\ssrc="https:\/\/tracker/i', $blocked,
            'Loading it on open would tell the sender the moment their mail was read.');
        $this->assertStringContainsString('data-kn-blocked-src', $blocked);

        $shown = $this->get("/inbox/{$tracked->id}/body?images=show")->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<img[^>]*\ssrc="https:\/\/tracker/i', $shown,
            'The reader asked for them, so they load.');
    }

    // ------------------------------------------------------------- reading

    public function test_opening_a_message_marks_it_read(): void
    {
        $unread = $this->makeEmail(['is_read' => false]);

        $this->get("/inbox/{$unread->id}")->assertOk();

        $this->assertTrue((bool) $unread->fresh()->is_read);
    }

    public function test_starring_and_unread_toggle(): void
    {
        $this->post("/inbox/{$this->message->id}/star")->assertRedirect();
        $this->assertTrue((bool) $this->message->fresh()->is_starred);

        $this->post("/inbox/{$this->message->id}/star")->assertRedirect();
        $this->assertFalse((bool) $this->message->fresh()->is_starred);

        $this->message->forceFill(['is_read' => true])->save();
        $this->post("/inbox/{$this->message->id}/read")->assertRedirect();
        $this->assertFalse((bool) $this->message->fresh()->is_read);
    }

    // --------------------------------------------------------------- bulk

    public function test_bulk_actions_move_messages_between_folders(): void
    {
        $a = $this->makeEmail();
        $b = $this->makeEmail();

        $this->post('/inbox/actions', ['action' => 'archive', 'ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertSame('archive', $a->fresh()->folder_type);
        $this->assertSame('archive', $b->fresh()->folder_type);
    }

    public function test_trash_is_not_deletion(): void
    {
        $this->post('/inbox/actions', ['action' => 'trash', 'ids' => [$this->message->id]])
            ->assertRedirect();

        $this->assertSame('trash', $this->message->fresh()->folder_type,
            'The row stays, so it can come back.');
        $this->assertNotNull(Email::find($this->message->id));
    }

    public function test_delete_for_good_only_works_from_the_trash(): void
    {
        $inInbox = $this->makeEmail();

        $this->post('/inbox/actions', ['action' => 'delete', 'ids' => [$inInbox->id]])
            ->assertRedirect();

        $this->assertNotNull(Email::find($inInbox->id),
            'Delete outside the trash would be an undoable action from a screen that did not warn.');

        $inInbox->forceFill(['folder_type' => 'trash'])->save();

        $this->post('/inbox/actions', ['action' => 'delete', 'ids' => [$inInbox->id]])
            ->assertRedirect();

        $this->assertNull(Email::withTrashed()->find($inInbox->id));
    }

    public function test_an_unknown_bulk_action_does_nothing(): void
    {
        $this->post('/inbox/actions', ['action' => 'nonsense', 'ids' => [$this->message->id]])
            ->assertRedirect();

        $this->assertSame('inbox', $this->message->fresh()->folder_type);
    }

    public function test_a_bulk_action_cannot_reach_another_accounts_messages(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@inbox.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = app(TenantManager::class)->runAs($other->account_id, fn () => Email::create([
            'message_id' => 'theirs@example.com', 'subject' => 'Theirs',
            'from_email' => 'x@example.com', 'folder_type' => 'inbox', 'received_at' => now(),
        ]));

        app(TenantManager::class)->set($this->owner->account_id);

        $this->post('/inbox/actions', ['action' => 'trash', 'ids' => [$foreign->id]])
            ->assertRedirect();

        $this->assertSame('inbox', Email::withoutGlobalScopes()->find($foreign->id)->folder_type);
    }

    public function test_emptying_the_trash_removes_the_files_too(): void
    {
        $trashed = $this->makeEmail(['folder_type' => 'trash']);

        Storage::disk('local')->put('attachments/x/file.pdf', 'content');

        EmailAttachment::create([
            'email_id' => $trashed->id, 'account_id' => $this->owner->account_id,
            'name' => 'file.pdf', 'mime_type' => 'application/pdf', 'size' => 7,
            'disk' => 'local', 'path' => 'attachments/x/file.pdf',
        ]);

        $this->post('/inbox/empty-trash')->assertRedirect();

        $this->assertNull(Email::withTrashed()->find($trashed->id));
        Storage::disk('local')->assertMissing('attachments/x/file.pdf');
        $this->assertSame(0, EmailAttachment::withoutGlobalScopes()->count(),
            'An empty trash that leaves the files is a storage quota that never goes down.');
    }

    // ------------------------------------------------------------ composing

    public function test_a_blank_message_can_be_started_and_saved(): void
    {
        $response = $this->post('/inbox/compose');
        $response->assertRedirect();

        $draft = Email::where('is_draft', true)->firstOrFail();

        $this->get("/inbox/compose/{$draft->id}")->assertOk();

        $this->put("/inbox/compose/{$draft->id}", [
            'to' => 'someone@example.com, another@example.com',
            'subject' => 'Hello there',
            'body_html' => '<p>Hi</p>',
        ])->assertRedirect();

        $draft->refresh();

        $this->assertSame('Hello there', $draft->subject);
        $this->assertCount(2, $draft->to);
        $this->assertSame('someone@example.com', $draft->to[0]['email']);
    }

    public function test_addresses_are_accepted_in_the_shapes_people_actually_type(): void
    {
        $this->post('/inbox/compose');
        $draft = Email::where('is_draft', true)->firstOrFail();

        $this->put("/inbox/compose/{$draft->id}", [
            'to' => "A Person <a@example.com>; b@example.com\nc@example.com, not-an-address",
            'subject' => 'x',
            'body_html' => '<p>x</p>',
        ])->assertRedirect();

        $emails = collect($draft->fresh()->to)->pluck('email')->all();

        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $emails);
    }

    public function test_replying_prefills_a_draft(): void
    {
        $this->post("/inbox/{$this->message->id}/reply/reply")->assertRedirect();

        $draft = Email::where('is_draft', true)->firstOrFail();

        // Not a literal: makeEmail()'s counter is static, so the subject
        // depends on how many messages earlier tests in this class made.
        $this->assertSame('Re: '.$this->message->subject, $draft->subject);
        $this->assertSame('customer@example.com', $draft->to[0]['email']);
        $this->assertSame($this->message->message_id, $draft->in_reply_to);
    }

    public function test_an_autosave_answers_json_rather_than_navigating(): void
    {
        $this->post('/inbox/compose');
        $draft = Email::where('is_draft', true)->firstOrFail();

        $this->putJson("/inbox/compose/{$draft->id}", [
            'subject' => 'Autosaved', 'body_html' => '<p>x</p>',
        ])->assertOk()->assertJsonPath('ok', true);
    }

    public function test_a_draft_can_be_discarded_with_its_attachments(): void
    {
        $this->post('/inbox/compose');
        $draft = Email::where('is_draft', true)->firstOrFail();

        Storage::disk('local')->put('attachments/y/file.txt', 'x');

        EmailAttachment::create([
            'email_id' => $draft->id, 'account_id' => $this->owner->account_id,
            'name' => 'file.txt', 'mime_type' => 'text/plain', 'size' => 1,
            'disk' => 'local', 'path' => 'attachments/y/file.txt',
        ]);

        $this->delete("/inbox/compose/{$draft->id}")->assertRedirect('/inbox/drafts');

        $this->assertNull(Email::withTrashed()->find($draft->id));
        Storage::disk('local')->assertMissing('attachments/y/file.txt');
    }

    public function test_the_reading_screen_is_not_a_composer(): void
    {
        // A sent message is not a draft, so the composer must not open it.
        $sent = $this->makeEmail(['folder_type' => 'sent', 'is_draft' => false]);

        $this->get("/inbox/compose/{$sent->id}")->assertNotFound();
    }

    // ---------------------------------------------------------- attachments

    public function test_an_attachment_downloads_as_a_file_not_as_its_claimed_type(): void
    {
        Storage::disk('local')->put('attachments/z/page.html', '<script>alert(1)</script>');

        $attachment = EmailAttachment::create([
            'email_id' => $this->message->id, 'account_id' => $this->owner->account_id,
            'name' => 'invoice.html', 'mime_type' => 'text/html', 'size' => 25,
            'disk' => 'local', 'path' => 'attachments/z/page.html',
        ]);

        $response = $this->get("/inbox/{$this->message->id}/attachments/{$attachment->id}")->assertOk();

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'),
            'Serving a stranger HTML as text/html from our origin is how it runs with the reader session.');
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_a_non_image_cannot_be_served_inline(): void
    {
        Storage::disk('local')->put('attachments/z/x.html', 'x');

        $attachment = EmailAttachment::create([
            'email_id' => $this->message->id, 'account_id' => $this->owner->account_id,
            'name' => 'x.html', 'mime_type' => 'text/html', 'size' => 1,
            'disk' => 'local', 'path' => 'attachments/z/x.html',
        ]);

        $this->get("/inbox/{$this->message->id}/inline/{$attachment->id}")->assertNotFound();
    }

    public function test_an_attachment_cannot_be_read_through_another_message(): void
    {
        Storage::disk('local')->put('attachments/z/secret.pdf', 'x');

        $attachment = EmailAttachment::create([
            'email_id' => $this->message->id, 'account_id' => $this->owner->account_id,
            'name' => 'secret.pdf', 'mime_type' => 'application/pdf', 'size' => 1,
            'disk' => 'local', 'path' => 'attachments/z/secret.pdf',
        ]);

        $other = $this->makeEmail();

        $this->get("/inbox/{$other->id}/attachments/{$attachment->id}")->assertNotFound();
    }

    // ------------------------------------------------------------- tenancy

    public function test_another_accounts_message_is_not_reachable(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival2', 'email' => 'rival2@inbox.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = app(TenantManager::class)->runAs($other->account_id, fn () => Email::create([
            'message_id' => 'foreign@example.com', 'subject' => 'Not yours',
            'from_email' => 'x@example.com', 'folder_type' => 'inbox', 'received_at' => now(),
        ]));

        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/inbox')->assertOk()->assertDontSee('Not yours');
        $this->get("/inbox/{$foreign->id}")->assertNotFound();
        $this->get("/inbox/{$foreign->id}/body")->assertNotFound();
    }

    // --------------------------------------------------------- permissions

    public function test_a_reader_without_send_permission_cannot_compose(): void
    {
        $staff = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/inbox')->assertOk();
        $this->get("/inbox/{$this->message->id}")->assertOk();

        $this->post('/inbox/compose')->assertForbidden();
        $this->post("/inbox/{$this->message->id}/reply/reply")->assertForbidden();
    }
}
