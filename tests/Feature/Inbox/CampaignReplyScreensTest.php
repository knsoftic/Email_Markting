<?php

namespace Tests\Feature\Inbox;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Email;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\Role;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The campaign replies queue.
 *
 * A reply screen earns its place by being a queue — what still needs
 * answering, oldest first — rather than the inbox with a filter on it.
 */
class CampaignReplyScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Mailbox $mailbox;

    protected Campaign $campaign;

    protected CampaignRecipient $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@replyscreens.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();
        $this->owner->account->subscription->update(['overrides' => [
            'allow_imap' => true, 'max_mailboxes' => 5,
        ]]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->mailbox = Mailbox::create([
            'user_id' => $this->owner->id, 'name' => 'Support', 'email' => 'support@senders.test',
            'imap_host' => 'imap.senders.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@senders.test', 'imap_password' => 'secret',
        ]);

        $this->campaign = Campaign::factory()->forAccount($this->owner->account)->create([
            'name' => 'March newsletter', 'subject' => 'Our March update',
            'status' => 'completed', 'sent_count' => 1, 'started_at' => now()->subDays(2),
        ]);

        $subscriber = Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'reader@example.com']);

        $this->recipient = CampaignRecipient::create([
            'campaign_id' => $this->campaign->id,
            'subscriber_id' => $subscriber->id,
            'email' => 'reader@example.com',
            'status' => 'sent',
            'sent_at' => now()->subDays(2),
            'message_id' => 'sent-to-reader@senders.test',
        ]);
    }

    /** A thread with one incoming reply already matched to the campaign. */
    protected function thread(array $threadOverrides = [], array $emailOverrides = []): EmailThread
    {
        static $seq = 0;
        $seq++;

        $thread = EmailThread::create(array_merge([
            'mailbox_id' => $this->mailbox->id,
            'campaign_id' => $this->campaign->id,
            'subscriber_id' => $this->recipient->subscriber_id,
            'thread_key' => "thread-{$seq}",
            'subject' => "Re: Our March update ({$seq})",
            'reply_status' => 'new',
            'messages_count' => 1,
            'last_message_at' => now()->subMinutes($seq),
        ], $threadOverrides));

        Email::create(array_merge([
            'mailbox_id' => $this->mailbox->id,
            'email_thread_id' => $thread->id,
            'message_id' => "reply{$seq}@example.com",
            'subject' => $thread->subject,
            'from_name' => 'A Reader',
            'from_email' => 'reader@example.com',
            'to' => [['name' => null, 'email' => 'support@senders.test']],
            'direction' => 'incoming',
            'folder_type' => 'inbox',
            'body_html' => '<p>Thanks, this was useful.</p>',
            'preview' => 'Thanks, this was useful.',
            'is_read' => false,
            'is_campaign_reply' => true,
            'campaign_id' => $this->campaign->id,
            'campaign_recipient_id' => $this->recipient->id,
            'subscriber_id' => $this->recipient->subscriber_id,
            'received_at' => now()->subMinutes($seq),
        ], $emailOverrides));

        return $thread->fresh();
    }

    // ------------------------------------------------------------ rendering

    public function test_both_screens_render(): void
    {
        $thread = $this->thread();

        $this->get('/campaign-replies')->assertOk()->assertSee('March newsletter');
        $this->get("/campaign-replies/{$thread->id}")->assertOk();
    }

    public function test_the_queue_renders_with_nothing_in_it(): void
    {
        $this->get('/campaign-replies')->assertOk();
    }

    /**
     * Deleting a campaign nulls `email_threads.campaign_id` through the foreign
     * key, so the conversation stops being a campaign reply and goes on living
     * as an ordinary one. The queue and the conversation screen agree about
     * that — and, critically, the messages themselves are untouched.
     */
    public function test_a_conversation_outlives_the_campaign_it_answered(): void
    {
        $thread = $this->thread();
        $messageId = Email::where('email_thread_id', $thread->id)->value('id');

        $this->campaign->forceDelete();

        $this->assertNull($thread->fresh()->campaign_id);

        // Gone from the campaign queue, in both places, consistently.
        $this->get('/campaign-replies')->assertOk()->assertDontSee($thread->subject);
        $this->get("/campaign-replies/{$thread->id}")->assertNotFound();

        // Still a real message the person can read.
        $this->get("/inbox/{$messageId}")->assertOk();
    }

    public function test_a_thread_with_no_messages_renders(): void
    {
        $thread = EmailThread::create([
            'mailbox_id' => $this->mailbox->id,
            'campaign_id' => $this->campaign->id,
            'thread_key' => 'empty-thread',
            'subject' => null,
            'reply_status' => 'new',
            'messages_count' => 0,
            'last_message_at' => null,
        ]);

        $this->get('/campaign-replies')->assertOk();
        $this->get("/campaign-replies/{$thread->id}")->assertOk();
    }

    public function test_a_malformed_query_string_does_not_crash_the_queue(): void
    {
        $this->thread();

        foreach (['/campaign-replies?q[]=x', '/campaign-replies?status=nonsense',
            '/campaign-replies?status[]=new', '/campaign-replies?campaign[]=1',
            '/campaign-replies?page=999'] as $url) {
            $this->get($url)->assertOk("Crashed on {$url}");
        }
    }

    public function test_a_thread_that_is_not_a_campaign_reply_is_not_reachable_here(): void
    {
        $plain = EmailThread::create([
            'mailbox_id' => $this->mailbox->id,
            'thread_key' => 'ordinary',
            'subject' => 'Just an email',
            'reply_status' => 'new',
        ]);

        $this->get("/campaign-replies/{$plain->id}")->assertNotFound();
    }

    // ------------------------------------------------------ the safety line

    public function test_a_reply_body_is_not_inlined_into_the_page(): void
    {
        $thread = $this->thread([], [
            'body_html' => '<p>Hi</p><script>alert(document.cookie)</script>',
        ]);

        $page = $this->get("/campaign-replies/{$thread->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('alert(document.cookie)', $page,
            'A reply body is a stranger HTML like any other.');
        $this->assertMatchesRegularExpression('/<iframe[^>]*\bsandbox\b/i', $page);
    }

    // --------------------------------------------------------------- queue

    public function test_the_queue_puts_new_conversations_first(): void
    {
        $closed = $this->thread(['reply_status' => 'closed', 'last_message_at' => now()]);
        $new = $this->thread(['reply_status' => 'new', 'last_message_at' => now()->subDay()]);

        $content = $this->get('/campaign-replies')->assertOk()->getContent();

        $this->assertLessThan(
            strpos($content, (string) $closed->subject),
            strpos($content, (string) $new->subject),
            'The oldest unanswered reply is the one somebody is waiting on.'
        );
    }

    public function test_the_status_filter_narrows_the_queue(): void
    {
        $new = $this->thread(['reply_status' => 'new']);
        $closed = $this->thread(['reply_status' => 'closed']);

        $this->get('/campaign-replies?status=closed')
            ->assertOk()
            ->assertSee($closed->subject)
            ->assertDontSee($new->subject);
    }

    public function test_the_campaign_filter_narrows_the_queue(): void
    {
        $mine = $this->thread();

        $otherCampaign = Campaign::factory()->forAccount($this->owner->account)
            ->create(['name' => 'Other campaign', 'subject' => 'Other', 'status' => 'completed']);

        $theirs = $this->thread(['campaign_id' => $otherCampaign->id]);

        $this->get('/campaign-replies?campaign='.$this->campaign->id)
            ->assertOk()
            ->assertSee($mine->subject)
            ->assertDontSee($theirs->subject);
    }

    // -------------------------------------------------------------- status

    public function test_opening_a_conversation_marks_it_read(): void
    {
        $thread = $this->thread(['reply_status' => 'new']);

        $this->get("/campaign-replies/{$thread->id}")->assertOk();

        $this->assertSame('read', $thread->fresh()->reply_status,
            'An explicit "mark as read" for something you are looking at is busywork.');
        $this->assertTrue(
            (bool) Email::where('email_thread_id', $thread->id)->first()->is_read
        );
    }

    public function test_a_conversation_can_be_closed_and_reopened(): void
    {
        $thread = $this->thread();

        $this->post("/campaign-replies/{$thread->id}/status", ['status' => 'closed'])
            ->assertRedirect();

        $this->assertSame('closed', $thread->fresh()->reply_status);
        $this->assertNotNull(EmailThread::find($thread->id), 'Closing is not deleting.');

        $this->post("/campaign-replies/{$thread->id}/status", ['status' => 'new'])
            ->assertRedirect();

        $this->assertSame('new', $thread->fresh()->reply_status);
    }

    public function test_an_invented_status_is_refused(): void
    {
        $thread = $this->thread();

        $this->post("/campaign-replies/{$thread->id}/status", ['status' => 'archived'])
            ->assertStatus(422);

        $this->assertSame('new', $thread->fresh()->reply_status);
    }

    // ------------------------------------------------------------- rematch

    public function test_rematch_finds_replies_that_arrived_unlinked(): void
    {
        Email::create([
            'mailbox_id' => $this->mailbox->id,
            'message_id' => 'late@example.com',
            'subject' => 'Re: Our March update',
            'from_email' => 'reader@example.com',
            'in_reply_to' => 'sent-to-reader@senders.test',
            'direction' => 'incoming',
            'folder_type' => 'inbox',
            'is_campaign_reply' => false,
            'received_at' => now(),
        ]);

        $this->post('/campaign-replies/rematch')->assertRedirect()->assertSessionHas('success');

        $this->assertTrue(
            (bool) Email::where('message_id', 'late@example.com')->first()->is_campaign_reply
        );
        $this->assertSame(1, (int) $this->campaign->fresh()->replied_count);
    }

    // -------------------------------------------------------------- detach

    public function test_a_wrongly_matched_reply_can_be_unlinked(): void
    {
        $thread = $this->thread();
        $email = Email::where('email_thread_id', $thread->id)->firstOrFail();

        // The matcher counted it when it linked it.
        DB::update('UPDATE campaigns SET replied_count = 1 WHERE id = ?', [$this->campaign->id]);
        $this->recipient->forceFill(['replied_at' => now()])->save();

        $this->post("/campaign-replies/messages/{$email->id}/detach")->assertRedirect();

        $email->refresh();

        $this->assertFalse((bool) $email->is_campaign_reply);
        $this->assertNull($email->campaign_id);
        $this->assertNotNull(Email::find($email->id), 'The message stays in the inbox.');

        $this->assertSame(0, (int) $this->campaign->fresh()->replied_count,
            'A screen that cannot correct itself makes every number on it untrustworthy.');
        $this->assertNull($this->recipient->fresh()->replied_at);
    }

    public function test_unlinking_one_of_two_replies_keeps_the_recipient_counted(): void
    {
        $thread = $this->thread();
        $first = Email::where('email_thread_id', $thread->id)->firstOrFail();

        $second = Email::create([
            'mailbox_id' => $this->mailbox->id,
            'email_thread_id' => $thread->id,
            'message_id' => 'second-reply@example.com',
            'subject' => 'Re: Our March update',
            'from_email' => 'reader@example.com',
            'direction' => 'incoming',
            'folder_type' => 'inbox',
            'is_campaign_reply' => true,
            'campaign_id' => $this->campaign->id,
            'campaign_recipient_id' => $this->recipient->id,
            'received_at' => now(),
        ]);

        DB::update('UPDATE campaigns SET replied_count = 1 WHERE id = ?', [$this->campaign->id]);
        $this->recipient->forceFill(['replied_at' => now()])->save();

        $this->post("/campaign-replies/messages/{$first->id}/detach")->assertRedirect();

        $this->assertSame(1, (int) $this->campaign->fresh()->replied_count,
            'They did still reply — the other message is proof.');
        $this->assertNotNull($this->recipient->fresh()->replied_at);
        $this->assertTrue((bool) $second->fresh()->is_campaign_reply);
    }

    public function test_a_message_that_is_not_a_campaign_reply_cannot_be_detached(): void
    {
        $plain = Email::create([
            'mailbox_id' => $this->mailbox->id,
            'message_id' => 'plain@example.com',
            'subject' => 'Ordinary',
            'from_email' => 'someone@example.com',
            'direction' => 'incoming',
            'folder_type' => 'inbox',
            'received_at' => now(),
        ]);

        $this->post("/campaign-replies/messages/{$plain->id}/detach")->assertNotFound();
    }

    // ------------------------------------------------------------- tenancy

    public function test_another_accounts_conversation_is_not_reachable(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@replyscreens.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = app(TenantManager::class)->runAs($other->account_id, function () use ($other) {
            $campaign = Campaign::factory()->forAccount($other->account)
                ->create(['name' => 'Theirs', 'subject' => 'Theirs']);

            return EmailThread::create([
                'campaign_id' => $campaign->id,
                'thread_key' => 'theirs',
                'subject' => 'Their conversation',
                'reply_status' => 'new',
            ]);
        });

        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/campaign-replies')->assertOk()->assertDontSee('Their conversation');
        $this->get("/campaign-replies/{$foreign->id}")->assertNotFound();
        $this->post("/campaign-replies/{$foreign->id}/status", ['status' => 'closed'])->assertNotFound();
    }

    // ---------------------------------------------------------- efficiency

    public function test_a_page_of_conversations_does_not_issue_a_query_per_row(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->thread();
        }

        DB::enableQueryLog();
        $this->get('/campaign-replies')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $count,
            "A page of 12 conversations issued {$count} queries — the campaign must be eager loaded.");
    }
}
