<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The single most important guarantee in the platform: account A can never
 * read, count or address account B's rows.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerA;

    protected User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $provisioner = app(AccountProvisioner::class);

        $this->ownerA = $provisioner->provision([
            'company_name' => 'Account A',
            'name' => 'Owner A',
            'email' => 'a@example.test',
            'password' => 'Password123!',
            'timezone' => 'UTC',
        ]);

        $this->ownerB = $provisioner->provision([
            'company_name' => 'Account B',
            'name' => 'Owner B',
            'email' => 'b@example.test',
            'password' => 'Password123!',
            'timezone' => 'UTC',
        ]);

        $this->ownerA->forceFill(['email_verified_at' => now()])->save();
        $this->ownerB->forceFill(['email_verified_at' => now()])->save();

        $tenant = app(TenantManager::class);

        $tenant->runAs($this->ownerA->account_id, function () {
            Subscriber::create(['email' => 'lead-a@example.test', 'name' => 'Lead A']);
            Campaign::create([
                'name' => 'Campaign A', 'subject' => 'Hello A',
                'from_name' => 'A', 'from_email' => 'a@example.test',
            ]);
        });

        $tenant->runAs($this->ownerB->account_id, function () {
            Subscriber::create(['email' => 'lead-b@example.test', 'name' => 'Lead B']);
        });

        $tenant->forget();
    }

    public function test_queries_are_scoped_to_the_active_account(): void
    {
        $tenant = app(TenantManager::class);

        $tenant->runAs($this->ownerA->account_id, function () {
            $this->assertSame(1, Subscriber::count());
            $this->assertSame('lead-a@example.test', Subscriber::first()->email);
            $this->assertNull(Subscriber::where('email', 'lead-b@example.test')->first());
        });

        $tenant->runAs($this->ownerB->account_id, function () {
            $this->assertSame(1, Subscriber::count());
            $this->assertSame('lead-b@example.test', Subscriber::first()->email);
        });
    }

    public function test_creating_a_record_stamps_the_active_account(): void
    {
        app(TenantManager::class)->runAs($this->ownerB->account_id, function () {
            $subscriber = Subscriber::create(['email' => 'stamped@example.test']);

            $this->assertSame($this->ownerB->account_id, $subscriber->account_id);
        });
    }

    public function test_a_user_cannot_open_another_accounts_record(): void
    {
        $campaignA = Campaign::withoutGlobalScopes()->firstWhere('name', 'Campaign A');

        // Owner B is authenticated, so SetTenant binds account B; account A's
        // campaign must be invisible to every query B can make.
        $this->actingAs($this->ownerB);
        app(TenantManager::class)->set($this->ownerB->account_id);

        $this->assertNull(Campaign::find($campaignA->id));
        $this->assertSame(0, Campaign::count());
    }

    public function test_dashboard_only_reports_the_users_own_totals(): void
    {
        $this->actingAs($this->ownerA)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Account A')
            ->assertDontSee('Account B');
    }

    public function test_guests_cannot_reach_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
