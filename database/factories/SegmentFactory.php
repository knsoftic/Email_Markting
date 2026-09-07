<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Segment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    protected $model = Segment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->unique()->words(2, true).' segment',
            'description' => fake()->optional()->sentence(),
            'match_type' => 'all',
            'rules' => [
                ['field' => 'status', 'operator' => 'is', 'value' => 'active'],
            ],
        ];
    }

    public function forAccount(Account|int $account): static
    {
        return $this->state(fn () => [
            'account_id' => $account instanceof Account ? $account->id : $account,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function withRules(array $rules, string $matchType = 'all'): static
    {
        return $this->state(fn () => ['rules' => $rules, 'match_type' => $matchType]);
    }
}
