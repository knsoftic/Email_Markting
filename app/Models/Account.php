<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'owner_id', 'company_name', 'website', 'phone',
        'country', 'timezone', 'status', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'storage_used' => 'integer',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * An account's own subscription must be readable whatever tenant is bound;
     * otherwise this returns null the moment one account looks at another
     * (the admin panel, a job, the SMTP selector) and every plan check
     * silently reads as "not allowed".
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->withoutGlobalScopes()
            ->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function usageCounters(): HasMany
    {
        return $this->hasMany(UsageCounter::class);
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(Subscriber::class);
    }

    public function lists(): HasMany
    {
        return $this->hasMany(SubscriberList::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }

    public function smtpAccounts(): HasMany
    {
        return $this->hasMany(SmtpAccount::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(Suppression::class);
    }

    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    // ------------------------------------------------------------------ helpers

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function plan(): ?Plan
    {
        return $this->subscription?->plan;
    }
}
