<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\SettingsService;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The customer's half of a manual billing process.
 *
 * This product takes no payments, and the screen must never suggest otherwise.
 * Most of what is asserted here is about honesty rather than mechanics: that a
 * button which only sends a message says so, that a private plan is not
 * advertised, and that a limit set for this account alone is not left looking
 * like a contradiction of the plan card beside it.
 */
class BillingScreenTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Payers Ltd', 'name' => 'Owner', 'email' => 'owner@payers.test',
            'password' => 'Password123!', 'timezone' => 'Asia/Karachi',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);
    }

    // ---------------------------------------------------------- the screen

    public function test_it_shows_the_plan_the_usage_and_the_other_plans(): void
    {
        Subscriber::factory()->forAccount($this->owner->account)->count(3)->create();

        $response = $this->get('/billing')->assertOk();

        $response->assertSee('Starter')
            ->assertSee('Business')
            ->assertSee('Pro')
            ->assertSee('Contacts')
            ->assertSee('What you are using');
    }

    /**
     * The whole screen is a shop that does not sell anything, and saying so is
     * the difference between an honest page and a broken one.
     */
    public function test_it_says_plainly_that_nothing_here_charges_or_changes_anything(): void
    {
        $this->get('/billing')
            ->assertOk()
            ->assertSee('there is no card checkout here')
            ->assertSee('never changes your plan');
    }

    /**
     * A plan the operator keeps off the public list is a private arrangement.
     */
    public function test_a_private_plan_is_not_advertised(): void
    {
        Plan::withoutGlobalScopes()->where('slug', 'pro')
            ->update(['is_public' => false]);

        $this->get('/billing')->assertOk()->assertDontSee('Ask about Pro');
    }

    public function test_an_inactive_plan_is_not_advertised(): void
    {
        Plan::withoutGlobalScopes()->where('slug', 'business')
            ->update(['is_active' => false]);

        $this->get('/billing')->assertOk()->assertDontSee('Ask about Business');
    }

    // ----------------------------------------------------- payment details

    public function test_it_shows_the_payment_details_the_operator_published(): void
    {
        app(SettingsService::class)->setMany('payment', [
            'bank_details' => 'Meezan Bank, IBAN PK00 TEST',
            'instructions' => 'Send the receipt to support.',
        ]);

        $this->get('/billing')
            ->assertOk()
            ->assertSee('Meezan Bank, IBAN PK00 TEST')
            ->assertSee('Send the receipt to support.');
    }

    /**
     * An empty box under the heading "Where to pay" would read as a fault in
     * the product. It has to say what to do instead.
     */
    public function test_when_no_payment_details_are_published_it_says_so(): void
    {
        app(SettingsService::class)->setMany('payment', [
            'bank_details' => '', 'instructions' => '',
        ]);
        app(SettingsService::class)->setMany('branding', [
            'support_email' => 'help@knsoftic.test',
        ]);

        $this->get('/billing')
            ->assertOk()
            ->assertSee('Payment details have not been published yet')
            ->assertSee('help@knsoftic.test')
            ->assertDontSee('Where to pay');
    }

    /**
     * The symbol lives in Admin → Payment settings and the currency code lives
     * on the plan, and nothing keeps them in step. A plan priced in USD was
     * being rendered with whatever symbol the operator had saved — "Rs 5.00"
     * on this screen while Admin → Plans listed the same row as "USD 5.00".
     */
    public function test_a_plan_in_another_currency_is_not_priced_with_the_wrong_symbol(): void
    {
        app(SettingsService::class)->setMany('payment', [
            'currency' => 'PKR', 'currency_symbol' => 'Rs ',
        ]);

        Plan::withoutGlobalScopes()->where('slug', 'starter')
            ->update(['currency' => 'USD', 'price' => 5]);

        $this->get('/billing')
            ->assertOk()
            ->assertSee('USD 5')
            ->assertDontSee('Rs 5');
    }

    public function test_the_symbol_is_used_when_the_currencies_agree(): void
    {
        app(SettingsService::class)->setMany('payment', [
            'currency' => 'PKR', 'currency_symbol' => 'Rs ',
        ]);

        Plan::withoutGlobalScopes()->where('slug', 'starter')
            ->update(['currency' => 'PKR', 'price' => 5]);

        $this->get('/billing')->assertOk()->assertSee('Rs 5');
    }

    // --------------------------------------------------- adjusted limits

    /**
     * An operator can grant one account more than its plan allows. Without
     * saying so, the usage bar reads "3 of 50,000" while the Starter card next
     * to it says 2,000, and the customer cannot tell which is wrong.
     */
    public function test_a_limit_set_for_this_account_is_marked_as_adjusted(): void
    {
        $this->get('/billing')->assertOk()->assertDontSee('adjusted');

        $this->owner->account->subscription
            ->forceFill(['overrides' => ['max_contacts' => 50000]])->save();

        $this->get('/billing')
            ->assertOk()
            ->assertSee('adjusted')
            ->assertSee('has been set')
            ->assertSee('50,000');
    }

    // --------------------------------------------------------- the request

    public function test_asking_about_a_plan_tells_the_operator_and_changes_nothing(): void
    {
        $admin = User::factory()->superAdmin()->create([
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::SUPER_ADMIN)->value('id'),
        ]);

        $pro = Plan::withoutGlobalScopes()->where('slug', 'pro')->firstOrFail();
        $before = $this->owner->account->subscription->plan_id;

        $this->post('/billing/request', ['plan_id' => $pro->id])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Nothing on your account has changed'));

        // The plan is untouched. That is the point.
        $this->assertSame($before, $this->owner->account->fresh()->subscription->plan_id);

        // The operator hears about it, and it is written down as well.
        $this->assertSame(1, DB::table('notifications')
            ->where('notifiable_id', $admin->id)->count());

        $this->assertDatabaseHas('activity_logs', [
            'account_id' => $this->owner->account_id,
            'event' => 'billing.upgrade_requested',
        ]);
    }

    public function test_a_free_form_question_is_sent_too(): void
    {
        User::factory()->superAdmin()->create([
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::SUPER_ADMIN)->value('id'),
        ]);

        $this->post('/billing/request', ['note' => 'We need 40,000 contacts from next month.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $log = ActivityLog::withoutGlobalScopes()
            ->where('event', 'billing.upgrade_requested')->firstOrFail();

        $this->assertStringContainsString('40,000', (string) ($log->properties['note'] ?? ''));
    }

    /**
     * A request naming a plan the customer was never shown would be a way to
     * ask for a private arrangement they should not know exists.
     */
    public function test_a_private_plan_cannot_be_requested(): void
    {
        $pro = Plan::withoutGlobalScopes()->where('slug', 'pro')->firstOrFail();
        $pro->forceFill(['is_public' => false])->save();

        $this->post('/billing/request', ['plan_id' => $pro->id])
            ->assertSessionHasErrors('plan_id');
    }

    public function test_the_request_survives_there_being_no_super_admin(): void
    {
        User::withoutGlobalScopes()->where('is_super_admin', true)->delete();

        $this->post('/billing/request', ['note' => 'Hello'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs', ['event' => 'billing.upgrade_requested']);
    }

    // ------------------------------------------------------ hostile input

    public function test_hostile_input_does_not_crash_it(): void
    {
        foreach ([
            ['plan_id' => 'abc'],
            ['plan_id' => ['1']],
            ['plan_id' => 999999],
            ['note' => str_repeat('a', 5000)],
            ['note' => ['x']],
        ] as $payload) {
            $response = $this->post('/billing/request', $payload);
            $this->assertNotSame(500, $response->status(), json_encode($payload));
        }

        $this->get('/billing?x[]=1')->assertOk();
    }

    // -------------------------------------------------------- permissions

    public function test_a_role_without_settings_view_cannot_see_it(): void
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

        $this->get('/billing')->assertForbidden();
        $this->post('/billing/request', ['note' => 'hi'])->assertForbidden();
    }
}
