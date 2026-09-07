<?php

namespace Tests\Feature\Auth;

use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'company_name' => 'Test Company',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'timezone' => 'UTC',
            'terms' => '1',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_provisions_an_account_owner_and_plan(): void
    {
        $this->post('/register', [
            'company_name' => 'Provisioned Ltd',
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'timezone' => 'Asia/Karachi',
            'terms' => '1',
        ]);

        $user = User::withoutGlobalScopes()->firstWhere('email', 'owner@example.com');
        $account = Account::withoutGlobalScopes()->firstWhere('name', 'Provisioned Ltd');

        $this->assertNotNull($user);
        $this->assertNotNull($account);
        $this->assertSame($account->id, $user->account_id);
        $this->assertSame($user->id, $account->owner_id);
        $this->assertSame('Asia/Karachi', $account->timezone);
        $this->assertFalse($user->is_super_admin);

        $this->assertTrue(
            Subscription::withoutGlobalScopes()->where('account_id', $account->id)->exists(),
            'A new account must start on a plan.'
        );
    }

    public function test_terms_must_be_accepted(): void
    {
        $this->post('/register', [
            'company_name' => 'No Consent Ltd',
            'name' => 'Owner',
            'email' => 'noconsent@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'timezone' => 'UTC',
        ])->assertSessionHasErrors('terms');

        $this->assertGuest();
    }
}
