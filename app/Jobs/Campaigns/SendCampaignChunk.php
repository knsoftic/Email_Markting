<?php

namespace App\Jobs\Campaigns;

use App\Models\Scopes\AccountScope;
use App\Models\Campaign;
use App\Notifications\AccountNotifier;
use App\Notifications\CampaignFailed;
use App\Services\Campaigns\CampaignRunner;
use App\Support\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends one chunk of a campaign, then re-queues itself if work remains.
 *
 * ── Why one self-rechaining job, and not one job per recipient ──────────────
 * On the database queue driver, 100,000 jobs means 100,000 rows written, each
 * polled, locked, deleted — the queue table becomes the bottleneck and the
 * payload of every job repeats the same campaign id. A chunked runner writes
 * one queue row at a time and keeps the SMTP connection warm across the whole
 * chunk (Phase 4 caches the transport per sender instance).
 *
 * Re-queueing rather than looping in one long job matters too: a worker that
 * runs for an hour is a worker that cannot be restarted, and on Windows there
 * is no pcntl to signal it. Short jobs mean a deploy or a pause takes effect
 * in seconds.
 */
class SendCampaignChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $backoff = 30;

    /** How long to wait before retrying when nothing has SMTP capacity. */
    public const DEFER_MINUTES = 5;

    public function __construct(public int $campaignId) {}

    /**
     * The campaign id is the lock key, so two chunks of the same campaign can
     * never run concurrently on one queue. Different campaigns still run in
     * parallel.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('campaign-send:'.$this->campaignId))
                ->releaseAfter(30)
                ->expireAfter(900),
        ];
    }

    public function handle(TenantManager $tenant, CampaignRunner $runner): void
    {
        // withoutGlobalScope(AccountScope::class), not the plural form: the
        // plural also lifts SoftDeletingScope, so a campaign deleted after
        // this chunk was queued would carry on sending. Deleting it is the
        // one action taken to stop it.
        $campaign = Campaign::withoutGlobalScope(AccountScope::class)->find($this->campaignId);

        if (! $campaign) {
            return;
        }

        // A worker is long-lived, so the tenant is bound explicitly. Inheriting
        // whatever the previous job left bound would read another account's
        // suppression list and SMTP accounts.
        $tenant->runAs($campaign->account_id, function () use ($campaign, $runner) {
            $result = $runner->runChunk($campaign);

            $this->rechain($campaign, $result);
        });
    }

    /**
     * @param  array{claimed:int, sent:int, failed:int, deferred:int, skipped:int, finished:bool, paused:bool, awaiting_decision:bool}  $result
     */
    protected function rechain(Campaign $campaign, array $result): void
    {
        if ($result['finished'] || $result['paused']) {
            return;
        }

        // The sample has gone out and the holdback is waiting on the winner.
        // Stop here: the decision restarts sending, and re-queueing once a
        // second for the next four hours would burn a worker slot doing
        // nothing at all.
        if ($result['awaiting_decision']) {
            return;
        }

        $campaign->refresh();

        if (! in_array($campaign->status, ['queued', 'sending'], true)) {
            return;
        }

        // Nothing had capacity: back off rather than spinning.
        if ($result['deferred'] > 0 && $result['sent'] === 0) {
            self::dispatch($this->campaignId)->delay(now()->addMinutes(self::DEFER_MINUTES));

            return;
        }

        self::dispatch($this->campaignId)->delay(now()->addSeconds($this->pace($result['sent'])));
    }

    /**
     * Spacing between chunks, derived from the install-wide ceiling in
     * config/knsoftic.php. Delaying the next chunk is what paces sending —
     * sleeping inside the worker would just hold a slot doing nothing.
     */
    protected function pace(int $sent): int
    {
        $perMinute = max(1, (int) config('knsoftic.send_rate_per_minute', 120));

        if ($sent <= 0) {
            return 1;
        }

        return (int) max(0, min(60, ceil($sent / $perMinute * 60)));
    }

    /**
     * After the final retry the campaign is marked failed, but only if it has
     * not already finished — a late failure must not overwrite a completed run.
     */
    public function failed(Throwable $exception): void
    {
        $marked = DB::update(
            "UPDATE campaigns SET status = 'failed', last_error = ?, updated_at = ?
              WHERE id = ? AND status IN ('queued','sending')",
            [mb_substr($exception->getMessage(), 0, 1000), now(), $this->campaignId]
        );

        // Only when this call is what actually failed the campaign. A late
        // failure arriving after the send already completed changes nothing,
        // and must not tell anybody that it did.
        if ($marked !== 1) {
            return;
        }

        // Nobody is told a campaign failed if they have already deleted it.
        $campaign = Campaign::withoutGlobalScope(AccountScope::class)->find($this->campaignId);

        if ($campaign) {
            AccountNotifier::send($campaign->account_id, new CampaignFailed(
                (int) $campaign->id,
                (string) $campaign->name,
                $exception->getMessage(),
                (int) $campaign->sent_count,
            ));
        }
    }
}
