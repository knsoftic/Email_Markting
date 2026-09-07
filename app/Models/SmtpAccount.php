<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmtpAccount extends Model
{
    use HasFactory, BelongsToAccount, SoftDeletes;

    /**
     * `password` is hidden as well as encrypted so it cannot leak through
     * toArray()/toJson() into a view, a log or an API response.
     */
    protected $hidden = ['password'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'is_global' => 'boolean',
            'is_active' => 'boolean',
            'verify_peer' => 'boolean',
            'test_passed' => 'boolean',
            'hour_reset_at' => 'datetime',
            'day_reset_at' => 'datetime',
            'month_reset_at' => 'datetime',
            'cooldown_until' => 'datetime',
            'last_error_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * A platform-owned row must always have account_id = NULL.
     *
     * BelongsToAccount stamps account_id from the bound tenant on create, and
     * withoutGlobalScopes() only removes the QUERY scope, not that model
     * event. Without this, creating a global account while any tenant happened
     * to be bound would quietly turn it into that tenant's private account —
     * it would vanish from the admin list and show up in one customer's.
     */
    public function shouldStampAccountId(): bool
    {
        return ! $this->is_global;
    }

    /** Admin SMTP rows (account_id = null) are visible to permitted tenants. */
    public function accountScopeIncludesGlobal(): bool
    {
        return true;
    }

    // ---------------------------------------------------------------- relations

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SmtpAssignment::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(SmtpUsage::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    // ------------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSendable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now());
            });
    }

    // ------------------------------------------------------------------ helpers

    public function isInCooldown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    /**
     * Whether this account still has headroom under all three limits.
     * Counter rollover is handled by SmtpSelector before this is called.
     */
    public function hasCapacity(int $needed = 1): bool
    {
        foreach ([
            ['hourly_limit', 'sent_this_hour'],
            ['daily_limit', 'sent_today'],
            ['monthly_limit', 'sent_this_month'],
        ] as [$limitKey, $usedKey]) {
            $limit = $this->{$limitKey};

            if ($limit !== null && ($this->{$usedKey} + $needed) > $limit) {
                return false;
            }
        }

        return true;
    }

    public function remainingToday(): ?int
    {
        return $this->daily_limit === null
            ? null
            : max(0, $this->daily_limit - $this->sent_today);
    }

    public function maskedUsername(): string
    {
        $username = (string) $this->username;

        if (! str_contains($username, '@')) {
            return mb_substr($username, 0, 2).str_repeat('*', max(0, mb_strlen($username) - 2));
        }

        [$local, $domain] = explode('@', $username, 2);

        return mb_substr($local, 0, 2).str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
