<?php

namespace Tests\Feature\Imap;

use App\Jobs\Imap\SyncMailboxJob;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Imap\ImapGateway;
use App\Services\Imap\MailboxSyncer;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;

/**
 * The sync job and the scheduler command.
 *
 * A mailbox whose password changed will fail every five minutes forever. What
 * these test is that the app notices, says something useful, and eventually
 * stops spending the provider's rate limit relearning the same fact.
 */
class SyncMailboxJobTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Mailbox $mailbox;

    protected FakeImapGateway $imap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@job.test',
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
        $this->imap->folderList = [
            ['path' => 'INBOX', 'name' => 'INBOX', 'delimiter' => '/', 'attributes' => [], 'no_select' => false],
        ];
        $this->app->instance(ImapGateway::class, $this->imap);
    }

    protected function runJob(?Mailbox $mailbox = null): void
    {
        $this->app->call([new SyncMailboxJob(($mailbox ?? $this->mailbox)->id), 'handle']);
    }

    /** Replaces the syncer with one that always throws. */
    protected function failWith(\Throwable $e): void
    {
        $this->app->bind(MailboxSyncer::class, function () use ($e) {
            return new class($e) extends MailboxSyncer
            {
                public function __construct(private \Throwable $error)
                {
                    // Deliberately does not call the parent: nothing else is used.
                }

                public function sync(Mailbox $mailbox): array
                {
                    throw $this->error;
                }
            };
        });
    }

    // ---------------------------------------------------------------- happy

    public function test_the_job_syncs_and_marks_the_mailbox_healthy(): void
    {
        $this->imap->messages['INBOX'] = [[
            'uid' => 1, 'message_id' => 'a@example.com', 'in_reply_to' => null, 'references' => null,
            'subject' => 'Hello', 'from_name' => 'A', 'from_email' => 'a@example.com',
            'to' => [], 'cc' => [], 'reply_to' => null, 'date' => now(),
            'body_html' => '<p>Hi</p>', 'body_text' => 'Hi', 'size' => 10,
            'seen' => false, 'flagged' => false, 'draft' => false, 'attachments' => [],
        ]];

        $this->runJob();

        $mailbox = $this->mailbox->fresh();

        $this->assertSame('connected', $mailbox->status);
        $this->assertSame('success', $mailbox->last_sync_status);
        $this->assertSame(0, (int) $mailbox->consecutive_failures);
        $this->assertSame(1, (int) $mailbox->messages_count);
    }

    public function test_a_switched_off_mailbox_is_left_alone(): void
    {
        $this->mailbox->forceFill(['is_active' => false])->save();

        $this->runJob();

        $this->assertNull($this->mailbox->fresh()->last_sync_at,
            'Nothing should have been attempted at all.');
    }

    public function test_a_deleted_mailbox_is_a_no_op(): void
    {
        $id = $this->mailbox->id;
        $this->mailbox->forceDelete();

        $this->app->call([new SyncMailboxJob($id), 'handle']);

        $this->assertTrue(true, 'Reaching here without throwing is the assertion.');
    }

    // -------------------------------------------------------------- failure

    public function test_a_failure_is_counted_and_explained(): void
    {
        $this->failWith(new AuthFailedException('Authentication failed for support@senders.test'));

        try {
            $this->runJob();
        } catch (\Throwable) {
            // The job rethrows so the queue can retry; that is expected.
        }

        $mailbox = $this->mailbox->fresh();

        $this->assertSame('error', $mailbox->status);
        $this->assertSame('failed', $mailbox->last_sync_status);
        $this->assertSame(1, (int) $mailbox->consecutive_failures);
        $this->assertStringContainsString('app-specific password', mb_strtolower((string) $mailbox->last_error),
            'A bare "authentication failed" tells the operator nothing they can act on.');
    }

    /**
     * "Check your password" is useless advice for a Gmail mailbox — the
     * password is not the problem, Google stopped accepting it. The classifier
     * has to know that.
     */
    public function test_the_advice_is_provider_specific_where_the_provider_is_known(): void
    {
        $this->mailbox->forceFill(['imap_host' => 'imap.gmail.com'])->save();

        $this->failWith(new AuthFailedException('[AUTHENTICATIONFAILED] Invalid credentials'));

        try {
            $this->runJob();
        } catch (\Throwable) {
        }

        $error = mb_strtolower((string) $this->mailbox->fresh()->last_error);

        $this->assertStringContainsString('app password', $error);
        $this->assertStringContainsString('2-step verification', $error);
    }

    public function test_the_credential_never_reaches_the_stored_error(): void
    {
        $this->failWith(new RuntimeException('LOGIN support@senders.test secret failed'));

        try {
            $this->runJob();
        } catch (\Throwable) {
        }

        $error = (string) $this->mailbox->fresh()->last_error;

        $this->assertStringNotContainsString('secret', $error);
        $this->assertStringContainsString('[redacted]', $error);
    }

    public function test_sync_switches_itself_off_after_repeated_failures(): void
    {
        $this->mailbox->forceFill(['consecutive_failures' => SyncMailboxJob::GIVE_UP_AFTER - 1])->save();

        $this->failWith(new AuthFailedException('Authentication failed'));

        try {
            $this->runJob();
        } catch (\Throwable) {
        }

        $mailbox = $this->mailbox->fresh();

        $this->assertFalse((bool) $mailbox->sync_enabled,
            'Polling a mailbox that has failed ten times running spends the provider rate limit to relearn the same fact.');
        $this->assertSame(SyncMailboxJob::GIVE_UP_AFTER, (int) $mailbox->consecutive_failures);
    }

    public function test_a_healthy_sync_clears_an_earlier_failure_streak(): void
    {
        $this->mailbox->forceFill([
            'consecutive_failures' => 4, 'status' => 'error', 'last_error' => 'Previously broken',
        ])->save();

        $this->runJob();

        $mailbox = $this->mailbox->fresh();

        $this->assertSame(0, (int) $mailbox->consecutive_failures);
        $this->assertNull($mailbox->last_error);
        $this->assertSame('connected', $mailbox->status);
    }

    /**
     * A failed pass is still a pass. Without this the row kept the timestamp
     * of the last SUCCESSFUL sync, and a card showed "last sync failed 9 hours
     * ago" directly above "failed 2 minutes ago" — two lines about the same
     * event, hours apart.
     */
    public function test_a_failed_pass_stamps_the_sync_time_too(): void
    {
        $this->mailbox->forceFill(['last_sync_at' => now()->subHours(9)])->save();

        $this->failWith(new AuthFailedException('Authentication failed'));

        try {
            $this->runJob();
        } catch (\Throwable) {
        }

        $mailbox = $this->mailbox->fresh();

        $this->assertTrue(
            $mailbox->last_sync_at->diffInMinutes(now()) < 1,
            'The last sync time must be the failed attempt, not the last success.'
        );
        $this->assertTrue($mailbox->last_error_at->diffInSeconds($mailbox->last_sync_at) < 2,
            'The two timestamps describe the same event and must agree.');
    }

    // -------------------------------------------------------------- locking

    public function test_two_syncs_of_one_mailbox_cannot_overlap(): void
    {
        $middleware = collect((new SyncMailboxJob(1))->middleware())
            ->first(fn ($m) => $m instanceof WithoutOverlapping);

        $this->assertNotNull($middleware,
            'Most providers allow very few simultaneous IMAP connections per account.');
    }

    // -------------------------------------------------------------- tenancy

    public function test_the_job_binds_its_own_tenant(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@job.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $this->imap->messages['INBOX'] = [[
            'uid' => 1, 'message_id' => 'b@example.com', 'in_reply_to' => null, 'references' => null,
            'subject' => 'Hello', 'from_name' => null, 'from_email' => 'b@example.com',
            'to' => [], 'cc' => [], 'reply_to' => null, 'date' => now(),
            'body_html' => null, 'body_text' => 'Hi', 'size' => 10,
            'seen' => false, 'flagged' => false, 'draft' => false, 'attachments' => [],
        ]];

        // A long-lived worker carries whatever the previous job left bound.
        app(TenantManager::class)->set($other->account_id);

        $this->runJob();

        $this->assertSame(
            $this->owner->account_id,
            (int) \App\Models\Email::withoutGlobalScopes()->where('message_id', 'b@example.com')->value('account_id')
        );
    }

    // ------------------------------------------------------------- schedule

    public function test_the_command_queues_only_mailboxes_that_are_due(): void
    {
        Queue::fake();

        // Synced a minute ago on a five-minute interval: not due.
        $this->mailbox->forceFill(['last_sync_at' => now()->subMinute(), 'sync_interval_minutes' => 5])->save();

        $this->artisan('mailboxes:sync')->assertSuccessful();

        Queue::assertNothingPushed();

        $this->mailbox->forceFill(['last_sync_at' => now()->subMinutes(10)])->save();

        $this->artisan('mailboxes:sync')->assertSuccessful();

        Queue::assertPushed(SyncMailboxJob::class, 1);
    }

    public function test_a_mailbox_with_sync_switched_off_is_never_queued(): void
    {
        Queue::fake();

        $this->mailbox->forceFill(['sync_enabled' => false, 'last_sync_at' => now()->subDay()])->save();

        $this->artisan('mailboxes:sync')->assertSuccessful();
        $this->artisan('mailboxes:sync', ['--force' => true])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_single_mailbox_can_be_forced(): void
    {
        Queue::fake();

        $this->mailbox->forceFill(['last_sync_at' => now()])->save();

        $this->artisan('mailboxes:sync', ['--mailbox' => $this->mailbox->id])->assertSuccessful();

        Queue::assertPushed(SyncMailboxJob::class, 1);
    }
}
