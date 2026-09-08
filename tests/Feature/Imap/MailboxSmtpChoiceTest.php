<?php

namespace Tests\Feature\Imap;

use App\Models\Plan;
use App\Models\Scopes\AccountScope;
use App\Models\SmtpAccount;
use App\Models\SmtpAssignment;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which SMTP account a mailbox may send its replies through.
 *
 * Replies are outbound mail like a campaign, so they have to obey the same
 * rule: your own accounts if your plan allows them, and a platform account
 * only where an assignment reaches you. The mailbox form used to accept any
 * row with is_global = true, which let a tenant point its replies at a relay
 * nobody had shared with it — spending that relay's quota and its sending
 * reputation — and listed every relay on the installation by name, host and
 * from address while doing it.
 */
class MailboxSmtpChoiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Repliers Ltd', 'name' => 'Owner', 'email' => 'owner@replies.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        // Starter ships no mailbox seats on the free tier's neighbour, so the
        // seats and the feature both have to be granted for the form to open.
        $this->allow(['allow_imap' => true, 'max_mailboxes' => 3, 'allow_custom_smtp' => true, 'max_smtp_accounts' => 3]);

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);
    }

    protected function allow(array $overrides): void
    {
        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], $overrides)]);
        $this->owner->account->refresh();
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Support',
            'email' => 'support@repliers.test',
            'provider' => 'custom',
            'imap_host' => 'imap.repliers.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@repliers.test',
            'imap_password' => 'secret',
            'sync_enabled' => 1,
            'sync_interval_minutes' => 15,
            'sync_limit' => 50,
            'is_active' => 1,
        ], $overrides);
    }

    // ------------------------------------------------------------ the listing

    public function test_an_unassigned_platform_account_is_not_offered(): void
    {
        SmtpAccount::factory()->global()->create(['name' => 'Someone elses relay']);

        $this->get('/mailboxes/create')
            ->assertOk()
            ->assertDontSee('Someone elses relay');
    }

    public function test_an_assigned_platform_account_is_offered(): void
    {
        $shared = SmtpAccount::factory()->global()->create(['name' => 'Platform relay']);
        SmtpAssignment::create(['smtp_account_id' => $shared->id, 'scope' => 'all']);

        $this->get('/mailboxes/create')
            ->assertOk()
            ->assertSee('Platform relay');
    }

    public function test_a_plan_that_forbids_platform_smtp_is_offered_none(): void
    {
        $shared = SmtpAccount::factory()->global()->create(['name' => 'Platform relay']);
        SmtpAssignment::create(['smtp_account_id' => $shared->id, 'scope' => 'all']);

        $this->allow(['allow_admin_smtp' => false]);

        $this->get('/mailboxes/create')
            ->assertOk()
            ->assertDontSee('Platform relay');
    }

    public function test_another_tenants_own_account_is_never_offered(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@replies.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        SmtpAccount::factory()->forAccount($other->account)->create(['name' => 'Rival relay']);

        $this->get('/mailboxes/create')->assertOk()->assertDontSee('Rival relay');
    }

    // ------------------------------------------------------------ the posting

    /**
     * The listing is only the visible half. A crafted post is the half that
     * matters, because that is the one an attacker uses.
     */
    public function test_posting_an_unassigned_platform_account_is_refused(): void
    {
        $shared = SmtpAccount::factory()->global()->create(['name' => 'Someone elses relay']);

        $this->post('/mailboxes', $this->payload(['smtp_account_id' => $shared->id]))
            ->assertSessionHasErrors('smtp_account_id');

        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_posting_an_assigned_platform_account_is_accepted(): void
    {
        $shared = SmtpAccount::factory()->global()->create();
        SmtpAssignment::create(['smtp_account_id' => $shared->id, 'scope' => 'all']);

        $this->post('/mailboxes', $this->payload(['smtp_account_id' => $shared->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('mailboxes', [
            'account_id' => $this->owner->account_id,
            'smtp_account_id' => $shared->id,
        ]);
    }

    public function test_posting_another_tenants_own_account_is_refused(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@replies.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $theirs = SmtpAccount::factory()->forAccount($other->account)->create();

        $this->post('/mailboxes', $this->payload(['smtp_account_id' => $theirs->id]))
            ->assertSessionHasErrors('smtp_account_id');
    }

    /**
     * A relay assigned to the Business plan must not be reachable by an account
     * on another plan, however the id was obtained.
     */
    public function test_a_plan_scoped_relay_is_refused_to_another_plan(): void
    {
        $shared = SmtpAccount::factory()->global()->create();
        $business = Plan::withoutGlobalScope(AccountScope::class)->where('slug', 'business')->firstOrFail();

        SmtpAssignment::create([
            'smtp_account_id' => $shared->id, 'scope' => 'plan', 'plan_id' => $business->id,
        ]);

        $this->post('/mailboxes', $this->payload(['smtp_account_id' => $shared->id]))
            ->assertSessionHasErrors('smtp_account_id');

        $this->owner->account->subscription->update(['plan_id' => $business->id]);
        $this->owner->account->refresh();

        $this->post('/mailboxes', $this->payload(['smtp_account_id' => $shared->id]))
            ->assertSessionHasNoErrors();
    }

    public function test_the_field_stays_optional(): void
    {
        $this->post('/mailboxes', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('mailboxes', [
            'email' => 'support@repliers.test',
            'smtp_account_id' => null,
        ]);
    }

    public function test_an_own_account_is_refused_when_the_plan_forbids_own_smtp(): void
    {
        $mine = SmtpAccount::factory()->forAccount($this->owner->account)->create();

        $this->allow(['allow_custom_smtp' => false]);

        $this->post('/mailboxes', $this->payload(['smtp_account_id' => $mine->id]))
            ->assertSessionHasErrors('smtp_account_id');
    }
}
