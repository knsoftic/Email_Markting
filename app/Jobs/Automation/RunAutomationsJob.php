<?php

namespace App\Jobs\Automation;

use App\Services\Automation\AutomationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * One pass over the automation runs that are due.
 *
 * ── Why the tick is a job and not the scheduler itself ──────────────────────
 * A tick can spend a minute inside SMTP. If the scheduler ran the work
 * directly, the next minute's tick would arrive while this one was still
 * talking to a mail server, and two of them would be walking the same runs.
 * The scheduler queues; the queue worker works.
 *
 * ── Two locks, deliberately ─────────────────────────────────────────────────
 * `WithoutOverlapping` stops two of these jobs running at once, which keeps
 * the common case cheap. It is not the safety property, though — a lock can
 * expire while its holder is alive. The guarantee that a subscriber is not
 * mailed twice lives in the runner's conditional UPDATE claim, which holds
 * even if this job runs in five copies.
 */
class RunAutomationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $limit = AutomationRunner::BATCH) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('automations:tick'))->dontRelease()->expireAfter(900)];
    }

    public function handle(AutomationRunner $runner): void
    {
        $result = $runner->tick($this->limit);

        if ($result['claimed'] === 0) {
            return;
        }

        Log::info('Automation tick', $result);
    }
}
