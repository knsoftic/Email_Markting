<?php

namespace Tests\Feature\Hardening;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `kn:admin-password`.
 *
 * The super admin seeder falls back to the password `password`, and the
 * placeholder in `.env.example` is published in a public repository — so a
 * fresh install has a platform administrator whose password anybody can read.
 * The seeder also hashes directly, so the policy that governs every other
 * password never sees it.
 *
 * This command is the fix, and these tests hold it to two things: the new
 * password must satisfy the same policy as everybody else's, and it must never
 * be accepted as a command-line argument.
 */
class AdminPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);
    }

    protected function admin(string $email = 'admin@knsoftic.com'): User
    {
        return User::factory()->create([
            'account_id' => null,
            'email' => $email,
            'is_super_admin' => true,
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::SUPER_ADMIN)->value('id'),
            'password' => Hash::make('change-this-before-deploying'),
            'email_verified_at' => now(),
        ]);
    }

    public function test_it_sets_a_strong_password(): void
    {
        $admin = $this->admin();

        $this->artisan('kn:admin-password')
            ->expectsQuestion('New password', 'harbour-lantern-93')
            ->expectsQuestion('Type it again', 'harbour-lantern-93')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('harbour-lantern-93', $admin->refresh()->password));
    }

    /**
     * The whole point. The seeder's own default would be refused here.
     */
    public function test_it_refuses_a_password_the_policy_would_refuse(): void
    {
        $admin = $this->admin();

        foreach (['password', '12345678', 'change-this-before-deploying'] as $weak) {
            $this->artisan('kn:admin-password')
                ->expectsQuestion('New password', $weak)
                ->expectsQuestion('Type it again', $weak)
                ->assertExitCode(1);
        }

        $this->assertTrue(Hash::check('change-this-before-deploying', $admin->refresh()->password),
            'A refused password must leave the old one in place.');
    }

    public function test_a_mistyped_confirmation_changes_nothing(): void
    {
        $admin = $this->admin();

        $this->artisan('kn:admin-password')
            ->expectsQuestion('New password', 'harbour-lantern-93')
            ->expectsQuestion('Type it again', 'harbour-lantern-94')
            ->assertExitCode(1);

        $this->assertTrue(Hash::check('change-this-before-deploying', $admin->refresh()->password));
    }

    /**
     * A password given as an argument lands in `~/.bash_history`, is visible to
     * every other user in `ps`, and on many systems is shipped to a log
     * aggregator. There is deliberately no option for it, and this fails if
     * somebody adds one for convenience.
     */
    public function test_the_password_cannot_be_passed_on_the_command_line(): void
    {
        $definition = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
            ->all()['kn:admin-password']->getDefinition();

        foreach (array_keys($definition->getOptions()) as $option) {
            $this->assertStringNotContainsString('password', $option,
                "kn:admin-password must not accept --{$option}: it would put the password in the shell history.");
        }

        foreach (array_keys($definition->getArguments()) as $argument) {
            $this->assertStringNotContainsString('password', $argument,
                "kn:admin-password must not take {$argument} as an argument.");
        }
    }

    public function test_it_names_the_admins_when_there_is_more_than_one(): void
    {
        $this->admin('first@knsoftic.com');
        $this->admin('second@knsoftic.com');

        $this->artisan('kn:admin-password')
            ->expectsOutputToContain('more than one super admin')
            ->assertExitCode(1);

        $this->artisan('kn:admin-password', ['email' => 'second@knsoftic.com'])
            ->expectsQuestion('New password', 'harbour-lantern-93')
            ->expectsQuestion('Type it again', 'harbour-lantern-93')
            ->assertExitCode(0);
    }

    public function test_it_says_so_when_there_is_no_super_admin(): void
    {
        $this->artisan('kn:admin-password')
            ->expectsOutputToContain('no super admin')
            ->assertExitCode(1);
    }
}
