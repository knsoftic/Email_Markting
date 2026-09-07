<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\Mailbox;
use App\Models\SmtpAccount;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Inbox\MessageComposer;
use App\Services\Smtp\MailerFactory;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/** Keeps what it was asked to send. */
class ComposedTransport implements TransportInterface
{
    /** @var array<int, string> */
    public array $sent = [];

    public function send(RawMessage $message, $envelope = null): ?SentMessage
    {
        $this->sent[] = $message->toString();

        return null;
    }

    public function __toString(): string
    {
        return 'composed';
    }
}

class ComposedFactory extends MailerFactory
{
    public ComposedTransport $transport;

    public function __construct()
    {
        $this->transport = new ComposedTransport;
    }

    public function for(SmtpAccount $account, ?int $timeout = null): TransportInterface
    {
        return $this->transport;
    }
}

/**
 * Writing, replying and sending.
 *
 * The reply headers are the part worth guarding: without In-Reply-To and
 * References every message becomes its own thread in the recipient's client
 * and in our own sync, and no amount of subject matching repairs that later.
 */
class ComposerTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Mailbox $mailbox;

    protected ComposedFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@compose.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5,
            'max_emails_per_month' => 1000, 'allow_imap' => true, 'max_mailboxes' => 5,
        ]]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->factory = new ComposedFactory;
        $this->app->instance(MailerFactory::class, $this->factory);

        SmtpAccount::factory()->forAccount($this->owner->account)->create();

        $this->mailbox = Mailbox::create([
            'user_id' => $this->owner->id, 'name' => 'Support', 'email' => 'support@senders.test',
            'imap_host' => 'imap.senders.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@senders.test', 'imap_password' => 'secret',
        ]);
    }

    protected function composer(): MessageComposer
    {
        return app(MessageComposer::class);
    }

    protected function incoming(array $overrides = []): Email
    {
        static $seq = 0;
        $seq++;

        return Email::create(array_merge([
            'mailbox_id' => $this->mailbox->id,
            'message_id' => "incoming{$seq}@example.com",
            'subject' => 'Question about pricing',
            'from_name' => 'A Customer',
            'from_email' => 'customer@example.com',
            'to' => [['name' => null, 'email' => 'support@senders.test']],
            'cc' => [['name' => null, 'email' => 'colleague@example.com']],
            'body_html' => '<p>How much is the Pro plan?</p>',
            'body_text' => 'How much is the Pro plan?',
            'direction' => 'incoming',
            'folder_type' => 'inbox',
            'received_at' => now()->subHour(),
        ], $overrides));
    }

    // -------------------------------------------------------------- replies

    public function test_a_reply_carries_the_headers_that_make_it_a_reply(): void
    {
        $source = $this->incoming();

        $draft = $this->composer()->draftFrom($source, 'reply', $this->mailbox);

        $this->assertSame(['customer@example.com'], $draft['to']);
        $this->assertSame('Re: Question about pricing', $draft['subject']);
        $this->assertSame('incoming1@example.com', $draft['in_reply_to']);
        $this->assertStringContainsString('<incoming1@example.com>', (string) $draft['references']);
    }

    public function test_a_reply_goes_to_the_reply_to_address_when_there_is_one(): void
    {
        $source = $this->incoming(['reply_to' => 'billing@example.com']);

        $draft = $this->composer()->draftFrom($source, 'reply', $this->mailbox);

        $this->assertSame(['billing@example.com'], $draft['to'],
            'Ignoring Reply-To sends the answer to an address nobody reads.');
    }

    public function test_reply_all_includes_the_others_but_never_ourselves(): void
    {
        $source = $this->incoming([
            'to' => [
                ['name' => null, 'email' => 'support@senders.test'],
                ['name' => null, 'email' => 'someone@example.com'],
            ],
        ]);

        $draft = $this->composer()->draftFrom($source, 'reply_all', $this->mailbox);

        $this->assertContains('someone@example.com', $draft['cc']);
        $this->assertContains('colleague@example.com', $draft['cc']);
        $this->assertNotContains('support@senders.test', $draft['cc'],
            'Reply-all that includes this mailbox loops every reply back into the inbox.');
        $this->assertNotContains('customer@example.com', $draft['cc'],
            'The person being replied to is in To, not also in Cc.');
    }

    public function test_a_forward_does_not_pretend_to_be_a_reply(): void
    {
        $source = $this->incoming();

        $draft = $this->composer()->draftFrom($source, 'forward', $this->mailbox);

        $this->assertSame('Fwd: Question about pricing', $draft['subject']);
        $this->assertSame([], $draft['to'], 'A forward has no obvious recipient — the user picks.');
        $this->assertNull($draft['in_reply_to'],
            'Threading a forward as an answer puts somebody else conversation into the recipient copy of ours.');
        $this->assertNull($draft['references']);
    }

    public function test_the_subject_prefix_does_not_stack(): void
    {
        foreach ([
            'Re: Already answered' => 'Re: Already answered',
            'RE: RE: Twice' => 'Re: Twice',
            'Fwd: Re: Mixed' => 'Re: Mixed',
            'AW: German' => 'Re: German',
        ] as $original => $expected) {
            $draft = $this->composer()->draftFrom(
                $this->incoming(['subject' => $original]), 'reply', $this->mailbox
            );

            $this->assertSame($expected, $draft['subject'], "From: {$original}");
        }
    }

    public function test_the_reference_chain_grows_rather_than_resetting(): void
    {
        $source = $this->incoming([
            'message_id' => 'third@example.com',
            'references' => '<first@example.com> <second@example.com>',
        ]);

        $draft = $this->composer()->draftFrom($source, 'reply', $this->mailbox);

        $this->assertStringContainsString('<first@example.com>', $draft['references']);
        $this->assertStringContainsString('<second@example.com>', $draft['references']);
        $this->assertStringContainsString('<third@example.com>', $draft['references'],
            'The message being answered joins the chain, or long threads split.');
    }

    /**
     * Receiving clients collapse quoted history on <blockquote>. Without it
     * every reply on a long thread arrives with the whole chain expanded — and
     * the rich text editor flattens a styled <div> away, so a div would not
     * even survive the composer.
     */
    public function test_the_quote_is_a_blockquote_so_clients_can_collapse_it(): void
    {
        $draft = $this->composer()->draftFrom($this->incoming(), 'reply', $this->mailbox);

        $this->assertStringContainsString('<blockquote', $draft['body_html']);
        $this->assertStringContainsString('</blockquote>', $draft['body_html']);
        $this->assertStringContainsString('wrote:', $draft['body_html']);
    }

    public function test_the_quoted_original_is_sanitised_on_the_way_in(): void
    {
        $source = $this->incoming([
            'body_html' => '<p>Hello</p><script>alert(1)</script><img src="https://tracker.test/p.gif">',
        ]);

        $draft = $this->composer()->draftFrom($source, 'reply', $this->mailbox);

        $this->assertStringContainsString('Hello', $draft['body_html']);
        $this->assertStringNotContainsString('<script', $draft['body_html']);
        $this->assertStringNotContainsString('alert(1)', $draft['body_html']);
    }

    // --------------------------------------------------------------- sending

    protected function draftRow(array $overrides = []): Email
    {
        return Email::create(array_merge([
            'mailbox_id' => $this->mailbox->id,
            'user_id' => $this->owner->id,
            'direction' => 'outgoing',
            'folder_type' => 'drafts',
            'is_draft' => true,
            'is_read' => true,
            'send_status' => 'draft',
            'from_name' => 'Support',
            'from_email' => 'support@senders.test',
            'to' => [['name' => null, 'email' => 'customer@example.com']],
            'subject' => 'Our reply',
            'body_html' => '<p>Here is the answer.</p>',
        ], $overrides));
    }

    public function test_sending_files_the_message_in_sent_and_stamps_it(): void
    {
        $draft = $this->draftRow();

        $result = $this->composer()->send($draft);

        $this->assertTrue($result['ok'], $result['message']);

        $sent = $draft->fresh();

        $this->assertSame('sent', $sent->folder_type);
        $this->assertSame('sent', $sent->send_status);
        $this->assertFalse((bool) $sent->is_draft);
        $this->assertNotNull($sent->sent_at);
        $this->assertNotEmpty($sent->message_id);
    }

    public function test_the_sent_message_carries_the_reply_headers(): void
    {
        $this->composer()->send($this->draftRow([
            'in_reply_to' => 'incoming@example.com',
            'references' => '<first@example.com> <incoming@example.com>',
        ]));

        $raw = $this->factory->transport->sent[0];

        $this->assertStringContainsString('In-Reply-To: <incoming@example.com>', $raw);
        $this->assertStringContainsString('References: <first@example.com> <incoming@example.com>', $raw);
    }

    public function test_a_text_alternative_is_always_sent(): void
    {
        $this->composer()->send($this->draftRow(['body_html' => '<p>Hello <b>there</b></p>', 'body_text' => null]));

        $raw = $this->factory->transport->sent[0];

        $this->assertStringContainsString('text/plain', $raw,
            'A message with no text part is more likely to be filtered.');
    }

    public function test_a_draft_with_no_recipient_is_refused_before_anything_is_sent(): void
    {
        $result = $this->composer()->send($this->draftRow(['to' => []]));

        $this->assertFalse($result['ok']);
        $this->assertSame([], $this->factory->transport->sent);
    }

    public function test_a_malformed_recipient_is_dropped_rather_than_sent_to(): void
    {
        $result = $this->composer()->send($this->draftRow([
            'to' => [
                ['name' => null, 'email' => 'not-an-address'],
                ['name' => null, 'email' => 'real@example.com'],
            ],
        ]));

        $this->assertTrue($result['ok']);

        $raw = $this->factory->transport->sent[0];

        $this->assertStringContainsString('real@example.com', $raw);
        $this->assertStringNotContainsString('not-an-address', $raw);
    }

    public function test_sending_counts_against_the_monthly_allowance(): void
    {
        $before = \App\Support\PlanLimits::for($this->owner->account)->usageFor('max_emails_per_month');

        $this->composer()->send($this->draftRow());

        $this->assertSame(
            $before + 1,
            \App\Support\PlanLimits::for($this->owner->account->fresh())->usageFor('max_emails_per_month')
        );
    }

    public function test_a_send_over_the_monthly_allowance_is_refused(): void
    {
        $this->owner->account->subscription->update(['overrides' => array_merge(
            $this->owner->account->subscription->overrides ?? [],
            ['max_emails_per_month' => 0]
        )]);
        $this->owner->account->refresh();

        $result = $this->composer()->send($this->draftRow());

        $this->assertFalse($result['ok']);
        $this->assertSame([], $this->factory->transport->sent);
    }

    public function test_a_sent_reply_joins_the_conversation(): void
    {
        $source = $this->incoming();

        $thread = \App\Models\EmailThread::create([
            'account_id' => $this->owner->account_id,
            'mailbox_id' => $this->mailbox->id,
            'thread_key' => 'incoming1@example.com',
            'subject' => 'Question about pricing',
            'messages_count' => 1,
        ]);

        $source->forceFill(['email_thread_id' => $thread->id])->save();

        $this->composer()->send($this->draftRow(['email_thread_id' => $thread->id]));

        $thread->refresh();

        $this->assertSame(2, (int) $thread->messages_count);
        $this->assertSame('replied', $thread->reply_status);
    }

    public function test_a_brand_new_message_starts_its_own_conversation(): void
    {
        $draft = $this->draftRow();

        $this->composer()->send($draft);

        $this->assertNotNull($draft->fresh()->email_thread_id);
        $this->assertSame(1, \App\Models\EmailThread::count());
    }
}
