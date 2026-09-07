<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Every user belongs to an account, so the factory creates one by default
     * and makes the user its owner. Tests that need a second member of the
     * same account use ->forAccount($account).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'status' => 'active',
            'timezone' => 'UTC',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            $account = Account::withoutGlobalScopes()->find($user->account_id);

            if ($account && $account->owner_id === null) {
                $account->forceFill(['owner_id' => $user->id])->save();
            }
        });
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    /** A staff member inside an existing account, not its owner. */
    public function forAccount(Account $account): static
    {
        return $this->state(fn () => ['account_id' => $account->id]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'account_id' => null,
            'is_super_admin' => true,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
