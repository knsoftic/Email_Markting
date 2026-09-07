<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailThread extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class)->orderBy('received_at');
    }

    public function scopeCampaignReplies(Builder $query): Builder
    {
        return $query->whereNotNull('campaign_id')->whereNotNull('reply_status');
    }

    public function refreshCounts(): void
    {
        $this->forceFill([
            'messages_count' => $this->emails()->count(),
            'unread_count' => $this->emails()->where('is_read', false)->count(),
            'last_message_at' => $this->emails()->max('received_at'),
        ])->save();
    }
}
