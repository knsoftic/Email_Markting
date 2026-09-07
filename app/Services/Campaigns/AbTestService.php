<?php

namespace App\Services\Campaigns;

use App\Jobs\Campaigns\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\CampaignVariant;
use App\Models\Scopes\AccountScope;
use App\Notifications\AbTestDecided;
use App\Notifications\AccountNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Split sends, and the decision at the end of one.
 *
 * ── How the audience is divided ─────────────────────────────────────────────
 * A sample of the list gets the variants; the rest — the holdback — waits and
 * receives whichever variant won. Membership is decided by a hash of
 * (campaign, subscriber), not by row order and not by RAND():
 *
 *   - Row order is signup order. Sampling the first 20% of ids would test the
 *     oldest contacts on the list and then send the winner to the newest,
 *     which are different people who behave differently. The result would look
 *     like a subject-line finding and actually be an audience finding.
 *   - ORDER BY RAND() over 100,000 rows sorts the whole table to pick a fifth
 *     of it, every time.
 *
 * A hash gives an even, arbitrary spread in one indexed UPDATE, and gives the
 * same answer twice — so re-running assignment cannot reshuffle a test that
 * has already started sending.
 *
 * ── Why the winner is decided on unique opens or clicks ─────────────────────
 * Totals reward the bigger half. If A got 60% of the sample it will win on raw
 * opens whatever it said. Every comparison here is a RATE over that variant's
 * own delivered count, and a variant that delivered nothing cannot win.
 */
class AbTestService
{
    /** Labels a test may use, in order. */
    public const LABELS = ['A', 'B', 'C', 'D'];

