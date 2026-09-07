<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\Tracking\CampaignAnalytics;
use App\Services\Tracking\TrackingLinkRewriter;
use App\Services\Tracking\TrackingRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    /** Windows the dashboard offers. Anything else falls back to 30. */
    protected const WINDOWS = [7, 30, 90];

    public function __construct(
        protected CampaignAnalytics $analytics,
        protected TrackingRecorder $recorder,
        protected TrackingLinkRewriter $rewriter,
    ) {}

    /** The account-wide dashboard. */
    public function index(Request $request): View
    {
        $days = (int) ($request->filter('days') ?? 30);
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;

        return view('analytics.index', [
            'days' => $days,
            'windows' => self::WINDOWS,
            'summary' => $this->analytics->accountSummary($days),
            'timeline' => $this->analytics->accountTimeline($request->user()->account_id, $days),
            'leaderboard' => $this->analytics->leaderboard($days),
            // Cancelled included: a campaign stopped half way still sent what
            // it sent, and omitting it made the dashboard claim an account had
            // sent nothing while its contacts held the proof.
            'recent' => Campaign::query()
                ->whereIn('status', ['sending', 'completed', 'paused', 'failed', 'cancelled'])
                ->latest('started_at')
                ->limit(8)
                ->get(),
        ]);
    }

    /** One campaign's report. */
    public function campaign(Request $request, Campaign $campaign): View
    {
        $filters = $request->filters(['q', 'status', 'engagement']);

        return view('analytics.campaign', [
            'campaign' => $campaign,
            'summary' => $this->analytics->summary($campaign),
            'timeline' => $this->analytics->timeline($campaign),
            'links' => $this->analytics->topLinks($campaign),
            'openSources' => $this->analytics->openSources($campaign),
            'filters' => $filters,
            // Replies are counted from Phase 9 onwards, but only for mail that
            // lands in a mailbox this app actually polls. Whether one is
            // connected is the difference between "nobody replied" and "we
            // cannot see replies", and the screen has to be able to say which.
            'hasMailbox' => \App\Models\Mailbox::query()->where('is_active', true)->exists(),
            'recipients' => $this->analytics->recipients($campaign, $filters)
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    /**
     * Recomputes the campaign's counters from the recorded events.
     *
     * The counters are maintained live, so this is not part of the normal
     * flow — it is the repair path for when they drift, and it is offered in
     * the UI because the alternative is a support ticket and a DB console.
     */
    public function rebuild(Campaign $campaign): RedirectResponse
    {
        $this->recorder->refreshCounts($campaign->id);
        $this->rewriter->refreshLinkCounts($campaign);

        return back()->with('success', 'Recalculated from the recorded opens and clicks.');
    }

    /**
     * The recipient drill-down as CSV.
     *
     * Streamed rather than built in memory: a completed campaign can have
     * 100,000 recipient rows, and holding them all to make a string is how an
     * export takes the site down.
     */
    public function export(Request $request, Campaign $campaign): StreamedResponse
    {
        $filters = $request->filters(['q', 'status', 'engagement']);
        $query = $this->analytics->recipients($campaign, $filters);

        $name = 'campaign-'.$campaign->id.'-recipients.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Email', 'Name', 'Status', 'Sent at', 'Opens', 'First opened', 'Last opened',
                'Clicks', 'First clicked', 'Unsubscribed at', 'Bounce type', 'Error',
            ]);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->email,
                        $row->name,
                        $row->status,
                        $row->sent_at?->toDateTimeString(),
                        $row->open_count,
                        $row->first_opened_at?->toDateTimeString(),
                        $row->last_opened_at?->toDateTimeString(),
                        $row->click_count,
                        $row->first_clicked_at?->toDateTimeString(),
                        $row->unsubscribed_at?->toDateTimeString(),
                        $row->bounce_type,
                        $row->error,
                    ]);
                }
            });

            fclose($out);
        }, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
