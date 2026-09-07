<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suppression extends Model
{
    use HasFactory, BelongsToAccount;

    public const REASONS = [
        'unsubscribed', 'hard_bounce', 'soft_bounce', 'spam_complaint',
        'manual', 'invalid', 'import',
    ];

    protected $guarded = ['id'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $term ? $query->where('email', 'like', Search::contains($term)) : $query;
    }
}
