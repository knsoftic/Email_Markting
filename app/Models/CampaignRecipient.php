<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRecipient extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'first_clicked_at' => 'datetime',
            'last_clicked_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'bounced_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(CampaignVariant::class, 'campaign_variant_id');
    }

    public function smtpAccount(): BelongsTo
    {
        return $this->belongsTo(SmtpAccount::class);
    }

    public function opens(): HasMany
    {
        return $this->hasMany(EmailOpen::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(EmailClick::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function wasOpened(): bool
    {
        return $this->open_count > 0;
    }

    public function wasClicked(): bool
    {
        return $this->click_count > 0;
    }
}
