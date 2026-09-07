<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AutomationStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    /**
     * Wait steps translate their config into a concrete resume time.
     *
     * Every unit is capped at roughly a year, and not for tidiness:
     * `automation_runs.next_run_at` is a MySQL TIMESTAMP, which cannot hold a
     * date past 2038. "Wait 999 weeks" typed into the builder would compute a
     * date the column silently refuses, and the run would either fail to save
     * or come back as a zero date that is instantly due — a subscriber parked
     * forever, or mailed immediately. Neither is what the number said.
     */
    public function waitUntil(): ?Carbon
    {
        if ($this->type !== 'wait') {
            return null;
        }

        $amount = max(0, (int) ($this->config['amount'] ?? 0));
        $unit = $this->config['unit'] ?? 'days';

        return match ($unit) {
            'minutes' => now()->addMinutes(min($amount, 525600)),
            'hours' => now()->addHours(min($amount, 8760)),
            'weeks' => now()->addWeeks(min($amount, 52)),
            default => now()->addDays(min($amount, 365)),
        };
    }
}
