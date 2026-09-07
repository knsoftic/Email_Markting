<?php

namespace App\Console\Commands;

use App\Jobs\Automation\RunAutomationsJob;
use App\Models\AutomationRun;
use App\Models\Scopes\AccountScope;
use App\Services\Automation\AutomationRunner;
use App\Services\Automation\AutomationSweeper;
use Illuminate\Console\Command;

/**
 * Advances every automation run that is due.
 *
 * Runs every minute from the scheduler and normally does nothing: it queues a
 * tick only when something is actually waiting, so an account with no
 * automations costs one indexed count per minute rather than a queued job.
 *
 * `--sync` runs the tick here instead of queueing it, which is what you want
 * when watching it work or when no queue worker is running.
 */
class RunAutomations extends Command
{
    protected $signature = 'automations:tick
                            {--sync : Run the tick immediately instead of queueing it}
                            {--limit= : How many runs to advance in this tick}';

    protected $description = 'Advance every automation run whose next step is due';

    public function handle(AutomationRunner $runner, AutomationSweeper $sweeper): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: AutomationRunner::BATCH));

        // The two triggers that are an absence rather than an event. Swept
        // here because there is no code path they could fire from.
        $swept = $sweeper->sweep();

        if ($swept['enrolled'] > 0) {
            $this->line("Enrolled {$swept['enrolled']} contact(s) from time-based triggers.");
        }

        $due = AutomationRun::withoutGlobalScope(AccountScope::class)
            ->where('status', 'waiting')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->count();

        $stale = AutomationRun::withoutGlobalScope(AccountScope::class)
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subMinutes(AutomationRunner::LEASE_MINUTES))
            ->count();

        if ($due === 0 && $stale === 0 && $swept['enrolled'] === 0) {
            $this->info('No automation run is due.');

            return self::SUCCESS;
        }

        if ($stale > 0) {
            $this->warn("{$stale} run(s) were left mid-step by a worker that stopped; they will be picked up again.");
        }

        if (! $this->option('sync')) {
            RunAutomationsJob::dispatch($limit);

            $this->info("Queued a tick for {$due} due run(s).");

            return self::SUCCESS;
        }

        $result = $runner->tick($limit);

        $this->info(sprintf(
            'Claimed %d, advanced %d step(s), sent %d, completed %d, deferred %d, failed %d.',
            $result['claimed'], $result['advanced'], $result['sent'],
            $result['completed'], $result['deferred'], $result['failed'],
        ));

        return self::SUCCESS;
    }
}
