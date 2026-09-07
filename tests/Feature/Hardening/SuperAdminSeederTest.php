<?php

namespace Tests\Feature\Hardening;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The first super admin.
 *
 * This account can see every tenant on the platform, so how its password comes
 * to exist is worth holding still. Two of these tests exist because of defects
 * found on a live server, not because of anything imagined.
 */
class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    /**
     * The defect that put a known password on a live server.
     *
     * The seeder used to call `env('KNS_ADMIN_PASSWORD', 'password')`. Outside
     * a config file `env()` returns its DEFAULT once `config:cache` has run,
     * because Laravel then stops reading `.env` at all — so on every deployed
     * install the seeder ignored the configured password and hashed the
     * fallback, while `.env` appeared to say otherwise.
     *
     * Reading through `config()` is what fixes it, and this asserts the value
     * is taken from there rather than from the environment directly.
     */
    public function test_the_password_comes_from_config_not_from_env_directly(): void
    {
        config(['knsoftic.admin.password' => 'harbour-lantern-93']);

        // Deliberately different: if the seeder still read the environment, it
        // would pick this up and the assertion below would fail.
        putenv('KNS_ADMIN_PASSWORD=something-else-entirely');

        $this->seed(SuperAdminSeeder::class);

        $admin = User::withoutGlobalScopes()->where('is_super_admin', true)->sole();

        $this->assertTrue(Hash::check('harbour-lantern-93', $admin->password));
        $this->assertFalse(Hash::check('something-else-entirely', $admin->password));

        putenv('KNS_ADMIN_PASSWORD');
    }

    /**
     * There is no fallback password any more. An install with nothing
     * configured gets a generated one rather than a published one.
     */
    public function test_an_unset_password_is_generated_rather_than_defaulted(): void
    {
        config(['knsoftic.admin.password' => null]);

        $this->seed(SuperAdminSeeder::class);

        $admin = User::withoutGlobalScopes()->where('is_super_admin', true)->sole();

        foreach (['password', 'change-this-before-deploying', '', 'admin'] as $guess) {
            $this->assertFalse(Hash::check($guess, $admin->password),
                "A fresh install must not come up with the password \"{$guess}\".");
        }
    }

    /**
     * Re-seeding is part of every deploy. It must not undo a password somebody
     * deliberately changed — which `updateOrCreate` did.
     */
    public function test_re_seeding_leaves_an_existing_password_alone(): void
    {
        config(['knsoftic.admin.password' => 'harbour-lantern-93']);
        $this->seed(SuperAdminSeeder::class);

        $admin = User::withoutGlobalScopes()->where('is_super_admin', true)->sole();
        $admin->forceFill(['password' => Hash::make('changed-by-a-human-42')])->save();

        // A later deploy, with the old value still sitting in .env.
        $this->seed(SuperAdminSeeder::class);

        $this->assertTrue(Hash::check('changed-by-a-human-42', $admin->refresh()->password),
            'A deploy must not put the old password back.');

        $this->assertSame(1, User::withoutGlobalScopes()->where('is_super_admin', true)->count(),
            'and must not create a second super admin.');
    }
}
