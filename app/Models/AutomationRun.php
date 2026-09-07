<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRun extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'next_run_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(AutomationStep::class, 'current_step_id');
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', 'waiting')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now());
    }
}
