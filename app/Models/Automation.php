<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Automation extends Model
{
    use BelongsToAccount, SoftDeletes;

    public const TRIGGERS = [
        'subscriber_added', 'tag_added', 'list_joined', 'campaign_opened',
        'link_clicked', 'campaign_not_opened', 'specific_date',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'allow_reentry' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function smtpAccount(): BelongsTo
    {
        return $this->belongsTo(SmtpAccount::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class)->orderBy('position');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isRunnable(): bool
    {
        return $this->status === 'active' && $this->steps()->exists();
    }
}
