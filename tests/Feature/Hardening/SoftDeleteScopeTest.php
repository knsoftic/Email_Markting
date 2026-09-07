<?php

namespace Tests\Feature\Hardening;

use App\Jobs\Campaigns\SendCampaignChunk;
use App\Jobs\Contacts\ProcessSubscriberImport;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Import;
use App\Models\Mailbox;
use App\Models\Role;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\CampaignRunner;
use App\Services\Campaigns\EmailCompiler;
use App\Services\Campaigns\RecipientGenerator;
use App\Services\Smtp\MailerFactory;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/** Remembers every address it was asked to deliver to. */
class ScopeProbeTransport implements TransportInterface
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
        return 'scope-probe';
    }
}

class ScopeProbeMailerFactory extends MailerFactory
{
    public ScopeProbeTransport $transport;

    public function __construct()
    {
        $this->transport = new ScopeProbeTransport;
    }

    public function for(SmtpAccount $account, ?int $timeout = null): TransportInterface
    {
        return $this->transport;
    }
}

/**
 * Deleting something has to actually stop it.
 *
 * `withoutGlobalScopes()` — plural — reads like "ignore tenancy" and means
 * "ignore every global scope, including the one that hides deleted rows". It
 * has now produced a real defect in four separate parts of this application,
 * and every one had the same shape: an operator deleted something and the app
 * carried on using it.
 *
 * These tests do not check which method was called. They check the behaviour
 * an operator would notice, so they still hold if the code is rewritten.
 */
class SoftDeleteScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected ScopeProbeMailerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Hardened Ltd', 'name' => 'Owner', 'email' => 'owner@hardening.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5,
            'max_emails_per_month' => 100000, 'max_team_members' => 3,
            'allow_inbox' => true, 'max_mailboxes' => 5,
        ])]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->factory = new ScopeProbeMailerFactory;
        $this->app->instance(MailerFactory::class, $this->factory);

        SmtpAccount::factory()->forAccount($this->owner->account)
            ->create(['name' => 'Relay', 'from_email' => 'relay@hardening.test', 'from_name' => 'Relay']);
    }

    // ------------------------------------------------------------- sending

    /**
     * A chunk is queued, then the operator deletes the campaign. The worker
     * picks the job up afterwards. Deleting it is the one action available to
     * stop a send already in flight, so it has to work.
     */
    public function test_a_campaign_deleted_after_its_chunk_was_queued_sends_nothing(): void
    {
        $campaign = $this->sendableCampaign();

        $campaign->delete();

        (new SendCampaignChunk($campaign->id))->handle(
            app(TenantManager::class),
            app(CampaignRunner::class)
        );

        $this->assertSame([], $this->factory->transport->sentTo,
            'A deleted campaign must not send.');

        $this->assertSame(0, CampaignRecipient::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)->where('status', 'sent')->count());
    }

    public function test_a_live_campaign_still_sends(): void
    {
        $campaign = $this->sendableCampaign();

        (new SendCampaignChunk($campaign->id))->handle(
            app(TenantManager::class),
            app(CampaignRunner::class)
        );

        $this->assertNotEmpty($this->factory->transport->sentTo,
            'The guard must stop deleted campaigns only.');
    }

    // ---------------------------------------------------------- mailboxes

    /**
     * A deleted mailbox polled every minute spends the provider's rate limit
     * downloading mail into an inbox nobody can open.
     */
    public function test_a_deleted_mailbox_is_not_queued_for_syncing(): void
    {
        $mailbox = $this->mailbox();

        Queue::fake();

        $this->artisan('mailboxes:sync', ['--force' => true])->assertExitCode(0);
        Queue::assertPushed(\App\Jobs\Imap\SyncMailboxJob::class);

        Queue::fake();
        $mailbox->delete();

        $this->artisan('mailboxes:sync', ['--force' => true])->assertExitCode(0);
        Queue::assertNothingPushed();
    }

    public function test_a_sync_job_for_a_deleted_mailbox_does_nothing(): void
    {
        $mailbox = $this->mailbox();
        $before = $mailbox->last_sync_at;

        $mailbox->delete();

        (new \App\Jobs\Imap\SyncMailboxJob($mailbox->id))->handle(
            app(TenantManager::class),
            app(\App\Services\Imap\MailboxSyncer::class),
            app(\App\Services\Imap\ImapFailureClassifier::class),
        );

        $this->assertSame(
            $before?->toDateTimeString(),
            Mailbox::withTrashed()->find($mailbox->id)->last_sync_at?->toDateTimeString(),
            'A deleted mailbox must not be contacted at all.'
        );
    }

    // ---------------------------------------------------------------- team

    /**
     * Removing somebody from the team has to remove them from the team screen
     * — and give their seat back. `PlanLimits` has always counted seats with
     * `whereNull('deleted_at')`, so the two disagreed.
     */
    public function test_a_removed_member_leaves_the_team_and_frees_their_seat(): void
    {
        $member = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'name' => 'Departed Colleague',
            'email' => 'departed@hardening.test',
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        $this->get('/team')->assertOk()->assertSee('Departed Colleague');

        $member->delete();

        $this->get('/team')->assertOk()->assertDontSee('Departed Colleague');

        // The plan allows three; owner plus the departed colleague is two, so
        // with the seat given back there must be room to invite somebody.
        $this->assertSame(1, \App\Support\PlanLimits::for($this->owner->account)
            ->usageFor('max_team_members'));

        $this->get('/team/create')->assertOk();
    }

    // -------------------------------------------------------------- import

    /**
     * Re-importing a contact who was deleted brings them back.
     *
     * Before this, "update" reported them updated while they stayed invisible,
     * and "skip" counted them as duplicates — so a deleted contact could never
     * be re-imported at all. The operator re-uploads their list and those
     * people simply never return, with nothing said about it.
     */
    public function test_re_importing_a_deleted_contact_restores_them(): void
    {
        $contact = Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'returning@example.com', 'first_name' => 'Old', 'status' => 'active']);

        $contact->delete();
        $this->assertSoftDeleted('subscribers', ['id' => $contact->id]);

        $import = $this->runImport("Email,Name\nreturning@example.com,Returned\n");

        $this->assertSame(1, (int) $import->imported_count,
            'A restored contact counts as imported, not as a duplicate.');
        $this->assertSame(0, (int) $import->duplicate_count);

        $fresh = Subscriber::query()->find($contact->id);

        $this->assertNotNull($fresh, 'The contact must be visible again.');
        $this->assertSame($contact->id, $fresh->id, 'and must be the same row, not a second one.');
    }

    public function test_a_live_contact_re_imported_is_still_a_duplicate(): void
    {
        Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'present@example.com', 'status' => 'active']);

        $import = $this->runImport("Email,Name\npresent@example.com,Present\n");

        $this->assertSame(1, (int) $import->duplicate_count,
            'Restoring must not change what happens to a contact who was never deleted.');
        $this->assertSame(0, (int) $import->imported_count);
    }

    // ----------------------------------------------------------- factories

    protected function sendableCampaign(): Campaign
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();

        Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'reader@example.com', 'status' => 'active'])
            ->lists()->attach($list->id, ['subscribed_at' => now()]);

        $doc = ['settings' => [], 'blocks' => [
            ['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hi']],
            ['id' => 'f', 'type' => 'footer', 'settings' => ['companyLine' => 'Hardened Ltd']],
        ]];

        $compiler = app(EmailCompiler::class);

        $campaign = Campaign::factory()->forAccount($this->owner->account)->create([
            'name' => 'Going out', 'subject' => 'Hello',
            'from_name' => 'Hardened Ltd', 'from_email' => 'hello@hardening.test',
            'status' => 'sending', 'blocks' => $doc,
            'html' => $compiler->compile($doc), 'plain_text' => $compiler->compileText($doc),
            'audience' => ['lists' => [$list->id]],
            'started_at' => now(),
        ]);

        app(RecipientGenerator::class)->generate($campaign);

        return $campaign->refresh();
    }

    protected function mailbox(): Mailbox
    {
        return Mailbox::create([
            'account_id' => $this->owner->account_id,
            'user_id' => $this->owner->id,
            'name' => 'Support',
            'email' => 'support@hardening.test',
            'imap_host' => 'imap.hardening.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@hardening.test',
            'imap_password' => 'secret',
            'is_active' => true,
            'sync_enabled' => true,
            'status' => 'connected',
        ]);
    }

    protected function runImport(string $csv): Import
    {
        $this->post('/imports', ['file' => UploadedFile::fake()->createWithContent('c.csv', $csv)])
            ->assertRedirect();

        $import = Import::withoutGlobalScopes()->latest('id')->firstOrFail();

        $import->forceFill([
            'mapping' => ['Email' => 'email', 'Name' => 'name'],
            'options' => array_merge($import->options ?? [], [
                'duplicates' => 'skip', 'status' => 'active',
                'consent_status' => 'explicit', 'source' => 'import',
            ]),
            'status' => 'pending',
        ])->save();

        (new ProcessSubscriberImport($import->id))->handle(
            app(TenantManager::class),
            app(\App\Services\Contacts\SuppressionService::class),
            app(\App\Services\Contacts\ListService::class),
        );

        return $import->fresh();
    }
}
