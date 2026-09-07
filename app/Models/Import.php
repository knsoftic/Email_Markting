<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Import extends Model
{
    use HasFactory, BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'list_ids' => 'array',
            'tag_ids' => 'array',
            'mapping' => 'array',
            'options' => 'array',
            'error_rows' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progressPercent(): float
    {
        if ($this->total_rows === 0) {
            return 0.0;
        }

        return round(($this->processed_rows / $this->total_rows) * 100, 1);
    }
}
