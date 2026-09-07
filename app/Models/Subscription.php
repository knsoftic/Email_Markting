<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'price_paid' => 'decimal:2',
            'overrides' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isActive(): bool
    {
        if (! in_array($this->status, ['active', 'trial'], true)) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    public function daysRemaining(): ?int
    {
        return $this->ends_at?->diffInDays(now(), false) * -1;
    }

    /**
     * Effective value of a plan limit or feature, after per-account overrides.
     */
    public function limit(string $key): int|bool|null
    {
        $overrides = $this->overrides ?? [];

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        return $this->plan?->{$key};
    }
}
