<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Scopes\AccountScope;
use App\Services\Campaigns\AbTestService;
use Illuminate\Console\Command;

/**
 * Picks the winner of every split test whose decision window has closed, and
 * releases the held-back audience to it.
 *
 * Runs every minute and normally does nothing: one indexed count decides
 * whether there is a test waiting at all.
 */
class DecideAbTests extends Command
{
    protected $signature = 'campaigns:decide-ab
                            {--campaign= : Decide one campaign now, ignoring its window}';

    protected $description = 'Choose the winning version of any split test whose window has closed';

    public function handle(AbTestService $ab): int
    {
        if ($id = $this->option('campaign')) {
            // withoutGlobalScope(AccountScope::class), not withoutGlobalScopes():
            // the plural form also strips SoftDeletingScope, and deciding a
            // campaign the operator has deleted would release its held-back
            // audience and notify somebody about a campaign they binned.
            $campaign = Campaign::withoutGlobalScope(AccountScope::class)->find((int) $id);

            if ($campaign === null || ! $campaign->is_ab_test) {
                $this->error('That campaign does not exist or is not a split test.');

                return self::FAILURE;
            }

            if ($campaign->ab_decided_at !== null) {
                $this->warn('That split test has already been decided.');

                return self::SUCCESS;
            }

            // --campaign means "ignore the waiting window", not "ignore the
            // data". The screen refuses this state with a 422 and the sweep
            // skips it; the command used to pick a winner by comparing a
            // version that had gone out against one that had sent nothing.
            if ($ab->sampleStillSending($campaign)) {
                $this->error('Part of the tested sample has not gone out yet, so there is nothing to '
                    .'compare it against. Wait for the sample to finish sending.');

                return self::FAILURE;
            }

            $winner = $ab->decide($campaign);

            if ($winner === null) {
                $this->warn('No version has sent anything yet, so there is nothing to compare.');

                return self::SUCCESS;
            }

            $this->info("Version {$winner->label} wins; the remaining audience will receive it.");

            return self::SUCCESS;
        }

        $waiting = Campaign::withoutGlobalScope(AccountScope::class)
            ->where('is_ab_test', true)
            ->whereNull('ab_decided_at')
            ->whereIn('status', ['sending', 'queued', 'completed'])
            ->count();

        if ($waiting === 0) {
            $this->info('No split test is waiting on a decision.');

            return self::SUCCESS;
        }

        $result = $ab->decideDue();

        $this->info($result['decided'] === 0
            ? "{$waiting} split test(s) are still inside their measuring window."
            : "Decided {$result['decided']} split test(s).");

        return self::SUCCESS;
    }
}
