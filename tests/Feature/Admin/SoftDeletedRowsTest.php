<?php

namespace Tests\Feature\Admin;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\Scopes\AccountScope;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AccountProvisioner;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleted rows, and the one-character mistake that keeps letting them back in.
 *
 * `withoutGlobalScopes()` lifts every global scope, and SoftDeletingScope is
 * one of them — so the plural form silently means "and include the deleted
 * ones". The singular `withoutGlobalScope(AccountScope::class)` is what was
 * meant everywhere it appeared. It has produced a deleted plan charging against
 * a limit, a deleted campaign that went on sending, a deleted mailbox that went
 * on syncing, and now these.
 *
 * Each of these tests fails with the plural form and passes with the singular,
 * which is the only way to keep the distinction from eroding again.
 */
class SoftDeletedRowsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->admin = User::factory()->superAdmin()->create([
            'role_id' => Role::withoutGlobalScope(AccountScope::class)->where('slug', Role::SUPER_ADMIN)->value('id'),
            'email_verified_at' => now(),
            'name' => 'Platform Admin',
        ]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Customer Ltd', 'name' => 'Deletable Person',
            'email' => 'person@deleted.test', 'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->admin);
    }

    // ------------------------------------------------------------- the lists

    /**
     * Deleting through Admin → Users says "their history is retained", so the
     * person reasonably expects them gone from the list. They stayed, looking
     * active, with working buttons beside them.
     */
    public function test_a_deleted_user_leaves_the_admin_list(): void
    {
        $this->get('/admin/users')->assertOk()->assertSee('Deletable Person');

        $this->owner->delete();

        $this->get('/admin/users')->assertOk()->assertDontSee('Deletable Person');
    }

    public function test_the_dashboard_does_not_count_deleted_people_or_their_work(): void
    {
        Subscriber::factory()->forAccount($this->owner->account)->count(3)->create();
        Campaign::factory()->forAccount($this->owner->account)->count(2)->create();

        $before = $this->stats();

        Subscriber::withoutGlobalScope(AccountScope::class)->limit(2)->get()->each->delete();
        Campaign::withoutGlobalScope(AccountScope::class)->limit(1)->get()->each->delete();
        $this->owner->delete();

        $after = $this->stats();

        $this->assertSame($before['subscribers_total'] - 2, $after['subscribers_total']);
        $this->assertSame($before['campaigns_total'] - 1, $after['campaigns_total']);
        $this->assertSame($before['users_total'] - 1, $after['users_total']);
    }

    /**
     * The accounts card never lifted any scope, so it always excluded deleted
     * rows. Everything beside it included them — two answers to "how many" on
     * one screen.
     */
    public function test_the_cards_agree_with_each_other_about_what_deleted_means(): void
    {
        $this->owner->account->delete();

        $stats = $this->stats();

        $this->assertSame(0, $stats['accounts_total']);
        $this->assertSame(0, $stats['subscribers_total']);
        $this->assertSame(0, $stats['campaigns_total']);
    }

    /** @return array<string, mixed> */
    protected function stats(): array
    {
        return $this->get('/admin')->assertOk()->viewData('stats');
    }

    // ------------------------------------------------------- recovering admin

    /**
     * Re-seeding is the documented way to recover a lost super admin. With the
     * plural form the deleted row counted as existing, so the seeder reported
     * success and left the platform with no usable login — and creating a
     * replacement is impossible, because users.email is uniquely indexed and
     * the deleted row still holds the address.
     */
    public function test_reseeding_brings_back_a_deleted_super_admin(): void
    {
        // The seeder only ever manages the configured address, so that is the
        // admin this is about — not the factory one the rest of the class uses.
        $email = (string) config('knsoftic.admin.email');

        $this->seed(SuperAdminSeeder::class);

        $seeded = User::withoutGlobalScope(AccountScope::class)->where('email', $email)->firstOrFail();
        $seeded->delete();

        $this->assertNull(User::withoutGlobalScope(AccountScope::class)->where('email', $email)->first());

        $this->seed(SuperAdminSeeder::class);

        $restored = User::withoutGlobalScope(AccountScope::class)->where('email', $email)->first();

        $this->assertNotNull($restored, 'Re-seeding left no usable super admin.');
        $this->assertTrue((bool) $restored->is_super_admin);
        $this->assertSame('active', $restored->status);
        $this->assertSame($seeded->id, $restored->id, 'The original row should be restored, not duplicated.');
    }

    /** The password must survive it, or a deploy would reset a chosen one. */
    public function test_restoring_does_not_change_the_password(): void
    {
        $email = (string) config('knsoftic.admin.email');

        $this->seed(SuperAdminSeeder::class);

        $seeded = User::withoutGlobalScope(AccountScope::class)->where('email', $email)->firstOrFail();
        $hash = $seeded->password;
        $seeded->delete();

        $this->seed(SuperAdminSeeder::class);

        $this->assertSame($hash, User::withoutGlobalScope(AccountScope::class)
            ->where('email', $email)->value('password'));
    }

    public function test_reseeding_does_not_duplicate_a_live_super_admin(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $before = User::withoutGlobalScope(AccountScope::class)->where('is_super_admin', true)->count();

        $this->seed(SuperAdminSeeder::class);

        $this->assertSame($before, User::withoutGlobalScope(AccountScope::class)
            ->where('is_super_admin', true)->count());
    }

    // ----------------------------------------------------------- the command

    public function test_the_password_command_ignores_a_deleted_admin(): void
    {
        $this->admin->delete();

        $this->artisan('kn:admin-password')
            ->expectsOutputToContain('no super admin')
            ->assertFailed();
    }

    // ------------------------------------------------------- the role select

    /**
     * old() hands the value back as a string while $role->id is an int, so a
     * strict comparison matched nothing after a validation error: every option
     * came back unselected, the browser showed the first one, and resubmitting
     * quietly reassigned the user's role. A privilege change nobody asked for
     * and nothing announced.
     */
    public function test_the_role_select_keeps_its_selection_after_a_validation_error(): void
    {
        $staffRoleId = Role::withoutGlobalScope(AccountScope::class)->where('slug', Role::STAFF)->value('id');

        $staff = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => $staffRoleId,
            'email_verified_at' => now(),
        ]);

        // Posted as a string, because that is what a browser sends and it is
        // the whole point: the int the model holds and the string the session
        // gives back are not === to each other. A test that posts an int here
        // passes against the broken comparison and proves nothing.
        $response = $this->from("/admin/users/{$staff->id}/edit")
            ->put("/admin/users/{$staff->id}", [
                'name' => $staff->name,
                'email' => $this->owner->email,
                'role_id' => (string) $staffRoleId,
                'status' => 'active',
            ])->assertSessionHasErrors('email');

        $html = $this->followRedirects($response)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="'.$staffRoleId.'"[^>]*\bselected\b/',
            $html,
            'The role the user actually holds came back unselected.'
        );

        $this->assertSame($staffRoleId, $staff->fresh()->role_id);
    }
}
