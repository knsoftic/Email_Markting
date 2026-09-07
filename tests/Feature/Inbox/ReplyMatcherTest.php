<?php

namespace Tests\Feature\Inbox;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Email;
use App\Models\Mailbox;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Inbox\ReplyMatcher;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Matching an inbound reply to the campaign that caused it.
 *
 * The failure worth guarding is not a missed match — it is a WRONG one. A
 * reply filed under the wrong campaign puts words in a customer's mouth in
 * somebody's reporting, and nobody goes back to check.
 */
class ReplyMatcherTest extends TestCase
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
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@replies.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        app(TenantManager::class)->set($this->owner->account_id);

        $this->mailbox = Mailbox::create([
            'user_id' => $this->owner->id, 'name' => 'Support', 'email' => 'support@senders.test',
            'imap_host' => 'imap.senders.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@senders.test', 'imap_password' => 'secret',
        ]);

        $this->campaign = Campaign::factory()->forAccount($this->owner->account)->create([
            'name' => 'March newsletter',
            'subject' => 'Our March update',
            'status' => 'completed',
            'sent_count' => 1,
            'started_at' => now()->subDays(2),
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
            'reply_token' => 'tok'.str_repeat('A', 29),
        ]);
    }

    protected function matcher(): ReplyMatcher
    {
        return app(ReplyMatcher::class);
    }

    protected function incoming(array $overrides = []): Email
    {
        static $seq = 0;
        $seq++;

        return Email::create(array_merge([
            'mailbox_id' => $this->mailbox->id,
            'message_id' => "reply{$seq}@example.com",
            'subject' => 'Re: Our March update',
            'from_email' => 'reader@example.com',
            'from_name' => 'A Reader',
            'to' => [['name' => null, 'email' => 'support@senders.test']],
            'direction' => 'incoming',
            'folder_type' => 'inbox',
            'body_text' => 'Thanks, this was useful.',
            'received_at' => now(),
        ], $overrides));
    }

    // ---------------------------------------------------------- header path

    public function test_in_reply_to_identifies_the_recipient_exactly(): void
    {
        $found = $this->matcher()->match($this->incoming(['in_reply_to' => 'sent-to-reader@senders.test']));

        $this->assertNotNull($found);
        $this->assertSame($this->recipient->id, $found['recipient']->id);
        $this->assertSame('header', $found['method']);
        $this->assertSame(ReplyMatcher::CERTAIN, $found['confidence']);
    }

    public function test_the_angle_brackets_are_tolerated(): void
    {
        $found = $this->matcher()->match($this->incoming(['in_reply_to' => '<sent-to-reader@senders.test>']));

        $this->assertNotNull($found);
    }

    public function test_references_are_read_from_the_end_backwards(): void
    {
        // A forwarded chain: the campaign message is the LAST entry, and the
        // earlier ones belong to an unrelated conversation.
        $found = $this->matcher()->match($this->incoming([
            'in_reply_to' => null,
            'references' => '<unrelated-a@example.com> <unrelated-b@example.com> <sent-to-reader@senders.test>',
        ]));

        $this->assertNotNull($found);
        $this->assertSame('header', $found['method']);
    }

    public function test_a_header_naming_nothing_of_ours_does_not_match(): void
    {
        $this->assertNull($this->matcher()->match($this->incoming([
            'in_reply_to' => 'somebody-elses@example.com',
            'subject' => 'Completely unrelated',
        ])));
    }

    // ----------------------------------------------------------- token path

    public function test_a_returned_reply_token_identifies_the_recipient(): void
    {
        $found = $this->matcher()->match($this->incoming([
            'subject' => 'Unrelated subject',
            'from_email' => 'someone-else@example.com',
            'to' => [['name' => null, 'email' => 'replies+'.$this->recipient->reply_token.'@senders.test']],
        ]));

        $this->assertNotNull($found);
        $this->assertSame('token', $found['method']);
        $this->assertSame(ReplyMatcher::CERTAIN, $found['confidence']);
    }

    public function test_an_unknown_token_matches_nothing(): void
    {
        $this->assertNull($this->matcher()->match($this->incoming([
            'subject' => 'Unrelated',
            'from_email' => 'stranger@example.com',
            'to' => [['name' => null, 'email' => 'replies+'.str_repeat('Z', 32).'@senders.test']],
        ])));
    }

    // --------------------------------------------------------- address path

    public function test_a_known_address_with_a_matching_subject_is_probable(): void
    {
        $found = $this->matcher()->match($this->incoming(['in_reply_to' => null, 'references' => null]));

        $this->assertNotNull($found);
        $this->assertSame('address', $found['method']);
        $this->assertSame(ReplyMatcher::PROBABLE, $found['confidence'],
            'Address plus subject is a judgement, and a screen has to be able to say so.');
    }

    public function test_the_address_alone_is_never_enough(): void
    {
        // The same contact writing about something else entirely.
        $this->assertNull($this->matcher()->match($this->incoming([
            'in_reply_to' => null,
            'subject' => 'Can I change my delivery address?',
        ])), 'Filing this under the last campaign they received would invent a response that never happened.');
    }

    public function test_a_reply_long_after_the_campaign_is_not_evidence_about_it(): void
    {
        $this->assertNull($this->matcher()->match($this->incoming([
            'in_reply_to' => null,
            'received_at' => now()->addDays(ReplyMatcher::ADDRESS_WINDOW_DAYS + 5),
        ])));
    }

    public function test_a_campaign_subject_with_a_placeholder_cannot_match_by_subject(): void
    {
        // The recipient saw "Hello Ayesha"; the campaign says "Hello
        // {{first_name}}". Comparing them would either never match or, worse,
        // match the wrong thing.
        $this->campaign->forceFill(['subject' => 'Hello {{first_name}}'])->save();

        $this->assertNull($this->matcher()->match($this->incoming([
            'in_reply_to' => null,
            'subject' => 'Re: Hello {{first_name}}',
        ])));
    }

    public function test_a_contact_who_was_never_actually_sent_to_does_not_match(): void
    {
        $this->recipient->forceFill(['status' => 'bounced', 'sent_at' => null])->save();

        $this->assertNull($this->matcher()->match($this->incoming(['in_reply_to' => null])));
    }

    // ------------------------------------------------------------- applying

    public function test_applying_links_the_message_and_counts_the_reply(): void
    {
        $email = $this->incoming(['in_reply_to' => 'sent-to-reader@senders.test']);

        $this->matcher()->apply($email);

        $email->refresh();

        $this->assertTrue((bool) $email->is_campaign_reply);
        $this->assertSame($this->campaign->id, $email->campaign_id);
        $this->assertSame($this->recipient->id, $email->campaign_recipient_id);
        $this->assertSame($this->recipient->subscriber_id, $email->subscriber_id);
        $this->assertNotNull($email->email_thread_id);

        $this->assertNotNull($this->recipient->fresh()->replied_at);
        $this->assertSame(1, (int) $this->campaign->fresh()->replied_count);
    }

    /**
     * A conversation showing "0 messages" while plainly holding one is the
     * kind of thing that makes somebody distrust every number on the screen.
     */
    public function test_the_conversation_counts_the_reply_it_was_created_for(): void
    {
        $email = $this->incoming(['in_reply_to' => 'sent-to-reader@senders.test', 'is_read' => false]);

        $this->matcher()->apply($email);

        $thread = \App\Models\EmailThread::find($email->fresh()->email_thread_id);

        $this->assertSame(1, (int) $thread->messages_count);
        $this->assertSame(1, (int) $thread->unread_count);
    }

    public function test_a_message_already_filed_in_a_thread_is_not_counted_twice(): void
    {
        // The syncer already put it in a thread and counted it there.
        $thread = \App\Models\EmailThread::create([
            'mailbox_id' => $this->mailbox->id,
            'thread_key' => 'already-filed',
            'subject' => 'Re: Our March update',
            'messages_count' => 1,
            'unread_count' => 1,
        ]);

        $email = $this->incoming([
            'in_reply_to' => 'sent-to-reader@senders.test',
            'email_thread_id' => $thread->id,
        ]);

        $this->matcher()->apply($email);

        $this->assertSame(1, (int) $thread->fresh()->messages_count,
            'The syncer counted it; the matcher must not count it again.');
    }

    public function test_a_second_reply_from_the_same_person_does_not_count_twice(): void
    {
        $this->matcher()->apply($this->incoming(['in_reply_to' => 'sent-to-reader@senders.test']));
        $this->matcher()->apply($this->incoming(['in_reply_to' => 'sent-to-reader@senders.test']));

        $this->assertSame(1, (int) $this->campaign->fresh()->replied_count,
            'The reply RATE is about people, not messages.');
    }

    public function test_applying_twice_to_one_message_changes_nothing(): void
    {
        $email = $this->incoming(['in_reply_to' => 'sent-to-reader@senders.test']);

        $this->matcher()->apply($email);
        $this->assertNull($this->matcher()->apply($email->fresh()));

        $this->assertSame(1, (int) $this->campaign->fresh()->replied_count);
    }

    public function test_our_own_sent_copy_is_never_treated_as_a_reply(): void
    {
        // The Sent copy of our own campaign carries the same References.
        $outgoing = $this->incoming([
            'direction' => 'outgoing',
            'folder_type' => 'sent',
            'in_reply_to' => 'sent-to-reader@senders.test',
        ]);

        $this->assertNull($this->matcher()->match($outgoing));
        $this->assertNull($this->matcher()->apply($outgoing));
    }

    // -------------------------------------------------------------- tenancy

    public function test_a_message_id_from_another_account_is_not_matched(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@replies.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreignCampaign = app(TenantManager::class)->runAs($other->account_id, function () use ($other) {
            $campaign = Campaign::factory()->forAccount($other->account)->create([
                'name' => 'Theirs', 'subject' => 'Theirs', 'status' => 'completed',
            ]);

            CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'subscriber_id' => Subscriber::factory()->forAccount($other->account)->create()->id,
                'email' => 'reader@example.com',
                'status' => 'sent',
                'sent_at' => now()->subDay(),
                'message_id' => 'their-message@rival.test',
            ]);

            return $campaign;
        });

        app(TenantManager::class)->set($this->owner->account_id);

        $found = $this->matcher()->match($this->incoming([
            'in_reply_to' => 'their-message@rival.test',
            'subject' => 'Unrelated',
        ]));

        $this->assertNull($found, 'One account replies must never be attributed to another account campaign.');
        $this->assertSame(0, (int) $foreignCampaign->fresh()->replied_count);
    }

    // ------------------------------------------------------------ backfill

    public function test_the_backfill_attributes_messages_that_arrived_earlier(): void
    {
        $this->incoming(['in_reply_to' => 'sent-to-reader@senders.test']);
        $this->incoming(['in_reply_to' => null, 'subject' => 'Nothing to do with it']);

        $result = $this->matcher()->backfill($this->owner->account_id);

        $this->assertSame(2, $result['checked']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame(1, (int) $this->campaign->fresh()->replied_count);
    }

    public function test_suggestions_offer_the_campaigns_this_person_was_actually_sent(): void
    {
        $suggestions = $this->matcher()->suggestionsFor($this->incoming(['subject' => 'Something else']));

        $this->assertCount(1, $suggestions);
        $this->assertSame($this->campaign->id, $suggestions->first()->id);
    }
}
