<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\SmtpAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmtpAccount>
 */
class SmtpAccountFactory extends Factory
{
    protected $model = SmtpAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'is_global' => false,
            'name' => fake()->unique()->words(2, true).' SMTP',
            'provider' => 'custom',
            'host' => 'smtp.example.test',
            'port' => 587,
            'username' => fake()->unique()->safeEmail(),
            'password' => 'secret-'.fake()->uuid(),
            'encryption' => 'tls',
            'verify_peer' => true,
            'from_name' => fake()->company(),
            'from_email' => fake()->unique()->companyEmail(),
            'is_active' => true,
            'priority' => 0,
        ];
    }

    public function forAccount(Account|int $account): static
    {
        return $this->state(fn () => [
            'account_id' => $account instanceof Account ? $account->id : $account,
            'is_global' => false,
        ]);
    }

    /** A platform-provided account, shared with tenants via smtp_assignments. */
    public function global(): static
    {
        return $this->state(fn () => ['account_id' => null, 'is_global' => true]);
    }

    public function limits(?int $hourly = null, ?int $daily = null, ?int $monthly = null): static
    {
        return $this->state(fn () => [
            'hourly_limit' => $hourly,
            'daily_limit' => $daily,
            'monthly_limit' => $monthly,
        ]);
    }

    /** Counters already at the ceiling, for exhaustion tests. */
    public function exhausted(int $daily = 500): static
    {
        return $this->state(fn () => [
            'daily_limit' => $daily,
            'sent_today' => $daily,
            'day_reset_at' => now(),
        ]);
    }

    public function inCooldown(int $minutes = 30): static
    {
        return $this->state(fn () => [
            'consecutive_failures' => 5,
            'cooldown_until' => now()->addMinutes($minutes),
            'last_error' => 'Authentication failed',
            'last_error_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
