<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProvisioner;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAndPermissionsTest extends TestCase
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
            'company_name' => 'Team A', 'name' => 'Owner A', 'email' => 'owner-a@example.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->ownerB = $provisioner->provision([
            'company_name' => 'Team B', 'name' => 'Owner B', 'email' => 'owner-b@example.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        // Both accounts are on Starter, whose seat limit is 1 — raise it so
        // team management can actually be exercised.
        foreach ([$this->ownerA, $this->ownerB] as $owner) {
            $owner->forceFill(['email_verified_at' => now()])->save();
            $owner->account->subscription->update(['overrides' => ['max_team_members' => 10]]);
        }
    }

    public function test_owner_can_add_a_staff_member(): void
    {
        $staffRole = Role::withoutGlobalScopes()->where('slug', Role::STAFF)->first();

        $this->actingAs($this->ownerA)->post('/team', [
            'name' => 'Staff One',
            'email' => 'staff-one@example.test',
            'timezone' => 'UTC',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role_id' => $staffRole->id,
        ])->assertRedirect(route('team.index'));

        $staff = User::withoutGlobalScopes()->firstWhere('email', 'staff-one@example.test');

        $this->assertNotNull($staff);
        $this->assertSame($this->ownerA->account_id, $staff->account_id);
        $this->assertSame($staffRole->id, $staff->role_id);
    }

    public function test_seat_limit_from_the_plan_is_enforced(): void
    {
        $this->ownerA->account->subscription->update(['overrides' => ['max_team_members' => 1]]);

        $this->actingAs($this->ownerA)->get('/team/create')->assertForbidden();

        $this->actingAs($this->ownerA)->post('/team', [
            'name' => 'Over Limit',
            'email' => 'over@example.test',
            'timezone' => 'UTC',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
        ])->assertForbidden();

        $this->assertNull(User::withoutGlobalScopes()->firstWhere('email', 'over@example.test'));
    }

    public function test_staff_permissions_gate_routes(): void
    {
        $staffRole = Role::withoutGlobalScopes()->where('slug', Role::STAFF)->first();

        $staff = User::factory()->forAccount($this->ownerA->account)->create([
            'role_id' => $staffRole->id,
        ]);

        // Staff ships without team.manage, so the team screens are closed.
        $this->assertFalse($staff->hasPermission('team.manage'));
        $this->actingAs($staff)->get('/team')->assertForbidden();

        // The owner grants it, and the same user gets in.
        $staffRole->permissions()->syncWithoutDetaching(
            Permission::where('slug', 'team.manage')->pluck('id')
        );

        $this->actingAs($staff->fresh())->get('/team')->assertOk();
    }

    public function test_owner_holds_every_permission_without_a_pivot_row(): void
    {
        $this->assertTrue($this->ownerA->hasPermission('team.manage'));
        $this->assertTrue($this->ownerA->hasPermission('campaigns.send'));
        $this->assertTrue($this->ownerA->hasPermission('anything.at.all'));
    }

    public function test_an_owner_cannot_touch_another_accounts_member(): void
    {
        $foreign = User::factory()->forAccount($this->ownerB->account)->create();

        $this->actingAs($this->ownerA)->get("/team/{$foreign->id}/edit")->assertNotFound();
        $this->actingAs($this->ownerA)->delete("/team/{$foreign->id}")->assertNotFound();

        $this->assertNotNull(User::withoutGlobalScopes()->find($foreign->id));
    }

    public function test_the_account_owner_cannot_be_removed_or_suspended(): void
    {
        $this->actingAs($this->ownerA)
            ->patch("/team/{$this->ownerA->id}/status")
            ->assertSessionHas('error');

        $this->assertSame('active', $this->ownerA->fresh()->status);
    }

    public function test_account_roles_are_private_to_their_account(): void
    {
        $this->actingAs($this->ownerA)->post('/team/roles', [
            'name' => 'Campaign Manager',
            'description' => 'Runs campaigns only',
            'permissions' => Permission::whereIn('slug', ['campaigns.view', 'campaigns.send'])->pluck('id')->all(),
        ])->assertRedirect(route('team.index'));

        $role = Role::withoutGlobalScopes()->firstWhere('name', 'Campaign Manager');

        $this->assertSame($this->ownerA->account_id, $role->account_id);
        $this->assertSame(2, $role->permissions()->count());

        // Account B must not be able to open or edit it.
        $this->actingAs($this->ownerB)->get("/team/roles/{$role->id}/edit")->assertNotFound();
        $this->actingAs($this->ownerB)->delete("/team/roles/{$role->id}")->assertNotFound();
    }

    public function test_super_admins_are_redirected_away_from_account_modules(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get('/team')->assertRedirect(route('admin.dashboard'));
    }
}
