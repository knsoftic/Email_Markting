<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscriber>
 */
class SubscriberFactory extends Factory
{
    protected $model = Subscriber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'account_id' => Account::factory(),
            'email' => fake()->unique()->safeEmail(),
            'name' => $name,
            'first_name' => explode(' ', $name)[0],
            'last_name' => explode(' ', $name)[1] ?? null,
            'phone' => fake()->optional()->phoneNumber(),
            'company' => fake()->optional()->company(),
            'country' => fake()->country(),
            'city' => fake()->city(),
            'status' => 'active',
            'source' => 'manual',
            'consent_status' => 'explicit',
            'consent_at' => now(),
            'subscribed_at' => now(),
        ];
    }

    public function forAccount(Account|int $account): static
    {
        return $this->state(fn () => [
            'account_id' => $account instanceof Account ? $account->id : $account,
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn () => [
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);
    }

    public function bounced(): static
    {
        return $this->state(fn () => ['status' => 'bounced', 'bounce_count' => 1]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function withCustom(array $values): static
    {
        return $this->state(fn () => ['custom' => $values]);
    }
}
