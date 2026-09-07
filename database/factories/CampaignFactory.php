<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->unique()->words(3, true),
            'subject' => fake()->sentence(4),
            'from_name' => fake()->company(),
            'from_email' => fake()->companyEmail(),
            'status' => 'draft',
        ];
    }

    public function forAccount(Account|int $account): static
    {
        return $this->state(fn () => [
            'account_id' => $account instanceof Account ? $account->id : $account,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'started_at' => now()->subDay(),
            'completed_at' => now(),
        ]);
    }
}