    /**
     * Splits the audience between the variants.
     *
     * Runs once, immediately before sending starts. Recipients already given a
     * variant are left alone: re-assigning mid-send would move somebody from
     * the group that was mailed A into the group being counted as B.
     *
     * @return array{sample: int, holdback: int}
     */
    public function assign(Campaign $campaign): array
    {
        $variants = $this->variants($campaign);

        if (! $campaign->is_ab_test || $variants->count() < 2) {
            return ['sample' => 0, 'holdback' => 0];
        }

        $total = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->count();

        if ($total === 0) {
            return ['sample' => 0, 'holdback' => 0];
        }

        $samplePercent = $this->samplePercent($campaign, $total, $variants->count());

        // Cumulative boundaries, so 60/40 becomes "under 60" and "under 100".
        $boundaries = $this->boundaries($variants);

        $lower = 0;

        foreach ($boundaries as $variantId => $upper) {
            DB::update(
                'UPDATE campaign_recipients
                    SET campaign_variant_id = ?, updated_at = ?
                  WHERE campaign_id = ?
                    AND campaign_variant_id IS NULL
                    AND status = ?
                    AND (CRC32(CONCAT(campaign_id, \':\', subscriber_id)) % 100) < ?
                    AND (CRC32(CONCAT(\'v\', campaign_id, \':\', subscriber_id)) % 100) >= ?
                    AND (CRC32(CONCAT(\'v\', campaign_id, \':\', subscriber_id)) % 100) < ?',
                [$variantId, now(), $campaign->id, 'pending', $samplePercent, $lower, $upper]
            );

            $lower = $upper;
        }

        $sample = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('campaign_variant_id')
            ->count();

        // A hash can land badly on a very small list — three contacts and a
        // 20% sample can select nobody, and a test that mails nobody never
        // decides and never finishes. Falling back to splitting everybody is
        // the honest answer: the list is too small for a holdback, so the
        // whole send becomes the test.
        if ($sample < $variants->count()) {
            $this->assignEverybody($campaign, $boundaries);

            $sample = DB::table('campaign_recipients')
                ->where('campaign_id', $campaign->id)
                ->whereNotNull('campaign_variant_id')
                ->count();
        }

        return ['sample' => $sample, 'holdback' => $total - $sample];
    }

    /**
     * @param  array<int, int>  $boundaries
     */
    protected function assignEverybody(Campaign $campaign, array $boundaries): void
    {
        $lower = 0;

        foreach ($boundaries as $variantId => $upper) {
            DB::update(
                'UPDATE campaign_recipients
                    SET campaign_variant_id = ?, updated_at = ?
                  WHERE campaign_id = ?
                    AND campaign_variant_id IS NULL
                    AND status = ?
                    AND (CRC32(CONCAT(\'v\', campaign_id, \':\', subscriber_id)) % 100) >= ?
                    AND (CRC32(CONCAT(\'v\', campaign_id, \':\', subscriber_id)) % 100) < ?',
                [$variantId, now(), $campaign->id, 'pending', $lower, $upper]
            );

            $lower = $upper;
        }
    }

    /**
     * Cumulative upper bounds per variant, normalised to 100.
     *
     * Shares that do not add up to 100 are scaled rather than rejected: a
     * three-way test typed as 33/33/33 must still cover every recipient, and
     * the last variant always closes the range so nobody is left unassigned to
     * a rounding gap.
     *
     * @return array<int, int>
     */
    protected function boundaries(Collection $variants): array
    {
        $totalShare = max(1, (int) $variants->sum(fn ($v) => max(0, (int) $v->share_percent)));

        $bounds = [];
        $running = 0;
        $last = $variants->count() - 1;

        foreach ($variants->values() as $i => $variant) {
            $running += (int) round(max(0, (int) $variant->share_percent) / $totalShare * 100);
            $bounds[$variant->id] = $i === $last ? 100 : min(100, $running);
        }

        return $bounds;
    }

    protected function samplePercent(Campaign $campaign, int $total, int $variantCount): int
    {
        $percent = (int) ($campaign->ab_sample_percent ?: 20);

        return max(1, min(100, $percent));
    }

    // -------------------------------------------------------------- results

    /**
     * What each variant actually did, computed from the recipient rows rather
     * than from the denormalised counters — a report that disagrees with the
     * data is worse than no report.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function results(Campaign $campaign): Collection
    {
        $rows = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('campaign_variant_id')
            // Bound rather than inlined: a literal in raw SQL has to be
            // single-quoted to survive ANSI_QUOTES, and a binding needs
            // no quoting argument at all.
            ->selectRaw('campaign_variant_id,
                COUNT(*) as assigned,
                SUM(status = ?) as sent,
                SUM(first_opened_at IS NOT NULL) as opens,
                SUM(first_clicked_at IS NOT NULL) as clicks', ['sent'])
            ->groupBy('campaign_variant_id')
            ->get()
            ->keyBy('campaign_variant_id');

        return $this->variants($campaign)->map(function (CampaignVariant $variant) use ($rows, $campaign) {
            $row = $rows[$variant->id] ?? null;
            $sent = (int) ($row->sent ?? 0);

            return [
                'variant' => $variant,
                'label' => $variant->label,
                'assigned' => (int) ($row->assigned ?? 0),
                'sent' => $sent,
                'opens' => (int) ($row->opens ?? 0),
                'clicks' => (int) ($row->clicks ?? 0),
                'open_rate' => $sent > 0 ? round(((int) $row->opens / $sent) * 100, 1) : 0.0,
                'click_rate' => $sent > 0 ? round(((int) $row->clicks / $sent) * 100, 1) : 0.0,
                'is_winner' => (int) $campaign->ab_winner_variant_id === (int) $variant->id,
            ];
        })->values();
    }

    /** Writes the per-variant counters back onto the variant rows. */
    public function refreshCounts(Campaign $campaign): void
    {
        foreach ($this->results($campaign) as $row) {
            CampaignVariant::query()->whereKey($row['variant']->id)->update([
                'sent_count' => $row['sent'],
                'unique_opens' => $row['opens'],
                'unique_clicks' => $row['clicks'],
                'updated_at' => now(),
            ]);
        }
    }

    // -------------------------------------------------------------- deciding

    /**
     * Whether this campaign is holding recipients back for a decision.
     */
    public function isAwaitingDecision(Campaign $campaign): bool
    {
        if (! $campaign->is_ab_test || $campaign->ab_decided_at !== null) {
            return false;
        }

        return DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereNull('campaign_variant_id')
            ->whereIn('status', ['pending', 'sending'])
            ->exists();
    }

    /**
     * Whether any of the tested sample has still to go out.
     *
     * Nothing may be judged until it has. Deciding while half of B is queued
     * compares a finished A against a variant that has barely sent — or, at
     * the extreme the audit found, against one that has sent nothing at all,
     * which is not a comparison. The scheduled sweep, the screen and the
     * console command all have to apply this same rule, so there is one copy
     * of it: the console command was the one that did not, and would happily
     * pick a winner the screen refuses with a 422.
     */
    public function sampleStillSending(Campaign $campaign): bool
    {
        return DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('campaign_variant_id')
            ->whereIn('status', ['pending', 'sending'])
            ->exists();
    }

    /**
     * When the decision may be taken.
     *
     * Measured from the last message the SAMPLE actually sent, not from the
     * campaign's start. A big sample can take an hour to go out, and starting
     * the clock at the beginning would judge the last recipients on almost no
     * time at all.
     */
    public function decideAt(Campaign $campaign): ?Carbon
    {
        $lastSent = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('campaign_variant_id')
            ->whereNotNull('sent_at')
            ->max('sent_at');

        if ($lastSent === null) {
            return null;
        }

        return Carbon::parse($lastSent)
            ->addMinutes(max(1, (int) ($campaign->ab_decide_after_minutes ?: 240)));
    }

    /**
     * Decides every test whose window has closed.
     *
     * @return array{decided: int}
     */
    public function decideDue(): array
    {
        $decided = 0;

        $campaigns = Campaign::withoutGlobalScope(AccountScope::class)
            ->where('is_ab_test', true)
            ->whereNull('ab_decided_at')
            ->whereIn('status', ['sending', 'queued', 'completed'])
            ->get();

        foreach ($campaigns as $campaign) {
            if ($this->sampleStillSending($campaign)) {
                continue;
            }

            $at = $this->decideAt($campaign);

            if ($at === null || $at->isFuture()) {
                continue;
            }

            if ($this->decide($campaign) !== null) {
                $decided++;
            }
        }

        return ['decided' => $decided];
    }

    /**
     * Picks the winner, releases the holdback to it, and restarts sending.
     *
     * The claim is guarded so two schedulers deciding at the same moment
     * cannot both release the holdback — which would be harmless for the
     * winner but would send the "decided" notification twice.
     */
    public function decide(Campaign $campaign, ?CampaignVariant $forced = null): ?CampaignVariant
    {
        $this->refreshCounts($campaign);

        $winner = $forced ?: $this->pickWinner($campaign);

        if ($winner === null) {
            return null;
        }

        $claimed = DB::update(
            'UPDATE campaigns SET ab_winner_variant_id = ?, ab_decided_at = ?, updated_at = ?
              WHERE id = ? AND ab_decided_at IS NULL',
            [$winner->id, now(), now(), $campaign->id]
        ) === 1;

        if (! $claimed) {
            return null;
        }

        CampaignVariant::query()->where('campaign_id', $campaign->id)->update(['is_winner' => false]);
        CampaignVariant::query()->whereKey($winner->id)->update(['is_winner' => true]);

        $released = DB::update(
            'UPDATE campaign_recipients
                SET campaign_variant_id = ?, updated_at = ?
              WHERE campaign_id = ? AND campaign_variant_id IS NULL AND status = ?',
            [$winner->id, now(), $campaign->id, 'pending']
        );

        $campaign->refresh();

        // Inside the claim, so it is sent once however many schedulers raced.
        AccountNotifier::send($campaign->account_id, AbTestDecided::for($campaign, $winner, $released));

        if ($released > 0 && in_array($campaign->status, ['queued', 'sending'], true)) {
            // Sending stopped when the holdback was all that was left. It has
            // to be started again by hand — nothing else is watching.
            SendCampaignChunk::dispatch($campaign->id);
        }

        return $winner;
    }

    /**
     * The variant with the best rate on the chosen metric.
     *
     * A tie is broken by the other metric and then by label, so the answer is
     * deterministic. Nothing wins on a sample that delivered nothing.
     */
    public function pickWinner(Campaign $campaign): ?CampaignVariant
    {
        $rows = $this->results($campaign)->filter(fn (array $r) => $r['sent'] > 0);

        if ($rows->isEmpty()) {
            return null;
        }

        $primary = $campaign->ab_winner_metric === 'clicks' ? 'click_rate' : 'open_rate';
        $secondary = $primary === 'click_rate' ? 'open_rate' : 'click_rate';

        return $rows->sortBy([
            fn (array $a, array $b) => $b[$primary] <=> $a[$primary],
            fn (array $a, array $b) => $b[$secondary] <=> $a[$secondary],
            fn (array $a, array $b) => $a['label'] <=> $b['label'],
        ])->first()['variant'];
    }

    /**
     * @return Collection<int, CampaignVariant>
     */
    public function variants(Campaign $campaign): Collection
    {
        return CampaignVariant::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('label')
            ->get();
    }
}
