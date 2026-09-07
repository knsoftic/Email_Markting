<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the platform's first super admin.
 *
 * ── Read from config, never from env() ──────────────────────────────────────
 * This used to call `env('KNS_ADMIN_PASSWORD', 'password')` directly, and that
 * is a trap rather than a style question. Outside a config file, `env()`
 * returns its DEFAULT once `config:cache` has run, because Laravel then stops
 * loading `.env` at all. So on any deployed install — where caching config is
 * the first thing anybody does — the seeder ignored the password in `.env`
 * entirely and hashed the fallback, giving the account that can see every
 * tenant a password published in a public repository, while `.env` appeared
 * to say something else.
 *
 * ── No fallback password ────────────────────────────────────────────────────
 * There is no default any more. If `KNS_ADMIN_PASSWORD` is unset the seeder
 * generates a long random one and prints it once, so an install can never
 * quietly come up with a password somebody could look up. Printed, not stored:
 * it is not written to a log or a file, and `kn:admin-password` replaces it
 * without needing to know it.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::withoutGlobalScopes()->where('slug', Role::SUPER_ADMIN)->first();

        $email = (string) config('knsoftic.admin.email');
        $password = (string) config('knsoftic.admin.password');

        $generated = false;

        if (trim($password) === '') {
            $password = Str::password(20);
            $generated = true;
        }

        $existing = User::withoutGlobalScopes()->where('email', $email)->first();

        // An existing admin keeps the password they have. Re-running the
        // seeder as part of a deploy must not reset a password somebody
        // deliberately changed — which is exactly what updateOrCreate did.
        if ($existing) {
            $existing->forceFill([
                'role_id' => $role?->id,
                'is_super_admin' => true,
                'status' => 'active',
            ])->save();

            $this->command?->info("Super admin already exists: {$email} (password unchanged)");

            return;
        }

        User::withoutGlobalScopes()->create([
            'account_id' => null,
            'role_id' => $role?->id,
            'is_super_admin' => true,
            'name' => (string) config('knsoftic.admin.name'),
            'email' => $email,
            'password' => Hash::make($password),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->command?->info("Super admin created: {$email}");

        if ($generated) {
            $this->command?->warn('KNS_ADMIN_PASSWORD was not set, so one was generated.');
            $this->command?->warn('This is shown once and stored nowhere:');
            $this->command?->line('');
            $this->command?->line('    '.$password);
            $this->command?->line('');
            $this->command?->warn('Change it with: php artisan kn:admin-password');
        }
    }
}
