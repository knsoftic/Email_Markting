<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\Mailbox;
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
 * The two actions on the reading screen that cannot simply redirect back.
 *
 * Both were live defects: "Delete for good" destroyed the row and then sent the
 * reader to its URL (a 404 as the reward for a successful delete), and "Mark as
 * unread" wrote is_read = false and returned to show(), whose first statement
 * marks it read again — a button that did nothing the reader could see.
 *
 * They are only visible when the redirect is followed, which is why the rest of
 * the suite's bare assertRedirect() missed them.
 */
class InboxReadingActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@reading.test',
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
    }

    protected function makeEmail(array $overrides = []): Email
    {
        static $seq = 0;
        $seq++;

        return Email::create(array_merge([
            'mailbox_id' => $this->mailbox->id,
            'message_id' => "read{$seq}@example.com",
            'subject' => "Message {$seq}",
            'from_name' => 'A Customer',
            'from_email' => 'customer@example.com',
            'to' => [['name' => null, 'email' => 'support@senders.test']],
            'body_html' => '<p>Body of the message</p>',
            'direction' => 'incoming',
            'folder_type' => 'inbox',
            'received_at' => now()->subMinutes($seq),
        ], $overrides));
    }

    public function test_delete_for_good_does_not_send_the_reader_to_the_row_it_destroyed(): void
    {
        $email = $this->makeEmail(['folder_type' => 'trash']);
        $showUrl = url("/inbox/{$email->id}");

        $target = $this->from($showUrl)
            ->post('/inbox/actions', ['action' => 'delete', 'ids' => [$email->id]])
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertNull(Email::withTrashed()->find($email->id));
        $this->assertNotSame($showUrl, $target, 'Sent back to the message it just destroyed.');

        $this->get($target)->assertOk("The redirect after a delete answered non-200: {$target}");
    }

    public function test_a_failed_delete_still_returns_to_where_it_came_from(): void
    {
        // Delete outside the Trash removes nothing, so the message the reader
        // was looking at is still there and back() is right.
        $email = $this->makeEmail();
        $showUrl = url("/inbox/{$email->id}");

        $this->from($showUrl)
            ->post('/inbox/actions', ['action' => 'delete', 'ids' => [$email->id]])
            ->assertRedirect($showUrl)
            ->assertSessionHas('warning');

        $this->assertNotNull(Email::find($email->id));
    }

    public function test_mark_as_unread_survives_the_redirect(): void
    {
        $email = $this->makeEmail(['is_read' => true]);
        $showUrl = url("/inbox/{$email->id}");

        $target = $this->from($showUrl)
            ->post("/inbox/{$email->id}/read")
            ->assertRedirect()
            ->headers->get('Location');

        $this->get($target)->assertOk();

        $this->assertFalse((bool) $email->fresh()->is_read,
            "Following the redirect to {$target} marked the message read again.");
    }

    public function test_marking_unread_returns_to_the_folder_the_message_is_in(): void
    {
        $email = $this->makeEmail(['folder_type' => 'archive', 'is_read' => true]);

        $this->from(url("/inbox/{$email->id}"))
            ->post("/inbox/{$email->id}/read")
            ->assertRedirect(url('/inbox/archive'));
    }

    public function test_marking_read_from_a_list_still_goes_back_to_the_list(): void
    {
        $email = $this->makeEmail(['is_read' => false]);
        $listUrl = url('/inbox');

        $this->from($listUrl)
            ->post("/inbox/{$email->id}/read")
            ->assertRedirect($listUrl);

        $this->assertTrue((bool) $email->fresh()->is_read);
    }
}
