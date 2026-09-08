<?php

namespace Tests\Feature\Contacts;

use App\Models\CustomField;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\PlanLimits;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end checks on the contacts screens: every route renders, the forms
 * write what they promise, and permission gates actually bite.
 */
class ContactScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Screens Ltd', 'name' => 'Owner', 'email' => 'owner@screens.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        // Segments and custom fields are plan-gated; Business unlocks both.
        $this->owner->account->subscription->update([
            'overrides' => ['allow_segments' => true, 'allow_custom_fields' => true],
        ]);

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);
    }

    // ------------------------------------------------------------ rendering

    public function test_every_contacts_screen_renders(): void
    {
        $subscriber = Subscriber::factory()->forAccount($this->owner->account)->create();
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();
        $tag = Tag::factory()->forAccount($this->owner->account)->create();

        $screens = [
            '/subscribers',
            '/subscribers/create',
            "/subscribers/{$subscriber->id}",
            "/subscribers/{$subscriber->id}/edit",
            '/custom-fields',
            '/lists',
            '/lists/create',
            "/lists/{$list->id}",
            "/lists/{$list->id}/edit",
            '/tags',
            "/tags/{$tag->id}",
            '/suppressions',
        ];

        foreach ($screens as $screen) {
            $this->get($screen)->assertOk("Failed rendering {$screen}");
        }
    }

    public function test_the_contacts_index_shows_status_counts_and_plan_usage(): void
    {
        Subscriber::factory()->forAccount($this->owner->account)->count(3)->create();
        Subscriber::factory()->forAccount($this->owner->account)->unsubscribed()->create();

        // Read from the plan rather than hardcoding a figure: the usage line is
        // what is under test, not what the default plan happens to grant this
        // year. A literal here made this fail the day the ladder gained a free
        // tier, which told us nothing about the screen.
        $limit = PlanLimits::for($this->owner->account)->limit('max_contacts');

        $this->assertNotNull($limit, 'The default plan is expected to cap contacts.');

        $this->get('/subscribers')
            ->assertOk()
            ->assertSee('Unsubscribed')
            ->assertSee(number_format($limit));
    }

    // --------------------------------------------------------------- forms

    public function test_creating_a_contact_through_the_form(): void
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();
        $tag = Tag::factory()->forAccount($this->owner->account)->create();

        $this->post('/subscribers', [
            'email' => 'Form.Contact@Example.com',
            'name' => 'Form Contact',
            'status' => 'active',
            'consent_status' => 'explicit',
            'country' => 'Pakistan',
            'list_ids' => [$list->id],
            'tag_ids' => [$tag->id],
        ])->assertRedirect();

        $subscriber = Subscriber::withoutGlobalScopes()->firstWhere('email', 'form.contact@example.com');

        $this->assertNotNull($subscriber);
        $this->assertSame(1, $subscriber->lists()->count());
        $this->assertSame(1, $subscriber->tags()->count());
        $this->assertSame(1, $list->fresh()->total_count);
    }

    public function test_a_duplicate_email_is_rejected_per_account_not_globally(): void
    {
        Subscriber::factory()->forAccount($this->owner->account)->create(['email' => 'taken@example.com']);

        $this->post('/subscribers', [
            'email' => 'taken@example.com',
            'status' => 'active',
            'consent_status' => 'unknown',
        ])->assertSessionHasErrors('email');

        // Another account may hold the same address.
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Other Ltd', 'name' => 'Other', 'email' => 'other@screens.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $other->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($other);
        app(TenantManager::class)->set($other->account_id);

        $this->post('/subscribers', [
            'email' => 'taken@example.com',
            'status' => 'active',
            'consent_status' => 'unknown',
        ])->assertSessionHasNoErrors();
    }

    public function test_custom_field_values_round_trip_through_the_form(): void
    {
        CustomField::factory()->forAccount($this->owner->account)->create([
            'name' => 'Tier', 'key' => 'tier', 'type' => 'text',
        ]);

        $this->post('/subscribers', [
            'email' => 'custom@example.com',
            'status' => 'active',
            'consent_status' => 'unknown',
            'custom' => ['tier' => 'gold', 'not_a_field' => 'ignored'],
        ])->assertRedirect();

        $subscriber = Subscriber::withoutGlobalScopes()->firstWhere('email', 'custom@example.com');

        $this->assertSame('gold', $subscriber->customValue('tier'));
        $this->assertNull(
            $subscriber->customValue('not_a_field'),
            'Only account-defined keys may be written into the custom column.'
        );
    }

    public function test_renaming_a_custom_field_key_moves_the_stored_values(): void
    {
        $field = CustomField::factory()->forAccount($this->owner->account)->create([
            'name' => 'Tier', 'key' => 'tier', 'type' => 'text',
        ]);

        $subscriber = Subscriber::factory()->forAccount($this->owner->account)
            ->withCustom(['tier' => 'gold'])->create();

        $this->put("/custom-fields/{$field->id}", [
            'name' => 'Membership tier',
            'key' => 'membership_tier',
            'type' => 'text',
        ])->assertRedirect();

        $subscriber->refresh();

        $this->assertSame('gold', $subscriber->customValue('membership_tier'));
        $this->assertNull($subscriber->customValue('tier'), 'The old key must not linger.');
    }

    public function test_deleting_a_custom_field_removes_its_stored_values(): void
    {
        $field = CustomField::factory()->forAccount($this->owner->account)->create([
            'name' => 'Tier', 'key' => 'tier', 'type' => 'text',
        ]);

        $subscriber = Subscriber::factory()->forAccount($this->owner->account)
            ->withCustom(['tier' => 'gold', 'keep' => 'me'])->create();

        $this->delete("/custom-fields/{$field->id}")->assertRedirect();

        $subscriber->refresh();

        $this->assertNull($subscriber->customValue('tier'));
        $this->assertSame('me', $subscriber->customValue('keep'), 'Other keys must survive.');
    }

    public function test_the_suppression_form_stores_the_note_it_collects(): void
    {
        $this->post('/suppressions', [
            'emails' => "one@example.com, two@example.com\nnot-an-email",
            'reason' => 'spam_complaint',
            'notes' => 'Reported via the provider feedback loop',
        ])->assertRedirect();

        $suppression = Suppression::withoutGlobalScopes()->firstWhere('email', 'one@example.com');

        $this->assertNotNull($suppression);
        $this->assertSame('spam_complaint', $suppression->reason);
        $this->assertSame(
            'Reported via the provider feedback loop',
            $suppression->notes,
            'A note the form collects must actually be stored.'
        );

        $this->assertSame(2, Suppression::withoutGlobalScopes()->count(), 'The invalid line is skipped.');
    }

    public function test_bulk_actions_run_from_the_contacts_screen(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create();
        $subscribers = Subscriber::factory()->forAccount($this->owner->account)->count(3)->create();

        $this->post('/subscribers/bulk', [
            'action' => 'add_tags',
            'ids' => $subscribers->pluck('id')->all(),
            'tag_ids' => [$tag->id],
        ])->assertRedirect();

        $this->assertSame(3, $tag->fresh()->subscribers_count);
    }

    public function test_a_bulk_action_without_its_required_option_is_refused(): void
    {
        $subscriber = Subscriber::factory()->forAccount($this->owner->account)->create();

        $this->post('/subscribers/bulk', [
            'action' => 'add_tags',
            'ids' => [$subscriber->id],
        ])->assertSessionHas('error');
    }

    // --------------------------------------------------------- permissions

    public function test_a_staff_member_without_contacts_permission_is_blocked(): void
    {
        $staff = User::factory()->forAccount($this->owner->account)->create([
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
        ]);

        // Staff ships with contacts.view but not create/update/delete.
        $this->actingAs($staff);

        $this->get('/subscribers')->assertOk();
        $this->get('/subscribers/create')->assertForbidden();
        $this->post('/subscribers', [
            'email' => 'blocked@example.com', 'status' => 'active', 'consent_status' => 'unknown',
        ])->assertForbidden();
    }

    public function test_granting_the_permission_opens_the_screen(): void
    {
        $staffRole = Role::withoutGlobalScopes()->where('slug', Role::STAFF)->first();
        $staff = User::factory()->forAccount($this->owner->account)->create(['role_id' => $staffRole->id]);

        $staffRole->permissions()->syncWithoutDetaching(
            Permission::whereIn('slug', ['contacts.create'])->pluck('id')
        );

        $this->actingAs($staff->fresh())->get('/subscribers/create')->assertOk();
    }

    public function test_a_contact_from_another_account_is_not_reachable(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@screens.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = Subscriber::factory()->forAccount($other->account)->create();
        $foreignList = SubscriberList::factory()->forAccount($other->account)->create();
        $foreignTag = Tag::factory()->forAccount($other->account)->create();

        $this->get("/subscribers/{$foreign->id}")->assertNotFound();
        $this->get("/subscribers/{$foreign->id}/edit")->assertNotFound();
        $this->delete("/subscribers/{$foreign->id}")->assertNotFound();
        $this->get("/lists/{$foreignList->id}")->assertNotFound();
        $this->get("/tags/{$foreignTag->id}")->assertNotFound();
    }

    public function test_a_super_admin_is_redirected_away_from_contacts(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get('/subscribers')->assertRedirect(route('admin.dashboard'));
    }

    // --------------------------------------------------------- plan limits

    public function test_custom_fields_are_refused_when_the_plan_excludes_them(): void
    {
        $this->owner->account->subscription->update(['overrides' => ['allow_custom_fields' => false]]);

        $this->get('/custom-fields')->assertOk()->assertSee('plan', false);

        $this->post('/custom-fields', [
            'name' => 'Blocked', 'key' => 'blocked', 'type' => 'text',
        ])->assertSessionHas('error');

        $this->assertSame(0, CustomField::withoutGlobalScopes()->count());
    }

    public function test_the_list_limit_is_enforced(): void
    {
        $this->owner->account->subscription->update(['overrides' => ['max_lists' => 1]]);

        $this->post('/lists', ['name' => 'First'])->assertRedirect();
        $this->post('/lists', ['name' => 'Second'])->assertSessionHas('error');

        $this->assertSame(1, SubscriberList::withoutGlobalScopes()->count());
    }
}
