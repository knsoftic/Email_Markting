<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignVariant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_winner' => 'boolean'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function openRate(): float
    {
        return $this->sent_count > 0
            ? round(($this->unique_opens / $this->sent_count) * 100, 2)
            : 0.0;
    }

    public function clickRate(): float
    {
        return $this->sent_count > 0
            ? round(($this->unique_clicks / $this->sent_count) * 100, 2)
            : 0.0;
    }
}
