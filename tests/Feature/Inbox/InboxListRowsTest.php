<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
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
 * The message list itself — what a row shows, and what it must never show.
 *
 * Companion to InboxScreensTest: that one proves the screens render and the
 * actions behave; this one holds the two lines a list row keeps — a stranger's
 * markup never becomes the excerpt, and a page past the end of the folder does
 * not claim the folder is empty.
 */
class InboxListRowsTest extends TestCase
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
    }

    protected function make(array $overrides = []): Email
    {
        static $seq = 0;
        $seq++;

        return Email::create(array_merge([
            'mailbox_id' => $this->mailbox->id,
            'message_id' => "adv{$seq}@example.com",
            'subject' => "Adv {$seq}",
            'from_name' => 'A Customer',
            'from_email' => 'customer@example.com',
            'to' => [['name' => null, 'email' => 'support@senders.test']],
            'body_html' => '<p>Body</p>',
            'preview' => 'Body',
            'direction' => 'incoming',
            'folder_type' => 'inbox',
            'received_at' => now()->subMinutes($seq),
        ], $overrides));
    }

    public function test_every_folder_survives_hostile_rows(): void
    {
        // A second mailbox turns on the per-row mailbox line.
        Mailbox::create([
            'user_id' => $this->owner->id, 'name' => 'Sales', 'email' => 'sales@senders.test',
            'imap_host' => 'imap.senders.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'sales@senders.test', 'imap_password' => 'secret',
        ]);

        foreach (Email::FOLDERS as $folder) {
            // Nothing at all.
            $this->make([
                'folder_type' => $folder, 'subject' => null, 'from_name' => null, 'from_email' => null,
                'to' => [], 'cc' => null, 'bcc' => null, 'body_html' => null, 'preview' => null,
                'received_at' => null, 'sent_at' => null, 'mailbox_id' => null, 'message_id' => null,
            ]);

            // Every badge at once, plus a draft in Drafts.
            $this->make([
                'folder_type' => $folder,
                'is_draft' => $folder === 'drafts',
                'is_starred' => true,
                'is_important' => true,
                'is_campaign_reply' => true,
                'send_status' => 'failed',
                'error' => 'SMTP said 550',
                'has_attachments' => true,
                'attachments_count' => 3,
                'body_html' => '<head><style>.wrapper{background:#abcdef}</style></head><script>alert(1)</script><p>ok</p>',
                'preview' => null,
                'body_text' => null,
            ]);

            $this->make([
                'folder_type' => $folder,
                'send_status' => 'scheduled',
                'scheduled_at' => now()->addDay(),
                'is_draft' => $folder === 'drafts',
            ]);
        }

        $urls = ['/inbox', '/inbox/sent', '/inbox/drafts', '/inbox/starred', '/inbox/spam', '/inbox/trash', '/inbox/archive'];

        foreach ($urls as $url) {
            $page = $this->get($url)->assertOk("Died on {$url}")->getContent();
            $this->assertStringNotContainsString('alert(1)', $page, "Script text leaked into the row on {$url}");
            $this->assertStringNotContainsString('#abcdef', $page, "Stylesheet text leaked into the row on {$url}");
            if ($url !== '/inbox/starred') {
                // The starred view only holds the starred row, which has a subject.
                $this->assertStringContainsString('(no subject)', $page, "A subjectless row lost its label on {$url}");
                $this->assertStringContainsString(
                    in_array($url, ['/inbox/sent', '/inbox/drafts'], true) ? 'No recipients yet' : 'Unknown sender',
                    $page,
                    "A row with nobody on it lost its label on {$url}"
                );
            }
        }

        foreach ($urls as $url) {
            $this->get($url.'?q=Adv&unread=1&attachments=1&mailbox='.$this->mailbox->id)->assertOk("Died on filtered {$url}");
            $this->get($url.'?q[]=x&unread[]=1&mailbox[]=9&attachments[]=1')->assertOk("Died on array filters {$url}");
            $this->get($url.'?page=999')->assertOk("Died on page 999 {$url}");
        }
    }

    public function test_page_beyond_the_end_does_not_claim_the_folder_is_empty(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->make();
        }

        $page = $this->get('/inbox?page=999')->assertOk()->getContent();

        $this->assertStringNotContainsString('The inbox is empty', $page,
            'The folder has 30 messages; page 999 is past the end, not an empty folder.');
        $this->assertStringContainsString('Nothing on this page', $page);
        $this->assertStringContainsString('page=1', $page, 'The way back has to be a real link.');

        // And the way back actually goes somewhere with messages on it.
        $this->get('/inbox?page=1')->assertOk()->assertSee('Adv 30');
    }

    public function test_destructive_controls_only_appear_where_they_work(): void
    {
        $this->make(['folder_type' => 'trash']);
        $this->make();

        $trash = $this->get('/inbox/trash')->assertOk()->getContent();
        $this->assertStringContainsString('Delete for good', $trash);
        $this->assertStringContainsString('Empty trash', $trash);

        $inbox = $this->get('/inbox')->assertOk()->getContent();
        $this->assertStringNotContainsString('Delete for good', $inbox);
        $this->assertStringNotContainsString('Empty trash', $inbox);
    }

    public function test_a_read_only_member_sees_no_control_that_would_403(): void
    {
        $this->make(['folder_type' => 'trash']);

        $staff = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        foreach (['/inbox', '/inbox/drafts', '/inbox/trash'] as $url) {
            $page = $this->get($url)->assertOk("Died on {$url} as staff")->getContent();
            $this->assertStringNotContainsString('Write a message', $page);
            $this->assertStringNotContainsString('Delete for good', $page);
            $this->assertStringNotContainsString('Empty trash', $page);
        }
    }

    public function test_the_folder_renders_with_no_mailbox_connected(): void
    {
        Mailbox::query()->delete();

        $this->get('/inbox')->assertOk()->assertSee('No mailbox is connected', false);
    }
}
