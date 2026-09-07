<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'account_id' => Account::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'color' => fake()->hexColor(),
        ];
    }

    public function forAccount(Account|int $account): static
    {
        return $this->state(fn () => [
            'account_id' => $account instanceof Account ? $account->id : $account,
        ]);
    }

    public function named(string $name): static
    {
        return $this->state(fn () => ['name' => $name, 'slug' => Str::slug($name)]);
    }
}
