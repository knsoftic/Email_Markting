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

    /**
     * withTrashed(), and this matters more than it looks.
     *
     * Plans are soft-deleted. Without this, deleting one resolved `plan` to
     * null for every account still on it — and `limit()` then returned null for
     * every key, which `PlanLimits::isUnlimited()` reads as UNLIMITED. So
     * removing a plan from the list silently handed its customers unlimited
     * contacts and unlimited sending, while `allows()` (which treats null as
     * false) switched every feature off at the same time. Neither half was
     * intended and the combination is incoherent.
     *
     * A deleted plan disappears from the admin list so it cannot be assigned
     * again, and goes on governing the accounts already on it until an operator
     * moves them.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
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
