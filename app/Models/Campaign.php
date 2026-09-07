<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, BelongsToAccount, SoftDeletes;

    public const STATUSES = [
        'draft', 'scheduled', 'queued', 'sending', 'paused',
        'completed', 'failed', 'cancelled',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'audience' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'paused_at' => 'datetime',
            'ab_decided_at' => 'datetime',
            'track_opens' => 'boolean',
            'track_clicks' => 'boolean',
            'is_ab_test' => 'boolean',
            'use_smtp_rotation' => 'boolean',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function smtpAccount(): BelongsTo
    {
        return $this->belongsTo(SmtpAccount::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(CampaignVariant::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(CampaignLink::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CampaignLog::class);
    }

    public function opens(): HasMany
    {
        return $this->hasMany(EmailOpen::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(EmailClick::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Email::class)->where('is_campaign_reply', true);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(EmailThread::class);
    }

    // ------------------------------------------------------------------- scopes

    public function scopeStatus(Builder $query, string|array $status): Builder
    {
        return $query->whereIn('status', (array) $status);
    }

    public function scopeDueForSending(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }

    // ------------------------------------------------------------------ helpers

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled', 'paused'], true);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'sending'], true);
    }

    public function progressPercent(): float
    {
        if ($this->total_recipients === 0) {
            return 0.0;
        }

        $done = $this->sent_count + $this->failed_count;

        return round(min(100, ($done / $this->total_recipients) * 100), 1);
    }

    public function remainingCount(): int
    {
        return max(0, $this->total_recipients - $this->sent_count - $this->failed_count);
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

    public function replyRate(): float
    {
        return $this->sent_count > 0
            ? round(($this->replied_count / $this->sent_count) * 100, 2)
            : 0.0;
    }

    public function bounceRate(): float
    {
        return $this->sent_count > 0
            ? round(($this->bounced_count / $this->sent_count) * 100, 2)
            : 0.0;
    }
}
