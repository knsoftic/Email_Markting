<?php

namespace App\Jobs\Automation;

use App\Models\Automation;
use App\Models\Scopes\AccountScope;
use App\Services\Automation\AutomationTrigger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Enrols a large batch of contacts into one automation, off the request.
 *
 * An import that puts 50,000 people on a list should not spend 50,000 inserts
 * inside the HTTP request that uploaded it. The trigger hands anything above
 * its inline limit here.
 *
 * Retrying is safe: enrolment is decided by the unique index on
 * (automation_id, subscriber_id), so a job that dies half way and runs again
 * re-enrols nobody it already enrolled.
 */
class EnrolSubscribersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * @param  array<int>  $subscriberIds
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $automationId,
        public array $subscriberIds,
        public array $context = [],
        public bool $allowReentry = true,
    ) {}

    public function handle(AutomationTrigger $trigger): void
    {
        $automation = Automation::withoutGlobalScope(AccountScope::class)->find($this->automationId);

        // Paused, deleted, or emptied of steps between the trigger and now.
        if ($automation === null || ! $automation->isRunnable()) {
            return;
        }

        foreach (array_chunk($this->subscriberIds, 500) as $chunk) {
            $trigger->enrolNow($automation, $chunk, $this->context, $this->allowReentry);
        }
    }
}
