<?php

namespace Tests\Feature\Imap;

use App\Jobs\Imap\SyncMailboxJob;
use App\Models\Mailbox;
use App\Models\MailboxFolder;
use App\Models\Role;
use App\Models\SmtpAccount;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Imap\ImapGateway;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The mailbox screens through HTTP.
 *
 * The thing most worth guarding here is the credential: a mailbox form that
 * renders the stored password back into the page hands it to anyone who can
 * read the HTML, including a browser extension.
 */
class MailboxScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@boxes.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $this->allow(['allow_imap' => true, 'max_mailboxes' => 5, 'max_storage_mb' => 512]);

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->mailbox = $this->makeMailbox();
    }

    protected function allow(array $overrides): void
    {
        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], $overrides)]);
        $this->owner->account->refresh();
    }

    protected function makeMailbox(array $overrides = []): Mailbox
    {
        static $seq = 0;
        $seq++;

        return Mailbox::create(array_merge([
            'user_id' => $this->owner->id,
            'name' => "Support {$seq}",
            'email' => "support{$seq}@senders.test",
            'provider' => 'gmail',
            'imap_host' => 'imap.gmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => "support{$seq}@senders.test",
            'imap_password' => 'super-secret-app-password',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sales',
            'email' => 'sales@senders.test',
            'provider' => 'custom',
            'imap_host' => 'imap.senders.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'sales@senders.test',
            'imap_password' => 'a-password',
            'imap_validate_cert' => '1',
            'sync_enabled' => '1',
            'sync_interval_minutes' => 5,
            'sync_limit' => 100,
            'is_active' => '1',
        ], $overrides);
    }

    // ------------------------------------------------------------ rendering

    public function test_every_mailbox_screen_renders(): void
    {
        $this->get('/mailboxes')->assertOk()->assertSee('Support 1');
        $this->get('/mailboxes/create')->assertOk();
        $this->get("/mailboxes/{$this->mailbox->id}")->assertOk();
        $this->get("/mailboxes/{$this->mailbox->id}/edit")->assertOk();
    }

    public function test_the_screens_render_for_a_mailbox_that_has_never_synced(): void
    {
        // Every timestamp null is the state a mailbox is in for its first few
        // minutes, and the one most likely to be missed.
        $fresh = $this->makeMailbox(['status' => 'pending']);

        $this->get('/mailboxes')->assertOk();
        $this->get("/mailboxes/{$fresh->id}")->assertOk();
    }

    public function test_the_detail_screen_renders_in_every_status(): void
    {
        foreach (['pending', 'connected', 'error', 'disconnected'] as $status) {
            $this->mailbox->forceFill([
                'status' => $status,
                'last_error' => $status === 'error' ? 'Authentication failed' : null,
                'last_error_at' => $status === 'error' ? now() : null,
            ])->save();

            $this->get("/mailboxes/{$this->mailbox->id}")->assertOk("Died on status {$status}");
        }
    }

    public function test_a_malformed_query_string_does_not_crash_the_list(): void
    {
        foreach (['/mailboxes?q[]=x', '/mailboxes?page=999'] as $url) {
            $this->get($url)->assertOk("Crashed on {$url}");
        }
    }

    // ---------------------------------------------------------- credentials

    public function test_the_stored_password_is_never_rendered(): void
    {
        $content = $this->get("/mailboxes/{$this->mailbox->id}/edit")->assertOk()->getContent();

        $this->assertStringNotContainsString('super-secret-app-password', $content,
            'A form that echoes the stored credential hands it to anything that can read the page.');

        $show = $this->get("/mailboxes/{$this->mailbox->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('super-secret-app-password', $show);
    }

    public function test_saving_with_a_blank_password_keeps_the_stored_one(): void
    {
        $this->put("/mailboxes/{$this->mailbox->id}", $this->payload([
            'name' => 'Renamed',
            'email' => $this->mailbox->email,
            'imap_username' => $this->mailbox->imap_username,
            'imap_password' => '',
        ]))->assertRedirect();

        $mailbox = $this->mailbox->fresh();

        $this->assertSame('Renamed', $mailbox->name);
        $this->assertSame('super-secret-app-password', $mailbox->imap_password,
            'Otherwise fixing a typo in the host would silently wipe the credential.');
    }

    public function test_the_password_is_encrypted_at_rest(): void
    {
        $raw = \Illuminate\Support\Facades\DB::table('mailboxes')
            ->where('id', $this->mailbox->id)->value('imap_password');

        $this->assertNotSame('super-secret-app-password', $raw);
        $this->assertSame('super-secret-app-password', $this->mailbox->fresh()->imap_password);
    }

    // -------------------------------------------------------------- saving

    public function test_a_mailbox_can_be_connected(): void
    {
        $this->post('/mailboxes', $this->payload())->assertRedirect();

        $mailbox = Mailbox::where('email', 'sales@senders.test')->firstOrFail();

        $this->assertSame('pending', $mailbox->status,
            'Nothing has been tested yet, so it must not claim to be connected.');
        $this->assertSame($this->owner->account_id, $mailbox->account_id);
    }

    public function test_two_mailboxes_cannot_share_an_address(): void
    {
        $this->post('/mailboxes', $this->payload(['email' => $this->mailbox->email]))
            ->assertSessionHasErrors('email');
    }

    public function test_a_bad_host_is_refused(): void
    {
        $this->post('/mailboxes', $this->payload(['imap_host' => 'https://imap.example.com/path']))
            ->assertSessionHasErrors('imap_host');
    }

    public function test_changing_the_connection_marks_it_untested_again(): void
    {
        $this->mailbox->forceFill(['status' => 'connected', 'last_tested_at' => now()])->save();

        $this->put("/mailboxes/{$this->mailbox->id}", $this->payload([
            'email' => $this->mailbox->email,
            'imap_host' => 'imap.somewhere-else.test',
            'imap_username' => $this->mailbox->imap_username,
            'imap_password' => '',
        ]))->assertRedirect();

        $mailbox = $this->mailbox->fresh();

        $this->assertSame('pending', $mailbox->status,
            'Saying "connected" about settings never tried is a small lie that costs an hour later.');
        $this->assertNull($mailbox->last_tested_at);
    }

    public function test_renaming_alone_does_not_invalidate_a_good_connection(): void
    {
        $this->mailbox->forceFill(['status' => 'connected', 'last_tested_at' => now()])->save();

        $this->put("/mailboxes/{$this->mailbox->id}", $this->payload([
            'name' => 'Just a new label',
            'email' => $this->mailbox->email,
            'imap_host' => $this->mailbox->imap_host,
            'imap_username' => $this->mailbox->imap_username,
            'imap_password' => '',
        ]))->assertRedirect();

        $this->assertSame('connected', $this->mailbox->fresh()->status);
    }

    public function test_an_smtp_account_from_another_tenant_cannot_be_attached(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@boxes.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = SmtpAccount::factory()->forAccount($other->account)->create();

        $this->post('/mailboxes', $this->payload(['smtp_account_id' => $foreign->id]))
            ->assertSessionHasErrors('smtp_account_id');
    }

    // -------------------------------------------------------------- actions

    public function test_sync_now_queues_the_job(): void
    {
        Queue::fake();

        $this->post("/mailboxes/{$this->mailbox->id}/sync")->assertRedirect();

        Queue::assertPushed(SyncMailboxJob::class);
    }

    public function test_a_switched_off_mailbox_cannot_be_synced(): void
    {
        Queue::fake();

        $this->mailbox->forceFill(['is_active' => false])->save();

        $this->post("/mailboxes/{$this->mailbox->id}/sync")->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_resume_switches_automatic_syncing_back_on_and_clears_the_streak(): void
    {
        $this->mailbox->forceFill([
            'sync_enabled' => false, 'consecutive_failures' => 12,
            'status' => 'error', 'last_error' => 'Authentication failed',
        ])->save();

        $this->post("/mailboxes/{$this->mailbox->id}/resume")->assertRedirect();

        $mailbox = $this->mailbox->fresh();

        $this->assertTrue((bool) $mailbox->sync_enabled);
        $this->assertSame(0, (int) $mailbox->consecutive_failures,
            'Leaving the streak would switch it off again on the very next failure.');
        $this->assertNull($mailbox->last_error);
    }

    public function test_deleting_disconnects_without_touching_the_messages(): void
    {
        $this->mailbox->emails()->create([
            'account_id' => $this->owner->account_id,
            'message_id' => 'kept@example.com',
            'subject' => 'Still here',
            'from_email' => 'someone@example.com',
            'received_at' => now(),
        ]);

        $this->delete("/mailboxes/{$this->mailbox->id}")->assertRedirect('/mailboxes');

        $this->assertNull(Mailbox::find($this->mailbox->id));
        $this->assertSame(1, \App\Models\Email::withoutGlobalScopes()
            ->where('message_id', 'kept@example.com')->count(),
            'Disconnecting a mailbox must not destroy mail that was already synced.');
    }

    /**
     * 'disconnected' sat in the enum from Phase 1 with nothing ever writing
     * it, so the UI carried a branch that could not happen. Switching a
     * mailbox off is what it means.
     */
    public function test_switching_a_mailbox_off_records_it_as_disconnected(): void
    {
        $this->mailbox->forceFill(['status' => 'connected'])->save();

        $this->post("/mailboxes/{$this->mailbox->id}/toggle")->assertRedirect();

        $mailbox = $this->mailbox->fresh();

        $this->assertFalse((bool) $mailbox->is_active);
        $this->assertSame('disconnected', $mailbox->status);

        $this->post("/mailboxes/{$this->mailbox->id}/toggle")->assertRedirect();

        $mailbox = $this->mailbox->fresh();

        $this->assertTrue((bool) $mailbox->is_active);
        $this->assertSame('pending', $mailbox->status,
            'Nothing has been tried since it came back on, so it must not claim to be connected.');
    }

    // ---------------------------------------------------------- plan limits

    public function test_a_plan_without_imap_cannot_add_a_mailbox(): void
    {
        $this->allow(['allow_imap' => false]);

        $this->get('/mailboxes')->assertOk();

        $this->from('/mailboxes')->get('/mailboxes/create')
            ->assertRedirect('/mailboxes')->assertSessionHas('error');

        $this->from('/mailboxes')->post('/mailboxes', $this->payload())
            ->assertRedirect('/mailboxes')->assertSessionHas('error');
    }

    public function test_the_mailbox_limit_is_enforced(): void
    {
        $this->allow(['max_mailboxes' => 1]);

        $this->from('/mailboxes')->post('/mailboxes', $this->payload())
            ->assertRedirect('/mailboxes')->assertSessionHas('error');

        $this->assertSame(1, Mailbox::count());
    }

    // ---------------------------------------------------------- permissions

    public function test_a_viewer_cannot_change_anything(): void
    {
        $staff = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        $role = Role::withoutGlobalScopes()->where('slug', Role::STAFF)->first();
        $role->permissions()->syncWithoutDetaching(
            \App\Models\Permission::where('slug', 'mailboxes.view')->pluck('id')->all()
        );

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/mailboxes')->assertOk();
        $this->get("/mailboxes/{$this->mailbox->id}")->assertOk();

        $this->get('/mailboxes/create')->assertForbidden();
        $this->get("/mailboxes/{$this->mailbox->id}/edit")->assertForbidden();
        $this->put("/mailboxes/{$this->mailbox->id}", $this->payload())->assertForbidden();
        $this->delete("/mailboxes/{$this->mailbox->id}")->assertForbidden();
        $this->post("/mailboxes/{$this->mailbox->id}/test")->assertForbidden();
    }

    // ------------------------------------------------------------- tenancy

    public function test_another_accounts_mailbox_is_not_reachable(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival2', 'email' => 'rival2@boxes.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = app(TenantManager::class)->runAs($other->account_id, fn () => Mailbox::create([
            'name' => 'Theirs', 'email' => 'theirs@rival.test', 'provider' => 'custom',
            'imap_host' => 'imap.rival.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'theirs@rival.test', 'imap_password' => 'nope',
        ]));

        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/mailboxes')->assertOk()->assertDontSee('theirs@rival.test');
        $this->get("/mailboxes/{$foreign->id}")->assertNotFound();
        $this->put("/mailboxes/{$foreign->id}", $this->payload())->assertNotFound();
        $this->post("/mailboxes/{$foreign->id}/sync")->assertNotFound();
        $this->delete("/mailboxes/{$foreign->id}")->assertNotFound();
    }

    // -------------------------------------------------------------- folders

    public function test_the_detail_screen_lists_the_folders(): void
    {
        MailboxFolder::create([
            'mailbox_id' => $this->mailbox->id,
            'path' => '[Gmail]/Sent Mail',
            'display_name' => 'Sent Mail',
            'type' => 'sent',
            'messages_count' => 42,
        ]);

        $this->get("/mailboxes/{$this->mailbox->id}")
            ->assertOk()
            ->assertSee('Sent Mail');
    }
}
