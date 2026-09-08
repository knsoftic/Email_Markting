<?php

namespace App\Console\Commands;

use App\Models\Scopes\AccountScope;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Sets a super admin's password from the server.
 *
 * ── Why this exists at all ──────────────────────────────────────────────────
 * `SuperAdminSeeder` reads `KNS_ADMIN_PASSWORD` from the environment and falls
 * back to `password`. Both the fallback and the placeholder in `.env.example`
 * are published in a public repository, so every install that has not changed
 * it has a platform administrator whose password anybody can read. The seeder
 * also calls `Hash::make()` directly, which means the password policy that
 * governs every other password in the application never sees it.
 *
 * Changing it through the web interface works, but requires signing in with the
 * known password first — over the internet, on an account that can see every
 * tenant. This does it from the server, before that is ever necessary.
 *
 * ── The password is never an argument ───────────────────────────────────────
 * It is prompted for, with the input hidden. A password passed on the command
 * line is recorded in `~/.bash_history`, is visible to every other user in
 * `ps`, and on many systems is shipped to a log aggregator. Deliberately there
 * is no `--password` option; making the safe path the only path is the point.
 */
class SetAdminPassword extends Command
{
    protected $signature = 'kn:admin-password {email? : Which super admin. Defaults to the only one.}';

    protected $description = 'Set a super admin password, prompted for rather than typed on the command line';

    public function handle(): int
    {
        // Singular: the plural form also lifts SoftDeletingScope, which offered
        // deleted admins as targets and let this set a password on an account
        // that can never sign in.
        $admins = User::withoutGlobalScope(AccountScope::class)
            ->where('is_super_admin', true)->get();

        if ($admins->isEmpty()) {
            $this->error('There is no super admin on this install. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        $email = $this->argument('email');

        if ($email === null && $admins->count() > 1) {
            $this->error('There is more than one super admin. Name the one you mean:');

            foreach ($admins as $admin) {
                $this->line('  '.$admin->email);
            }

            return self::FAILURE;
        }

        $user = $email === null
            ? $admins->first()
            : $admins->firstWhere('email', $email);

        if ($user === null) {
            $this->error("No super admin with the address {$email}.");

            return self::FAILURE;
        }

        $this->info("Setting the password for {$user->email}.");

        $password = $this->secret('New password');
        $confirm = $this->secret('Type it again');

        if ($password !== $confirm) {
            $this->error('Those did not match. Nothing was changed.');

            return self::FAILURE;
        }

        // The same policy every other password in the application is held to.
        // The seeder bypasses it by hashing directly; this does not.
        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::defaults()]]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->get('password') as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        $this->newLine();
        $this->info('Done. Sign in with the new password.');
        $this->line('  If KNS_ADMIN_PASSWORD is still in your .env, remove it: re-running the');
        $this->line('  seeder would otherwise put the old one back.');

        return self::SUCCESS;
    }
}
