<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignRecipient>
 */
class CampaignRecipientFactory extends Factory
{
    protected $model = CampaignRecipient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'subscriber_id' => Subscriber::factory(),
            'email' => fake()->unique()->safeEmail(),
            'status' => 'sent',
            'sent_at' => now(),
        ];
    }

    public function opened(int $times = 1): static
    {
        return $this->state(fn () => [
            'open_count' => $times,
            'first_opened_at' => now(),
            'last_opened_at' => now(),
        ]);
    }

    public function clicked(int $times = 1): static
    {
        return $this->state(fn () => [
            'click_count' => $times,
            'first_clicked_at' => now(),
            'last_clicked_at' => now(),
        ]);
    }
}
