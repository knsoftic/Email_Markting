<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailOpen extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
