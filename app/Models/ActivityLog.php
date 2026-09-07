<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    /** Platform-level events (super admin actions) have account_id = null. */
    public function accountScopeIncludesGlobal(): bool
    {
        return false;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeEvent(Builder $query, string|array $event): Builder
    {
        return $query->whereIn('event', (array) $event);
    }
}
