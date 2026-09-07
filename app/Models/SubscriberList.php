<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriberList extends Model
{
    use HasFactory, BelongsToAccount, SoftDeletes;

    protected $table = 'subscriber_lists';

    protected $guarded = ['id'];

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(Subscriber::class, 'list_subscriber')
            ->withPivot('subscribed_at')
            ->withTimestamps();
    }

    public function activeSubscribers(): BelongsToMany
    {
        return $this->subscribers()->where('subscribers.status', 'active');
    }

    /**
     * Recomputes the denormalised counters. Called by ListService after any
     * bulk membership change rather than on every single attach.
     */
    public function refreshCounts(): void
    {
        $counts = $this->subscribers()
            ->selectRaw('subscribers.status, COUNT(*) as aggregate')
            ->groupBy('subscribers.status')
            ->pluck('aggregate', 'status');

        $this->forceFill([
            'total_count' => (int) $counts->sum(),
            'active_count' => (int) ($counts['active'] ?? 0),
            'unsubscribed_count' => (int) ($counts['unsubscribed'] ?? 0),
            'bounced_count' => (int) ($counts['bounced'] ?? 0),
        ])->save();
    }
}
