<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscriber extends Model
{
    use HasFactory, BelongsToAccount, SoftDeletes;

    public const STATUSES = ['active', 'pending', 'unsubscribed', 'bounced', 'blocked'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'custom' => 'array',
            'consent_at' => 'datetime',
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'bounce_count' => 'integer',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(SubscriberList::class, 'list_subscriber')
            ->withPivot('subscribed_at')
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function opens(): HasMany
    {
        return $this->hasMany(EmailOpen::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(EmailClick::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(EmailThread::class);
    }

    // ------------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Contacts that may legally receive marketing mail: active status and not
     * present on the account's suppression list.
     */
    public function scopeMailable(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->whereNotExists(function ($sub) {
                $sub->selectRaw(1)
                    ->from('suppressions')
                    ->whereColumn('suppressions.email', 'subscribers.email')
                    ->whereColumn('suppressions.account_id', 'subscribers.account_id');
            });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = Search::contains($term);

        return $query->where(function (Builder $q) use ($like) {
            $q->where('email', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('company', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
    }

    // ------------------------------------------------------------------ helpers

    public function displayName(): string
    {
        return $this->name ?: trim($this->first_name.' '.$this->last_name) ?: $this->email;
    }

    public function isMailable(): bool
    {
        return $this->status === 'active';
    }

    public function customValue(string $key): mixed
    {
        return data_get($this->custom, $key);
    }
}
