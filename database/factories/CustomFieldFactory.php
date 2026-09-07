<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomField>
 */
class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'account_id' => Account::factory(),
            'name' => Str::title($name),
            'key' => Str::slug($name, '_'),
            'type' => 'text',
        ];
    }

    public function forAccount(Account|int $account): static
    {
        return $this->state(fn () => [
            'account_id' => $account instanceof Account ? $account->id : $account,
        ]);
    }
}
