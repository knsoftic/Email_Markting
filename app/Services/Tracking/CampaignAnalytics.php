<?php

namespace App\Services\Tracking;

use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind a campaign report.
 *
 * ── What "sent" means here ──────────────────────────────────────────────────
 * `sent_count` is messages the receiving server ACCEPTED. A bounce increments
 * `bounced_count` and never `sent_count`, so sent is already the delivered
 * figure — every rate below divides by it, which is the convention people
 * expect and the one that does not flatter the sender.
 *
 * ── Rates that are honest about what they are ───────────────────────────────
 * An open is a pixel fetch. It undercounts, because most clients block remote
 * images until the reader allows them, and it overcounts, because Apple Mail
 * Privacy Protection and Gmail's proxy fetch images nobody looked at. This
 * class therefore reports the machine share alongside the open rate rather
 * than quietly folding it in, so a report can say what it actually knows.
 *
 * The click rate has no such problem — a click is a person — which is why
 * click-to-open is worth showing even though it is the ratio of a solid number
 * to a soft one.
 */
class CampaignAnalytics
{
    /**
     * The headline figures.
     *
     * @return array<string, mixed>
     */
    public function summary(Campaign $campaign): array
    {
        $total = (int) $campaign->total_recipients;
        $sent = (int) $campaign->sent_count;
        $bounced = (int) $campaign->bounced_count;
        $failed = (int) $campaign->failed_count;

        $bounceSplit = $this->bounceSplit($campaign);
        $machineOpens = $this->machineOpens($campaign);

        return [
            'recipients' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'bounced' => $bounced,
            'bounced_hard' => $bounceSplit['hard'],
            'bounced_soft' => $bounceSplit['soft'],
            'skipped' => (int) CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('status', 'skipped')->count(),

            'opens' => (int) $campaign->opened_count,
            'unique_opens' => (int) $campaign->unique_opens,
            'clicks' => (int) $campaign->clicked_count,
            'unique_clicks' => (int) $campaign->unique_clicks,
            'unsubscribes' => (int) $campaign->unsubscribed_count,
            'replies' => (int) $campaign->replied_count,

            'open_rate' => $this->rate((int) $campaign->unique_opens, $sent),
            'click_rate' => $this->rate((int) $campaign->unique_clicks, $sent),
            // Of the people who opened, how many went further. The one rate
            // that is really about the content rather than the subject line.
            'click_to_open_rate' => $this->rate((int) $campaign->unique_clicks, (int) $campaign->unique_opens),
            'bounce_rate' => $this->rate($bounced, $sent + $bounced),
            'unsubscribe_rate' => $this->rate((int) $campaign->unsubscribed_count, $sent),
            'reply_rate' => $this->rate((int) $campaign->replied_count, $sent),
            'failure_rate' => $this->rate($failed, $total),

            // Not a metric to boast about — a caveat to print next to the open
            // rate, so nobody reads a machine's fetch as a person's attention.
            'machine_opens' => $machineOpens,
            'machine_open_share' => $this->rate($machineOpens, (int) $campaign->opened_count),

            'delivered_of_attempted' => $this->rate($sent, $sent + $bounced + $failed),
        ];
    }

    /**
     * Opens and clicks over time, ready for a chart.
     *
     * Grouped by hour for the first two days and by day after that: an hourly
     * series over a three-month-old campaign is 2,000 points nobody can read,
     * and a daily series on a campaign sent this morning is one bar.
     *
     * @return array{labels: array<int, string>, opens: array<int, int>, clicks: array<int, int>, unit: string}
     */
    public function timeline(Campaign $campaign, ?string $timezone = null): array
    {
        $timezone = $timezone ?: ($campaign->timezone ?: config('app.timezone'));
        $start = $campaign->started_at ?: $campaign->created_at;

        if (! $start) {
            return ['labels' => [], 'opens' => [], 'clicks' => [], 'unit' => 'hour'];
        }

        $span = $start->diffInHours(now());
        $byHour = $span <= 48;

        $opens = $this->bucket('email_opens', 'opened_at', $campaign->id, $byHour, $timezone);
        $clicks = $this->bucket('email_clicks', 'clicked_at', $campaign->id, $byHour, $timezone);

        // One axis for both series: a chart whose two lines use different
        // buckets is a chart that invents a correlation.
        $labels = collect($opens->keys())->merge($clicks->keys())->unique()->sort()->values();

        return [
            'labels' => $labels->all(),
            'opens' => $labels->map(fn ($k) => (int) ($opens[$k] ?? 0))->all(),
            'clicks' => $labels->map(fn ($k) => (int) ($clicks[$k] ?? 0))->all(),
            'unit' => $byHour ? 'hour' : 'day',
        ];
    }

