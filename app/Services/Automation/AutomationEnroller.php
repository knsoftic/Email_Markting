<?php

namespace App\Services\Automation;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Scopes\AccountScope;
use App\Models\Subscriber;
use App\Services\Contacts\SuppressionService;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Puts a subscriber into an automation.
 *
 * ── Why entering twice is the bug to prevent, not a feature to allow ────────
 * An automation that welcomes new subscribers will be triggered again the next
 * time that contact is imported, re-tagged, or added to a second list. Without
 * a guard, the same person gets the same welcome three times, and the sender
 * looks broken to exactly the customer they were trying to impress.
 *
 * `automation_runs` has a unique index on (automation_id, subscriber_id), and
 * that index — not a SELECT — is what decides. Two events arriving at once
 * both try to insert; one wins, the other catches the violation and walks
 * away. `allow_reentry` is opt-in per automation and re-arms the existing run
 * rather than creating a second one.
 */
class AutomationEnroller
{
    public function __construct(protected SuppressionService $suppressions) {}

    /**
     * Enrols one subscriber, or returns null when they should not enter.
     *
     * @param  array<string, mixed>  $context  what the trigger knew, kept for condition steps
     * @param  bool  $allowReentry  false forbids re-arming a finished run even
     *                              where the automation permits it — see the
     *                              cycle note on the runner's applyTag()
     */
    public function enrol(
        Automation $automation,
        Subscriber $subscriber,
        array $context = [],
        bool $allowReentry = true,
    ): ?AutomationRun {
        if (! $automation->isRunnable()) {
            return null;
        }

        if (! $this->eligible($automation, $subscriber)) {
            return null;
        }

        $first = $automation->steps()->orderBy('position')->first();

        if ($first === null) {
            return null;
        }

        try {
            $run = AutomationRun::withoutGlobalScope(AccountScope::class)->create([
                'account_id' => $automation->account_id,
                'automation_id' => $automation->id,
                'subscriber_id' => $subscriber->id,
                'current_step_id' => $first->id,
                'status' => 'waiting',
                // Due immediately. A wait step earns its delay when the runner
                // reaches it, not before — otherwise every automation would be
                // late by its first step's duration.
                'next_run_at' => now(),
                'started_at' => now(),
                'context' => $context,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already in this automation. Whether that is an error depends on
            // the automation, not on us.
            return $allowReentry && $automation->allow_reentry
                ? $this->rearm($automation, $subscriber, $first->id, $context)
                : null;
        }

        $automation->increment('entered_count');

        return $run;
    }

    /**
     * Puts a finished run back to the start, for an automation that allows it.
     *
     * Only a run that has actually FINISHED may re-enter. Re-arming one that
     * is mid-flight would abandon a subscriber halfway through a sequence and
     * start them again — they would get step one twice and step four never.
     */
    protected function rearm(Automation $automation, Subscriber $subscriber, int $firstStepId, array $context): ?AutomationRun
    {
        $run = AutomationRun::withoutGlobalScope(AccountScope::class)
            ->where('automation_id', $automation->id)
            ->where('subscriber_id', $subscriber->id)
            ->whereIn('status', ['completed', 'cancelled', 'failed'])
            ->first();

        if ($run === null) {
            return null;
        }

        $run->forceFill([
            'current_step_id' => $firstStepId,
            'status' => 'waiting',
            'next_run_at' => now(),
            'started_at' => now(),
            'completed_at' => null,
            'steps_completed' => 0,
            'last_error' => null,
            'context' => $context,
        ])->save();

        $automation->increment('entered_count');

        return $run;
    }

    /**
     * Whether this contact should be entered at all.
     *
     * The suppression check happens here AND again at send time. Here, because
     * enrolling somebody who has opted out and then dropping every email is
     * dishonest bookkeeping — the automation would report entries it never
     * mailed. At send time, because a contact can opt out midway through a
     * three-week sequence, and the only correct answer then is to stop.
     */
    public function eligible(Automation $automation, Subscriber $subscriber): bool
    {
        if ($subscriber->status !== 'active') {
            return false;
        }

        return ! $this->suppressions->isSuppressed($subscriber->email, $automation->account_id);
    }

    /**
     * Stops a subscriber's run, without pretending it finished.
     */
    public function cancel(AutomationRun $run, string $reason): void
    {
        $run->forceFill([
            'status' => 'cancelled',
            'completed_at' => now(),
            'next_run_at' => null,
            'last_error' => mb_substr($reason, 0, 1000),
        ])->save();
    }
}
