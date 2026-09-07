<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\Campaigns\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\EmailCompiler;
use App\Services\Campaigns\RecipientGenerator;
use App\Services\Smtp\MailerFactory;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Its own recorder rather than the one in CampaignRunnerTest: a test file has
 * to run on its own, and PSR-4 will not find a class declared inside another
 * test's file.
 */
class ChunkTransport implements TransportInterface
{
    /** @var array<int, string> */
    public array $sentTo = [];

    public function send(RawMessage $message, $envelope = null): ?SentMessage
    {
        preg_match('/^To:\s*(.+)$/mi', $message->toString(), $m);
        $this->sentTo[] = trim($m[1] ?? 'unknown');

        return null;
    }

    public function __toString(): string
    {
        return 'chunk-recorder';
    }
}

class ChunkMailerFactory extends MailerFactory
{
    public ChunkTransport $transport;

    public function __construct()
    {
        $this->transport = new ChunkTransport;
    }

    public function for(SmtpAccount $account, ?int $timeout = null): TransportInterface
    {
        return $this->transport;
    }
}

/**
 * The queue job itself: what it re-queues, what it refuses to re-queue, and
 * what it does to the campaign when it finally gives up.
 *
 * CampaignRunnerTest covers what happens INSIDE a chunk. This covers the
 * chaining around it, which is where a campaign either keeps moving or quietly
 * stops halfway with nobody noticing.
 */
class SendCampaignChunkTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected ChunkMailerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@chunk.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 100000,
        ]]);
        $this->owner->account->refresh();

        app(TenantManager::class)->set($this->owner->account_id);

        $this->factory = new ChunkMailerFactory;
        $this->app->instance(MailerFactory::class, $this->factory);

        SmtpAccount::factory()->forAccount($this->owner->account)->create(['name' => 'Relay']);
    }

    protected function campaignWith(int $contacts, array $overrides = []): Campaign
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();

        for ($i = 1; $i <= $contacts; $i++) {
            Subscriber::factory()->forAccount($this->owner->account)
                ->create(['email' => "person{$i}@example.com"])
                ->lists()->attach($list->id, ['subscribed_at' => now()]);
        }

        $doc = ['settings' => [], 'blocks' => [
            ['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hi']],
            ['id' => 'f', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
        ]];

        $compiler = app(EmailCompiler::class);

        $campaign = Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'name' => 'Chunked campaign',
            'subject' => 'Hello',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'status' => 'sending',
            'blocks' => $doc,
            'html' => $compiler->compile($doc),
            'plain_text' => $compiler->compileText($doc),
            'audience' => ['lists' => [$list->id]],
        ], $overrides));

        app(RecipientGenerator::class)->generate($campaign);

        return $campaign->fresh();
    }

    protected function runChunk(Campaign $campaign): void
    {
        $this->app->call([new SendCampaignChunk($campaign->id), 'handle']);
    }

    // ------------------------------------------------------------ chaining

    public function test_it_re_queues_itself_while_recipients_remain(): void
    {
        config()->set('knsoftic.campaign_chunk', 2);

        $campaign = $this->campaignWith(5);

        Queue::fake();
        $this->runChunk($campaign);

        Queue::assertPushed(SendCampaignChunk::class, 1);

        $this->assertGreaterThan(0, CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'sent')->count());
        $this->assertGreaterThan(0, CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')->count(), 'There is still work, hence the next chunk.');
    }

    public function test_it_stops_chaining_once_everybody_has_been_sent_to(): void
    {
        $campaign = $this->campaignWith(3);

        // First pass sends everybody.
        $this->runChunk($campaign);

        Queue::fake();

        // A second pass finds nothing to claim and must not queue a third.
        $this->runChunk($campaign->fresh());

        Queue::assertNothingPushed();
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    public function test_a_paused_campaign_neither_sends_nor_re_queues(): void
    {
        $campaign = $this->campaignWith(4, ['status' => 'paused']);

        Queue::fake();
        $this->runChunk($campaign);

        Queue::assertNothingPushed();
        $this->assertSame([], $this->factory->transport->sentTo);
        $this->assertSame(4, CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')->count(), 'The queue is kept so Resume can pick it up.');
    }

    public function test_a_cancelled_campaign_is_not_restarted_by_a_chunk_still_on_the_queue(): void
    {
        $campaign = $this->campaignWith(3, ['status' => 'cancelled']);

        Queue::fake();
        $this->runChunk($campaign);

        Queue::assertNothingPushed();
        $this->assertSame([], $this->factory->transport->sentTo);
    }

    public function test_no_smtp_capacity_backs_off_instead_of_spinning(): void
    {
        SmtpAccount::withoutGlobalScope(\App\Models\Scopes\AccountScope::class)
            ->where('account_id', $this->owner->account_id)
            ->update(['daily_limit' => 1, 'sent_today' => 1, 'day_reset_at' => now()]);

        $campaign = $this->campaignWith(3);

        Queue::fake();
        $this->runChunk($campaign);

        Queue::assertPushed(SendCampaignChunk::class, function (SendCampaignChunk $job) {
            // Delayed by minutes, not seconds: retrying every second against a
            // provider that has cut us off is how an account gets blocked.
            return $job->delay !== null
                && $job->delay->getTimestamp() >= now()->addMinutes(SendCampaignChunk::DEFER_MINUTES - 1)->getTimestamp();
        });

        $this->assertSame('sending', $campaign->fresh()->status,
            'Running out of capacity is not a failure — it is a wait.');
    }

    public function test_a_deleted_campaign_is_a_no_op(): void
    {
        $campaign = $this->campaignWith(2);
        $id = $campaign->id;
        $campaign->forceDelete();

        Queue::fake();
        $this->app->call([new SendCampaignChunk($id), 'handle']);

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------- tenancy

    public function test_the_job_binds_its_own_tenant_rather_than_inheriting_one(): void
    {
        $campaign = $this->campaignWith(2);

        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@chunk.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        // A long-lived worker carries whatever the previous job left bound.
        app(TenantManager::class)->set($other->account_id);

        Queue::fake();
        $this->runChunk($campaign);

        $this->assertCount(2, $this->factory->transport->sentTo,
            'The job must bind the campaign\'s account, not the one left over from the last job.');
    }

    // -------------------------------------------------------------- locking

    public function test_two_chunks_of_one_campaign_cannot_overlap(): void
    {
        $job = new SendCampaignChunk(42);

        $middleware = collect($job->middleware())
            ->first(fn ($m) => $m instanceof WithoutOverlapping);

        $this->assertNotNull($middleware, 'Without this, two workers would claim and send the same chunk.');
    }

    // -------------------------------------------------------------- failure

    public function test_the_final_failure_marks_the_campaign_failed_with_the_reason(): void
    {
        $campaign = $this->campaignWith(2);

        (new SendCampaignChunk($campaign->id))->failed(new RuntimeException('SMTP host unreachable'));

        $campaign->refresh();

        $this->assertSame('failed', $campaign->status);
        $this->assertStringContainsString('SMTP host unreachable', (string) $campaign->last_error);
    }

    public function test_a_late_failure_cannot_overwrite_a_finished_campaign(): void
    {
        $campaign = $this->campaignWith(1, ['status' => 'completed']);

        (new SendCampaignChunk($campaign->id))->failed(new RuntimeException('too late'));

        $this->assertSame('completed', $campaign->fresh()->status,
            'A retry that fails after the run finished must not rewrite history.');
        $this->assertNull($campaign->fresh()->last_error);
    }
}
