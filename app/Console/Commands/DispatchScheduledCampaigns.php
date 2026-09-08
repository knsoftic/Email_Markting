<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\Campaigns\CampaignDispatcher;
use App\Support\ActivityLogger;
use App\Support\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Starts campaigns whose scheduled time has arrived.
 *
 * The claim is a conditional UPDATE, not a select-then-update: the scheduler
 * runs every minute and a slow run can still be going when the next one
 * starts. Whoever flips the row from `scheduled` to `queued` owns it, and the
 * loser's UPDATE matches nothing — so a campaign cannot be dispatched twice
 * and sent to everybody twice.
 */
class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-scheduled';

    protected $description = 'Start any campaign whose scheduled send time has passed';

    public function handle(CampaignDispatcher $dispatcher, TenantManager $tenant): int
    {
        // acrossAccounts(), not withoutGlobalScopes(): the command has to see
        // every tenant, but dropping ALL scopes drops the soft-delete one too,
        // and a campaign deleted while still scheduled would then be picked up
        // here and sent to its whole audience after the operator deleted it.
        $due = Campaign::acrossAccounts()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            // A suspended account's campaigns stay scheduled and go out if it
            // is reactivated. The sender refuses them anyway, but queueing work
            // that is certain to be refused fills the failed-job table with
            // noise an operator then has to read past.
            ->whereHas('account', fn ($q) => $q->where('status', 'active'))
            ->orderBy('scheduled_at')
            ->limit(50)
            ->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($due as $campaign) {
            // Claim it. Only one process can win this.
            // deleted_at is re-checked here as well as in the select above:
            // this UPDATE is the authority on the claim, and a campaign deleted
            // between the two must lose it.
            $claimed = DB::update(
                "UPDATE campaigns SET status = 'queued', updated_at = ?
                  WHERE id = ? AND status = 'scheduled' AND deleted_at IS NULL",
                [now(), $campaign->id]
            );

            if ($claimed !== 1) {
                continue;
            }

            try {
                $tenant->runAs($campaign->account_id, function () use ($dispatcher, $campaign) {
                    // Put it back to a startable state for the dispatcher, now
                    // that this process owns it.
                    $campaign->forceFill(['status' => 'scheduled'])->save();

                    $dispatcher->sendNow($campaign);
                });

                $this->info("Started: {$campaign->name}");
            } catch (Throwable $e) {
                DB::update(
                    "UPDATE campaigns SET status = 'failed', last_error = ?, updated_at = ?
                      WHERE id = ? AND status IN ('scheduled','queued')",
                    [mb_substr($e->getMessage(), 0, 1000), now(), $campaign->id]
                );

                ActivityLogger::log(
                    'campaign.schedule_failed',
                    "Scheduled campaign {$campaign->name} could not start: ".$e->getMessage(),
                    ['account_id' => $campaign->account_id],
                    $campaign
                );

                $this->error("Failed: {$campaign->name} — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
