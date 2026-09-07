<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Suppression;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suppression>
 */
class SuppressionFactory extends Factory
{
    protected $model = Suppression::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'email' => fake()->unique()->safeEmail(),
            'reason' => 'manual',
            'source' => 'admin',
        ];
    }

    public function forAccount(Account|int $account): static
    {
        return $this->state(fn () => [
            'account_id' => $account instanceof Account ? $account->id : $account,
        ]);
    }

    public function reason(string $reason): static
    {
        return $this->state(fn () => ['reason' => $reason]);
    }
}
