<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailTemplate extends Model
{
    use HasFactory, BelongsToAccount, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** System templates (account_id = null) are readable by every tenant. */
    public function accountScopeIncludesGlobal(): bool
    {
        return true;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isEditableBy(User $user): bool
    {
        return $this->is_system
            ? $user->isSuperAdmin()
            : $this->account_id === $user->account_id;
    }
}