    /**
     * @return Collection<string, int>
     */
    protected function bucket(string $table, string $column, int $campaignId, bool $byHour, string $timezone): Collection
    {
        // The offset is applied in SQL so the buckets line up with the
        // sender's own day rather than UTC's — a campaign sent at 9pm in
        // Karachi should not report half its opens as "tomorrow".
        $offset = $this->utcOffset($timezone);
        $format = $byHour ? '%Y-%m-%d %H:00' : '%Y-%m-%d';

        return DB::table($table)
            ->where('campaign_id', $campaignId)
            ->selectRaw("DATE_FORMAT(CONVERT_TZ({$column}, '+00:00', ?), ?) AS bucket, COUNT(*) AS aggregate", [$offset, $format])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('aggregate', 'bucket');
    }

    /**
     * The links people actually clicked, best first.
     *
     * @return Collection<int, CampaignLink>
     */
    public function topLinks(Campaign $campaign, int $limit = 20): Collection
    {
        return CampaignLink::query()
            ->where('campaign_id', $campaign->id)
            ->orderByDesc('unique_click_count')
            ->orderByDesc('click_count')
            ->limit($limit)
            ->get();
    }

    /**
     * How the opens were split between real clients and machines.
     */
    public function openSources(Campaign $campaign): Collection
    {
        return DB::table('email_opens')
            ->where('campaign_id', $campaign->id)
            ->selectRaw('COALESCE(device, ?) AS source, COUNT(*) AS aggregate', ['reader'])
            ->groupBy('source')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'source');
    }

    /**
     * The recipient list behind the numbers, filtered.
     *
     * This is what turns a percentage into something a person can act on:
     * "27% opened" is a number, "these 43 people clicked and these 12 bounced"
     * is a next step.
     *
     * @param  array<string, mixed>  $filters
     */
    public function recipients(Campaign $campaign, array $filters = [])
    {
        $engagement = $filters['engagement'] ?? null;

        return CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->with('subscriber:id,email,first_name,last_name,status')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('email', 'like', '%'.$term.'%'))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($engagement === 'opened', fn ($q) => $q->whereNotNull('first_opened_at'))
            ->when($engagement === 'clicked', fn ($q) => $q->whereNotNull('first_clicked_at'))
            ->when($engagement === 'unopened', fn ($q) => $q->whereNull('first_opened_at')->where('status', 'sent'))
            ->when($engagement === 'unsubscribed', fn ($q) => $q->whereNotNull('unsubscribed_at'))
            ->when($engagement === 'bounced', fn ($q) => $q->whereNotNull('bounced_at'))
            ->orderByDesc('click_count')
            ->orderByDesc('open_count')
            ->orderBy('email');
    }

    /**
     * Account-wide figures for the analytics dashboard.
     *
     * @return array<string, mixed>
     */
    public function accountSummary(int $days = 30): array
    {
        $since = now()->subDays($days);

        // 'cancelled' and 'failed' belong here. A campaign cancelled after
        // 500 messages had gone out really did send 500 messages, and leaving
        // it out let the dashboard say "nothing has been sent yet" over real
        // delivered email. The filter is about whether a campaign ever ran,
        // not about how it ended.
        $campaigns = Campaign::query()
            ->whereIn('status', ['sending', 'completed', 'paused', 'cancelled', 'failed'])
            ->where('sent_count', '>', 0)
            ->where(fn ($q) => $q->where('started_at', '>=', $since)->orWhereNull('started_at'))
            ->get();

        $sent = (int) $campaigns->sum('sent_count');
        $bounced = (int) $campaigns->sum('bounced_count');

        return [
            'days' => $days,
            'campaigns' => $campaigns->count(),
            'sent' => $sent,
            'unique_opens' => (int) $campaigns->sum('unique_opens'),
            'unique_clicks' => (int) $campaigns->sum('unique_clicks'),
            'bounced' => $bounced,
            'unsubscribed' => (int) $campaigns->sum('unsubscribed_count'),
            'open_rate' => $this->rate((int) $campaigns->sum('unique_opens'), $sent),
            'click_rate' => $this->rate((int) $campaigns->sum('unique_clicks'), $sent),
            'bounce_rate' => $this->rate($bounced, $sent + $bounced),
            'unsubscribe_rate' => $this->rate((int) $campaigns->sum('unsubscribed_count'), $sent),
        ];
    }

    /**
     * Sends per day across the account, for the dashboard chart.
     *
     * @return array{labels: array<int, string>, sent: array<int, int>, opens: array<int, int>, clicks: array<int, int>}
     */
    public function accountTimeline(int $accountId, int $days = 30, ?string $timezone = null): array
    {
        $timezone = $timezone ?: config('app.timezone');
        $offset = $this->utcOffset($timezone);
        $since = now()->subDays($days)->startOfDay();

        $series = [];

        foreach ([
            'sent' => ['campaign_logs', 'created_at', ['status' => 'sent']],
            'opens' => ['email_opens', 'opened_at', []],
            'clicks' => ['email_clicks', 'clicked_at', []],
        ] as $key => [$table, $column, $where]) {
            $series[$key] = DB::table($table)
                ->where('account_id', $accountId)
                ->where($column, '>=', $since)
                ->where($where)
                ->selectRaw("DATE_FORMAT(CONVERT_TZ({$column}, '+00:00', ?), '%Y-%m-%d') AS bucket, COUNT(*) AS aggregate", [$offset])
                ->groupBy('bucket')
                ->pluck('aggregate', 'bucket');
        }

        // Every day in the window, including the empty ones: a chart that
        // silently skips quiet days makes a gap look like activity.
        $labels = [];
        $cursor = $since->copy();

        while ($cursor->lte(now())) {
            $labels[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return [
            'labels' => $labels,
            'sent' => array_map(fn ($d) => (int) ($series['sent'][$d] ?? 0), $labels),
            'opens' => array_map(fn ($d) => (int) ($series['opens'][$d] ?? 0), $labels),
            'clicks' => array_map(fn ($d) => (int) ($series['clicks'][$d] ?? 0), $labels),
        ];
    }

    /**
     * The best and worst performing campaigns in the window.
     *
     * @return Collection<int, Campaign>
     */
    public function leaderboard(int $days = 30, int $limit = 10): Collection
    {
        return Campaign::query()
            ->where('status', 'completed')
            ->where('sent_count', '>', 0)
            ->where('completed_at', '>=', now()->subDays($days))
            ->orderByRaw('(unique_opens / GREATEST(sent_count, 1)) DESC')
            ->limit($limit)
            ->get();
    }

    // ------------------------------------------------------------- helpers

    /**
     * @return array{hard: int, soft: int}
     */
    protected function bounceSplit(Campaign $campaign): array
    {
        $counts = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('bounce_type')
            ->selectRaw('bounce_type, COUNT(*) AS aggregate')
            ->groupBy('bounce_type')
            ->pluck('aggregate', 'bounce_type');

        return [
            'hard' => (int) ($counts['hard'] ?? 0),
            'soft' => (int) ($counts['soft'] ?? 0),
        ];
    }

    protected function machineOpens(Campaign $campaign): int
    {
        return (int) DB::table('email_opens')
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('device')
            ->count();
    }

    /**
     * A percentage, or 0.0 when there is nothing to divide by.
     *
     * Never returns null: a report full of em-dashes reads as broken, and
     * "0% of nothing" is the truthful answer to a question nobody asked.
     */
    public function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 2) : 0.0;
    }

    /**
     * MySQL's CONVERT_TZ needs an offset unless the timezone tables are
     * loaded, and on a stock XAMPP install they are not — so the offset is
     * computed in PHP and passed in.
     */
    protected function utcOffset(string $timezone): string
    {
        try {
            $minutes = Carbon::now($timezone)->utcOffset();
        } catch (\Throwable) {
            return '+00:00';
        }

        return sprintf('%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);
    }
}
