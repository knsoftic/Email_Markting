<?php

namespace Tests\Feature\Activity;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\ActivityLogger;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The account's own audit trail.
 *
 * Every module already wrote here; until now only the super admin could read
 * any of it. The failures worth guarding are the two an audit trail cannot
 * survive: showing somebody another account's history, and showing it to
 * somebody in this account who should not have it.
 */
class ActivityScreenTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = $this->provision('Audited Ltd', 'owner@audit.test');

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);
    }

    protected function provision(string $company, string $email): User
    {
        $user = app(AccountProvisioner::class)->provision([
            'company_name' => $company, 'name' => 'Owner', 'email' => $email,
            'password' => 'Password123!', 'timezone' => 'Asia/Karachi',
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /**
     * Written straight to the table rather than through ActivityLogger, so the
     * row's account, actor and time are exactly what each test needs.
     */
    protected function entry(array $overrides = []): ActivityLog
    {
        return ActivityLog::withoutGlobalScopes()->create(array_merge([
            'account_id' => $this->owner->account_id,
            'user_id' => $this->owner->id,
            'event' => 'campaign.created',
            'description' => 'Created campaign Spring sale',
            'ip' => '203.0.113.4',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    // ---------------------------------------------------------- the basics

    public function test_the_screen_shows_what_this_account_did(): void
    {
        $this->entry();
        $this->entry(['event' => 'subscriber.deleted', 'description' => 'Deleted contact ali@example.com']);

        $this->get('/activity')
            ->assertOk()
            ->assertSee('Created campaign Spring sale')
            ->assertSee('Deleted contact ali@example.com')
            ->assertSee('Owner')
            ->assertSee('203.0.113.4');
    }

    public function test_it_renders_before_anything_has_happened(): void
    {
        ActivityLog::withoutGlobalScopes()->delete();

        $this->get('/activity')
            ->assertOk()
            ->assertSee('Nothing has been recorded yet');
    }

    /**
     * A scheduled job has no user. Inventing a name for it would be worse than
     * saying plainly that nobody was signed in.
     */
    public function test_an_entry_with_no_actor_says_so(): void
    {
        ActivityLog::withoutGlobalScopes()->delete();
        $this->entry(['user_id' => null, 'description' => 'Campaign finished sending']);

        $this->get('/activity')->assertOk()->assertSee('The system');
    }

    public function test_a_platform_action_is_attributed_to_the_vendor(): void
    {
        ActivityLog::withoutGlobalScopes()->delete();
        $this->entry([
            'user_id' => null,
            'event' => 'admin.subscription.assigned',
            'description' => 'Moved this account onto the Growth plan',
        ]);

        $this->get('/activity')->assertOk()->assertSee('KN Softic');
    }

    // ------------------------------------------------------------- filters

    public function test_the_area_chips_narrow_by_event_prefix(): void
    {
        $this->entry(['event' => 'campaign.created', 'description' => 'A campaign thing']);
        $this->entry(['event' => 'mailbox.created', 'description' => 'A mailbox thing']);

        $this->get('/activity?area=campaign')
            ->assertOk()
            ->assertSee('A campaign thing')
            ->assertDontSee('A mailbox thing');
    }

    public function test_the_person_filter_narrows_to_one_actor(): void
    {
        $colleague = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'name' => 'Sana Khan',
            'email' => 'sana@audit.test',
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        $this->entry(['description' => 'Something the owner did']);
        $this->entry(['user_id' => $colleague->id, 'description' => 'Something Sana did']);

        $this->get('/activity?user='.$colleague->id)
            ->assertOk()
            ->assertSee('Something Sana did')
            ->assertDontSee('Something the owner did');
    }

    public function test_the_date_range_narrows_and_is_read_in_the_accounts_timezone(): void
    {
        $this->entry(['description' => 'Happened today']);
        $this->entry(['description' => 'Happened long ago', 'created_at' => now()->subMonths(2)]);

        $this->get('/activity?from='.now()->subWeek()->format('Y-m-d'))
            ->assertOk()
            ->assertSee('Happened today')
            ->assertDontSee('Happened long ago');

        // The account is in Asia/Karachi, and the screen must say so rather
        // than quietly using UTC and dropping the first hours of a day.
        $this->get('/activity')->assertOk()->assertSee('Asia/Karachi');
    }

    public function test_a_backwards_date_range_is_swapped_rather_than_showing_nothing(): void
    {
        $this->entry(['description' => 'Happened today']);

        $this->get('/activity?from='.now()->addWeek()->format('Y-m-d')
            .'&to='.now()->subWeek()->format('Y-m-d'))
            ->assertOk()
            ->assertSee('Happened today');
    }

    public function test_search_matches_the_description_and_the_event(): void
    {
        $this->entry(['description' => 'Created campaign Spring sale']);
        $this->entry(['event' => 'tag.deleted', 'description' => 'Removed tag Winter']);

        $this->get('/activity?q=Spring')
            ->assertOk()
            ->assertSee('Spring sale')
            ->assertDontSee('Removed tag Winter');

        $this->get('/activity?q=tag.deleted')
            ->assertOk()
            ->assertSee('Removed tag Winter');
    }

    // ------------------------------------------------------ hostile input

    public function test_hostile_query_strings_do_not_crash_it(): void
    {
        $this->entry();

        foreach ([
            '/activity?q[]=x',
            '/activity?area[]=campaign',
            '/activity?area=nonsense',
            '/activity?user=abc',
            '/activity?user[]=1',
            '/activity?user=999999',
            '/activity?from=notadate',
            '/activity?from[]=2026-01-01',
            '/activity?page=999',
            '/activity?page=-1',
            '/activity?q='.urlencode('%'),
        ] as $url) {
            $this->get($url)->assertOk("Crashed on {$url}");
        }
    }

    public function test_a_description_containing_markup_is_escaped(): void
    {
        $this->entry(['description' => 'Created campaign <script>alert(1)</script>']);

        $this->get('/activity')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // -------------------------------------------------------- permissions

    /**
     * The audit trail says what every member of the account did, so it is
     * account administration rather than a module's own data.
     */
    public function test_a_role_without_settings_view_is_refused(): void
    {
        $role = Role::withoutGlobalScopes()->where('slug', Role::STAFF)->first();
        $role->permissions()->detach(Permission::where('slug', 'settings.view')->value('id'));

        $staff = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => $role->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/activity')->assertForbidden();
    }

    // ----------------------------------------------------------- tenancy

    /**
     * The worst thing this screen could do. An audit trail that leaks another
     * customer's history is worse than no audit trail at all.
     */
    public function test_another_accounts_history_is_never_shown(): void
    {
        $rival = $this->provision('Rivals Ltd', 'owner@rival.test');

        ActivityLog::withoutGlobalScopes()->create([
            'account_id' => $rival->account_id,
            'user_id' => $rival->id,
            'event' => 'campaign.created',
            'description' => 'Their secret campaign',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entry(['description' => 'Our own campaign']);

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/activity')
            ->assertOk()
            ->assertSee('Our own campaign')
            ->assertDontSee('Their secret campaign');

        // Nor by naming their user id in the filter.
        $this->get('/activity?user='.$rival->id)
            ->assertOk()
            ->assertDontSee('Their secret campaign');
    }

    public function test_the_person_filter_only_offers_this_accounts_people(): void
    {
        $rival = $this->provision('Rivals Ltd', 'owner@rival.test');
        $rival->forceFill(['name' => 'Rival Owner'])->save();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/activity')
            ->assertOk()
            ->assertDontSee('Rival Owner');
    }

    // -------------------------------------------------------------- shape

    public function test_the_screen_does_not_cost_a_query_per_row(): void
    {
        $colleague = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        $count = function (): int {
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            $this->get('/activity')->assertOk();

            return $n;
        };

        for ($i = 0; $i < 3; $i++) {
            $this->entry(['user_id' => $i % 2 ? $colleague->id : $this->owner->id]);
        }

        $small = $count();

        for ($i = 0; $i < 30; $i++) {
            $this->entry(['user_id' => $i % 2 ? $colleague->id : $this->owner->id]);
        }

        $large = $count();

        $this->assertLessThanOrEqual($small + 2, $large,
            "The screen cost {$small} queries for three entries and {$large} for thirty-three — "
            .'the actor must be eager-loaded, not fetched per row.');
    }

    /**
     * Logging must never break the action it records. The logger swallows its
     * own failures; this proves the screen agrees by rendering an entry whose
     * actor has since been deleted.
     */
    public function test_an_entry_whose_actor_was_deleted_still_renders(): void
    {
        $colleague = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'name' => 'Departed Colleague',
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
            'email_verified_at' => now(),
        ]);

        ActivityLog::withoutGlobalScopes()->delete();

        ActivityLogger::log('tag.created', 'Created tag VIP', ['user_id' => $colleague->id]);

        $colleague->delete();

        $this->get('/activity')->assertOk()->assertSee('Created tag VIP');
    }
}
