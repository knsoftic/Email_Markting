<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailboxFolder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_syncable' => 'boolean',
            'last_sync_at' => 'datetime',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }
}
