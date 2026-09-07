<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mailbox extends Model
{
    use HasFactory, BelongsToAccount, SoftDeletes;

    protected $table = 'mailboxes';

    protected $hidden = ['imap_password'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'imap_password' => 'encrypted',
            'imap_validate_cert' => 'boolean',
            'sync_enabled' => 'boolean',
            'is_active' => 'boolean',
            'last_sync_at' => 'datetime',
            'last_sync_started_at' => 'datetime',
            'last_error_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function smtpAccount(): BelongsTo
    {
        return $this->belongsTo(SmtpAccount::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(MailboxFolder::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class);
    }

    // ------------------------------------------------------------------- scopes

    public function scopeSyncable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('sync_enabled', true);
    }

    // ------------------------------------------------------------------ helpers

    public function isDueForSync(): bool
    {
        if (! $this->sync_enabled || ! $this->is_active) {
            return false;
        }

        return $this->last_sync_at === null
            || $this->last_sync_at->addMinutes($this->sync_interval_minutes)->isPast();
    }

    public function isHealthy(): bool
    {
        return $this->status === 'connected' && $this->consecutive_failures === 0;
    }

    public function folderOfType(string $type): ?MailboxFolder
    {
        return $this->folders->firstWhere('type', $type);
    }
}
