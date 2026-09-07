<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\SubscriberList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriberList>
 */
class SubscriberListFactory extends Factory
{
    protected $model = SubscriberList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->unique()->words(2, true).' list',
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function forAccount(Account|int $account): static
    {
        return $this->state(fn () => [
            'account_id' => $account instanceof Account ? $account->id : $account,
        ]);
    }
}
