<?php

namespace Tests\Feature;

use App\Jobs\Campaigns\SendCampaignChunk;
use App\Jobs\Imap\SyncMailboxJob;
use App\Models\Campaign;
use App\Models\CampaignVariant;
use App\Models\Mailbox;
use App\Models\Role;
use App\Models\SmtpAccount;
use App\Models\User;
use App\Notifications\AccountNotification;
use App\Notifications\AccountNotifier;
use App\Notifications\CampaignCompleted;
use App\Notifications\MailboxSyncDisabled;
use App\Notifications\SmtpAccountCooledDown;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\AbTestService;
use App\Services\Campaigns\CampaignRunner;
use App\Services\Imap\MailboxSyncer;
use App\Services\Smtp\SmtpSelector;
use App\Support\PlanLimits;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/** A notification whose payload cannot be built. Used to prove it is swallowed. */
class ExplodingNotification extends AccountNotification
{
    public function payload(): array
    {
        throw new RuntimeException('payload blew up');
    }
}

/**
 * In-app notifications, the bell, and the notifications screen.
 *
 * The failures this file is really about:
 *
 *  1. A notification taking down the thing it was reporting on. Every one of
 *     these fires from inside a queue job or a scheduled command, in the
 *     middle of work that matters far more than the notification.
 *  2. Telling the wrong people. A row in the wrong bell is a tenancy leak, and
 *     a super admin has no account to be told about.
 *  3. A bell that 404s. The campaign a notification is about outlives it by a
 *     long way, and gets deleted.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@bell.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_custom_smtp' => true, 'allow_imap' => true,
            'max_smtp_accounts' => 5, 'max_mailboxes' => 5,
            'max_emails_per_month' => 100000, 'max_team_members' => 20,
        ])]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);
    }

    // ------------------------------------------------------------- fixtures

    protected function campaign(array $overrides = []): Campaign
    {
        return Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'name' => 'Spring sale',
            'subject' => 'Spring sale inside',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'status' => 'sending',
            'timezone' => 'UTC',
        ], $overrides));
    }

    protected function mailbox(): Mailbox
    {
        return Mailbox::create([
            'user_id' => $this->owner->id,
            'name' => 'Support',
            'email' => 'support@senders.test',
            'imap_host' => 'imap.senders.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@senders.test',
            'imap_password' => 'secret',
        ]);
    }

    /**
     * A second member of this account on the system Staff role, which holds
     * campaigns.view. A user with no role at all holds no permission anywhere
     * in the product, so it would be the wrong fixture for "another member".
     */
    protected function member(string $email, array $overrides = []): User
    {
        return User::factory()->forAccount($this->owner->account)->create(array_merge([
            'email' => $email,
            'email_verified_at' => now(),
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
        ], $overrides));
    }

    /** A member of this account whose role holds no permissions at all. */
    protected function permissionlessMember(string $email): User
    {
        $role = Role::create([
            'account_id' => $this->owner->account_id,
            'name' => 'Reader',
            'slug' => 'reader-'.Str::random(6),
            'is_system' => false,
        ]);

        return User::factory()->forAccount($this->owner->account)->create([
            'email' => $email, 'role_id' => $role->id, 'email_verified_at' => now(),
        ]);
    }

    protected function unread(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    // =====================================================================
    //  Who gets told
    // =====================================================================

    public function test_a_finished_campaign_tells_the_account_and_nobody_else(): void
    {
        // A second account, and a super admin with no account at all.
        $stranger = app(AccountProvisioner::class)->provision([
            'company_name' => 'Other Ltd', 'name' => 'Other', 'email' => 'other@bell.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $admin = User::factory()->create(['account_id' => null, 'is_super_admin' => true]);

        $member = $this->member('member@bell.test');

        $campaign = $this->campaign(['sent_count' => 120, 'failed_count' => 2, 'total_recipients' => 122]);

        // Nothing is pending, so this is the pass that finalises it.
        $this->assertTrue(app(CampaignRunner::class)->tryFinalise($campaign));

        $this->assertSame(1, $this->unread($this->owner), 'The owner is told.');
        $this->assertSame(1, $this->unread($member), 'Another member of the same account is told.');
        $this->assertSame(0, $this->unread($stranger), 'Another account must never see it.');
        $this->assertSame(0, $this->unread($admin), 'A super admin has no account and no bell for this.');

        $data = $this->owner->unreadNotifications()->first()->data;

        $this->assertStringContainsString('Spring sale', $data['title']);
        $this->assertStringContainsString('120 messages sent', $data['body']);
        $this->assertStringContainsString('2 failed', $data['body']);
        $this->assertSame('campaign', $data['kind']);
        $this->assertSame($campaign->id, $data['target_id']);
    }

    public function test_a_member_who_cannot_open_campaigns_is_not_told_about_them(): void
    {
        $reader = $this->permissionlessMember('reader@bell.test');

        $this->assertFalse($reader->hasPermission('campaigns.view'));

        app(CampaignRunner::class)->tryFinalise($this->campaign());

        $this->assertSame(1, $this->unread($this->owner));
        $this->assertSame(0, $this->unread($reader),
            'A notification whose only link answers 403 is noise in that person’s bell forever.');
    }

    public function test_a_suspended_member_is_not_told(): void
    {
        $suspended = $this->member('gone@bell.test', ['status' => 'suspended']);

        app(CampaignRunner::class)->tryFinalise($this->campaign());

        $this->assertSame(0, $this->unread($suspended));
    }

    public function test_the_allowance_warning_goes_to_everybody_including_a_permissionless_member(): void
    {
        $reader = $this->permissionlessMember('everyone@bell.test');

        $account = $this->owner->account;
        $subscription = $account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'max_emails_per_month' => 10,
        ])]);
        $account->refresh();

        PlanLimits::for($account)->increment('emails_sent', 8);

        AccountNotifier::sendingAllowance($account, 7);

        $this->assertSame(1, $this->unread($this->owner));
        $this->assertSame(1, $this->unread($reader),
            'Running out of allowance stops every send in the product, so it is not one team’s news.');
    }

    // =====================================================================
    //  It must never break what it reports on
    // =====================================================================

    public function test_a_notification_that_cannot_be_built_is_swallowed(): void
    {
        $sent = AccountNotifier::send($this->owner->account_id, new ExplodingNotification);

        $this->assertSame(0, $sent);
        $this->assertSame(0, $this->unread($this->owner));
    }

    public function test_a_campaign_still_finalises_when_the_account_has_gone(): void
    {
        $campaign = $this->campaign();

        // The account row is deleted out from under the running send.
        DB::table('accounts')->where('id', $this->owner->account_id)->update(['deleted_at' => now()]);

        $this->assertTrue(app(CampaignRunner::class)->tryFinalise($campaign),
            'The completion must still be recorded even when there is nobody left to tell.');

        $this->assertSame('completed', DB::table('campaigns')->where('id', $campaign->id)->value('status'));
    }

    public function test_sending_to_a_null_account_is_a_no_op(): void
    {
        $this->assertSame(0, AccountNotifier::send(null, new CampaignCompleted(1)));
    }

    // =====================================================================
    //  Each thing that fires one
    // =====================================================================

    public function test_a_completion_notification_fires_exactly_once(): void
    {
        $campaign = $this->campaign();
        $runner = app(CampaignRunner::class);

        $this->assertTrue($runner->tryFinalise($campaign));
        $this->assertFalse($runner->tryFinalise($campaign->refresh()),
            'The second call cannot claim the completion, so it must say nothing.');

        $this->assertSame(1, $this->unread($this->owner));
    }

    public function test_a_failed_campaign_notifies_once_and_a_late_failure_says_nothing(): void
    {
        $campaign = $this->campaign(['sent_count' => 30]);

        (new SendCampaignChunk($campaign->id))->failed(new RuntimeException('Connection to the relay timed out'));

        $this->assertSame(1, $this->unread($this->owner));

        $data = $this->owner->unreadNotifications()->first()->data;
        $this->assertSame('danger', $data['level']);
        $this->assertStringContainsString('Spring sale', $data['title']);
        $this->assertStringContainsString('30 messages', $data['body']);
        $this->assertStringContainsString('Connection to the relay timed out', $data['body']);

        // The campaign is already `failed`, so this call changes nothing.
        (new SendCampaignChunk($campaign->id))->failed(new RuntimeException('again'));

        $this->assertSame(1, $this->unread($this->owner),
            'A late failure must not tell anybody it failed the campaign when it did not.');
    }

    public function test_a_completed_campaign_is_not_re_reported_as_failed(): void
    {
        $campaign = $this->campaign(['status' => 'completed']);

        (new SendCampaignChunk($campaign->id))->failed(new RuntimeException('too late'));

        $this->assertSame(0, $this->unread($this->owner));
        $this->assertSame('completed', DB::table('campaigns')->where('id', $campaign->id)->value('status'));
    }

    public function test_a_split_test_decision_notifies_the_account(): void
    {
        $campaign = $this->campaign([
            'is_ab_test' => true, 'ab_test_type' => 'subject',
            'ab_sample_percent' => 50, 'ab_winner_metric' => 'opens',
        ]);

        $a = CampaignVariant::create([
            'campaign_id' => $campaign->id, 'label' => 'A',
            'subject' => 'Version A', 'share_percent' => 50, 'sent_count' => 10, 'unique_opens' => 4,
        ]);
        CampaignVariant::create([
            'campaign_id' => $campaign->id, 'label' => 'B',
            'subject' => 'Version B', 'share_percent' => 50,
        ]);

        $this->assertNotNull(app(AbTestService::class)->decide($campaign, $a));

        $this->assertSame(1, $this->unread($this->owner));

        $data = $this->owner->unreadNotifications()->first()->data;
        $this->assertStringContainsString('version A won', $data['title']);
        $this->assertSame('split', $data['icon']);

        // The claim is guarded, so a second scheduler deciding cannot send it twice.
        app(AbTestService::class)->decide($campaign->refresh(), $a);

        $this->assertSame(1, $this->unread($this->owner));
    }

    public function test_an_smtp_cooldown_notifies_without_leaking_the_password(): void
    {
        config()->set('knsoftic.smtp_failure_threshold', 1);

        $smtp = SmtpAccount::factory()->forAccount($this->owner->account)->create([
            'name' => 'Main relay', 'password' => 'sup3r-s3cret-pw',
        ]);

        $tripped = app(SmtpSelector::class)->recordFailure(
            $smtp,
            'AUTH LOGIN sup3r-s3cret-pw rejected by the server',
            $this->owner->account_id
        );

        $this->assertTrue($tripped);

        AccountNotifier::send($this->owner->account_id, new SmtpAccountCooledDown($smtp->id, 30));

        $data = $this->owner->unreadNotifications()->first()->data;

        $this->assertStringContainsString('Main relay', $data['title']);
        $this->assertStringNotContainsString('sup3r-s3cret-pw', $data['body'],
            'The notification must read the scrubbed error off the row, never the raw exception.');
        $this->assertStringContainsString('[redacted]', $data['body']);
    }

    public function test_a_mailbox_that_gives_up_notifies_the_account(): void
    {
        $mailbox = $this->mailbox();

        DB::table('mailboxes')->where('id', $mailbox->id)
            ->update(['consecutive_failures' => SyncMailboxJob::GIVE_UP_AFTER - 1]);

        // A syncer that always throws, exactly as SyncMailboxJobTest does.
        $this->app->bind(MailboxSyncer::class, fn () => new class extends MailboxSyncer
        {
            public function __construct() {}

            public function sync(Mailbox $mailbox): array
            {
                throw new RuntimeException('IMAP authentication failed');
            }
        });

        try {
            $this->app->call([new SyncMailboxJob($mailbox->id), 'handle']);
        } catch (Throwable) {
            // The job rethrows so the queue can retry. That is not this test's business.
        }

        $this->assertFalse((bool) DB::table('mailboxes')->where('id', $mailbox->id)->value('sync_enabled'));
        $this->assertSame(1, $this->unread($this->owner));

        $data = $this->owner->unreadNotifications()->first()->data;
        $this->assertStringContainsString('support@senders.test', $data['title']);
        $this->assertSame('mailbox', $data['kind']);
    }

    public function test_the_allowance_warning_fires_on_the_crossing_and_not_again(): void
    {
        $account = $this->owner->account;
        $subscription = $account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'max_emails_per_month' => 10,
        ])]);
        $account->refresh();

        $limits = PlanLimits::for($account);

        // 7 -> 8 crosses 80% of 10.
        $limits->increment('emails_sent', 8);
        AccountNotifier::sendingAllowance($account, 7);
        $this->assertSame(1, $this->unread($this->owner));

        // The same chunk evaluated again crosses nothing.
        AccountNotifier::sendingAllowance($account, 8);
        $this->assertSame(1, $this->unread($this->owner),
            'A level-based check would repeat this line on every chunk for the rest of the month.');

        // 8 -> 10 crosses the ceiling.
        $limits->increment('emails_sent', 2);
        AccountNotifier::sendingAllowance($account, 8);
        $this->assertSame(2, $this->unread($this->owner));

        $titles = $this->owner->unreadNotifications()->get()->map(fn ($n) => $n->data['title'])->all();
        $this->assertTrue((bool) preg_grep('/80%/', $titles));
        $this->assertTrue((bool) preg_grep('/used up/', $titles));
    }

    public function test_an_unlimited_plan_is_told_nothing_about_an_allowance(): void
    {
        $account = $this->owner->account;
        $subscription = $account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'max_emails_per_month' => null,
        ])]);
        $account->refresh();

        PlanLimits::for($account)->increment('emails_sent', 5000);
        AccountNotifier::sendingAllowance($account, 0);

        $this->assertSame(0, $this->unread($this->owner),
            'There is no allowance to be near the end of, so there is nothing honest to say.');
    }

    // =====================================================================
    //  The screen
    // =====================================================================

    public function test_the_page_renders_every_kind_and_survives_a_deleted_target(): void
    {
        $campaign = $this->campaign(['sent_count' => 5]);
        $mailbox = $this->mailbox();
        $smtp = SmtpAccount::factory()->forAccount($this->owner->account)->create(['name' => 'Main relay']);

        app(CampaignRunner::class)->tryFinalise($campaign);
        AccountNotifier::send($this->owner->account_id, new MailboxSyncDisabled($mailbox->id, $mailbox->email, 10, 'Auth failed'));
        AccountNotifier::send($this->owner->account_id, new SmtpAccountCooledDown($smtp->id, 30));

        $gone = $this->campaign(['name' => 'Deleted campaign', 'sent_count' => 1]);
        app(CampaignRunner::class)->tryFinalise($gone);
        $gone->delete();

        $response = $this->get('/notifications')->assertOk();

        $response->assertSee('Spring sale has finished sending')
            ->assertSee('Sync switched off for support@senders.test')
            ->assertSee('Main relay has been paused after repeated failures')
            ->assertSee('Deleted campaign has finished sending')
            ->assertSee('This campaign has since been deleted, so there is no report left to open.')
            ->assertSee('New');

        // Read rows render their target as a plain link (an unread one renders
        // the POST that marks it read on the way through), so read them all and
        // look again: the live campaign must link, the deleted one must not.
        $this->owner->unreadNotifications()->update(['read_at' => now()]);

        $this->get('/notifications')->assertOk()
            ->assertSee(route('campaigns.show', $campaign), false)
            ->assertSee(route('mailboxes.show', $mailbox), false)
            ->assertSee(route('smtp.show', $smtp), false)
            ->assertDontSee(route('campaigns.show', $gone->id), false)
            ->assertSee('This campaign has since been deleted, so there is no report left to open.');
    }

    public function test_the_list_survives_hostile_query_strings(): void
    {
        app(CampaignRunner::class)->tryFinalise($this->campaign());

        $this->get('/notifications?show[]=unread')->assertOk();
        $this->get('/notifications?show=nonsense')->assertOk();
        $this->get('/notifications?page=99')->assertOk();
        $this->get('/notifications?show=unread&page[]=2')->assertOk();
    }

    public function test_the_unread_filter_shows_only_unread(): void
    {
        app(CampaignRunner::class)->tryFinalise($this->campaign(['name' => 'Read one']));
        app(CampaignRunner::class)->tryFinalise($this->campaign(['name' => 'Unread one']));

        $read = $this->owner->notifications()->get()->first(
            fn ($n) => str_contains($n->data['title'], 'Read one')
        );
        $unread = $this->owner->notifications()->get()->first(
            fn ($n) => str_contains($n->data['title'], 'Unread one')
        );
        $read->markAsRead();

        // The bell shows the most recent few whatever their state, so presence
        // of the title alone proves nothing about the list. The per-row Delete
        // form is rendered by the list and only by the list.
        $this->get('/notifications?show=unread')->assertOk()
            ->assertSee(route('notifications.destroy', $unread->id), false)
            ->assertDontSee(route('notifications.destroy', $read->id), false);

        $this->get('/notifications')->assertOk()
            ->assertSee(route('notifications.destroy', $unread->id), false)
            ->assertSee(route('notifications.destroy', $read->id), false);
    }

    public function test_an_empty_list_says_what_will_appear_there(): void
    {
        $this->get('/notifications')->assertOk()->assertSee('No notifications yet');
        $this->get('/notifications?show=unread')->assertOk()->assertSee('Nothing unread');
    }

    // =====================================================================
    //  The controls
    // =====================================================================

    public function test_marking_one_read_from_the_list_stays_on_the_list(): void
    {
        app(CampaignRunner::class)->tryFinalise($this->campaign());
        $row = $this->owner->notifications()->first();

        $this->from('/notifications')
            ->post("/notifications/{$row->id}/read", ['back' => '1'])
            ->assertRedirect('/notifications');

        $this->assertNotNull($row->fresh()->read_at);
        $this->assertSame(0, $this->unread($this->owner));
    }

    public function test_opening_one_marks_it_read_and_lands_on_the_thing(): void
    {
        $campaign = $this->campaign();
        app(CampaignRunner::class)->tryFinalise($campaign);
        $row = $this->owner->notifications()->first();

        $this->post("/notifications/{$row->id}/read")
            ->assertRedirect(route('campaigns.show', $campaign));

        $this->assertNotNull($row->fresh()->read_at);
    }

    public function test_opening_one_whose_target_is_gone_does_not_404(): void
    {
        $campaign = $this->campaign();
        app(CampaignRunner::class)->tryFinalise($campaign);
        $campaign->delete();

        $row = $this->owner->notifications()->first();

        $this->post("/notifications/{$row->id}/read")
            ->assertRedirect('/notifications')
            ->assertSessionHas('info');

        $this->assertNotNull($row->fresh()->read_at);
    }

    public function test_mark_all_read_reports_what_it_actually_did(): void
    {
        app(CampaignRunner::class)->tryFinalise($this->campaign(['name' => 'One']));
        app(CampaignRunner::class)->tryFinalise($this->campaign(['name' => 'Two']));

        $this->from('/notifications')->post('/notifications/read-all')
            ->assertRedirect('/notifications')
            ->assertSessionHas('success', '2 notifications marked as read.');

        $this->assertSame(0, $this->unread($this->owner));

        $this->from('/notifications')->post('/notifications/read-all')
            ->assertSessionHas('success', 'Nothing was unread.');
    }

    public function test_deleting_one_removes_only_that_row(): void
    {
        app(CampaignRunner::class)->tryFinalise($this->campaign(['name' => 'Keep me']));
        app(CampaignRunner::class)->tryFinalise($this->campaign(['name' => 'Bin me']));

        $row = $this->owner->notifications()->get()->first(
            fn ($n) => str_contains($n->data['title'], 'Bin me')
        );

        $this->from('/notifications')->delete("/notifications/{$row->id}")
            ->assertRedirect('/notifications');

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->get('/notifications')->assertOk()->assertSee('Keep me has finished sending');
    }

    public function test_a_colleagues_notification_is_not_reachable(): void
    {
        $member = $this->member('mate@bell.test');

        app(CampaignRunner::class)->tryFinalise($this->campaign());

        $theirs = $member->notifications()->first();
        $this->assertNotNull($theirs, 'Both members were notified, so there is a row to try to reach.');

        $this->post("/notifications/{$theirs->id}/read")->assertNotFound();
        $this->delete("/notifications/{$theirs->id}")->assertNotFound();

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_a_garbage_id_is_never_read_as_a_notification(): void
    {
        $this->post('/notifications/not-a-uuid/read')->assertNotFound();
        $this->delete('/notifications/not-a-uuid')->assertNotFound();
    }

    // =====================================================================
    //  The bell
    // =====================================================================

    public function test_the_bell_shows_the_unread_count_on_an_ordinary_page(): void
    {
        app(CampaignRunner::class)->tryFinalise($this->campaign());

        $this->get('/dashboard')->assertOk()
            ->assertSee('See all notifications')
            ->assertSee('Spring sale has finished sending');
    }

    public function test_the_bell_renders_nothing_for_a_user_with_no_account(): void
    {
        $admin = User::factory()->create([
            'account_id' => null, 'is_super_admin' => true, 'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->get('/admin')->assertOk()->assertDontSee('See all notifications');
    }

    public function test_the_bell_survives_a_notification_row_with_a_broken_payload(): void
    {
        app(CampaignRunner::class)->tryFinalise($this->campaign());

        // A row written by an older release, or by hand.
        DB::table('notifications')
            ->where('notifiable_id', $this->owner->id)
            ->update(['data' => json_encode(['title' => null, 'kind' => ['x'], 'route' => 'nope.missing'])]);

        $this->get('/dashboard')->assertOk();
        $this->get('/notifications')->assertOk()->assertSee('Notification');
    }
}
