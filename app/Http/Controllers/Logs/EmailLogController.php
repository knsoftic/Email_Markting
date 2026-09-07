<?php

namespace App\Http\Controllers\Logs;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The sending log.
 *
 * One row per send attempt — campaign mail, automation mail, tests, replies —
 * with the SMTP account that carried it and the provider's answer. It is the
 * screen somebody opens to settle "did this person actually get the email?",
 * so it is read-only by design and never hides a failed attempt.
 *
 * `campaign_logs` is the largest table in the application on a busy account,
 * which shapes every query here:
 *
 *  - the list is ordered by id, not created_at. The id is auto-increment, so
 *    id DESC is the same chronological order, and it is served by the primary
 *    key instead of a filesort over millions of rows.
 *  - the campaign, SMTP account and contact behind each row are eager loaded
 *    in three queries for the whole page, never one per row.
 *  - the status counts come from a single grouped query over the
 *    (account_id, status, created_at) index rather than one COUNT per status.
 *  - the export streams in id-ordered chunks, so its memory cost does not grow
 *    with the size of the result.
 */
class EmailLogController extends Controller
{
    /** Values the `type` enum accepts. Anything else is not a filter. */
    public const TYPES = ['campaign', 'automation', 'transactional', 'test', 'reply'];

    /** Values the `status` enum accepts. */
    public const STATUSES = ['sent', 'failed', 'bounced', 'deferred'];

    /** Keys read off the query string, in one place so the export cannot drift. */
    public const FILTERS = ['q', 'type', 'status', 'campaign', 'from', 'to'];

    /**
     * How many campaigns the filter dropdown offers.
     *
     * A DISTINCT over campaign_logs would be a full scan of the biggest table
     * in the app just to populate a <select>, so the list comes from the
     * campaigns table instead, newest first, and the view says so when it is
     * truncated.
     */
    protected const CAMPAIGN_CHOICES = 100;

    public function index(Request $request): View
    {
        $filters = $request->filters(self::FILTERS);
        $zone = $this->zone($request);

        $logs = $this->query($filters, $zone)
            // Three extra queries for the whole page, not one per row. Trashed
            // rows are included so a log whose campaign or SMTP account was
            // deleted afterwards can still name it.
            ->with([
                'campaign' => fn ($q) => $q->withTrashed()->select('id', 'account_id', 'name', 'deleted_at'),
                'smtpAccount' => fn ($q) => $q->withTrashed()->select('id', 'account_id', 'name', 'deleted_at'),
            ])
            // id DESC, not created_at: it is chronological and it is stable
            // where two rows share a timestamp, which at send rates of
            // thousands a minute is most of them. It became index-backed in
            // 2026_09_07_000193 — before that it said so and filesorted every
            // row in the account to render the first page.
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('logs.index', [
            'logs' => $logs,
            'filters' => $filters,
            'zone' => $zone,
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
            // Counts per status for the current search, so the chips can say
            // how many failures that search actually found. The status filter
            // itself is left out — otherwise every chip but the active one
            // would read zero.
            'statusCounts' => $this->query(array_merge($filters, ['status' => null]), $zone)
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status'),
            'campaigns' => $this->campaignChoices($filters['campaign'] ?? null),
            'campaignTotal' => Campaign::withTrashed()->count(),
            // Only asked when the page came back empty, and only to tell
            // "nothing matches this search" apart from "nothing has been sent
            // from this account yet" — two very different things to say.
            'accountHasLogs' => $logs->isEmpty()
                ? CampaignLog::query()->exists()
                : true,
        ]);
    }

    /**
     * One log row in full.
     *
     * Related rows are loaded withTrashed(): a campaign deleted after the send
     * does not un-send the email, and a log that suddenly cannot say which
     * campaign it belonged to is a log that has lost the answer somebody came
     * here for. The view links only to records that are still live.
     */
    public function show(Request $request, CampaignLog $log): View
    {
        $log->load([
            'campaign' => fn ($q) => $q->withTrashed(),
            'smtpAccount' => fn ($q) => $q->withTrashed(),
            'subscriber' => fn ($q) => $q->withTrashed(),
        ]);

        return view('logs.show', [
            'log' => $log,
            'zone' => $this->zone($request),
        ]);
    }

    /**
     * The filtered log as CSV.
     *
     * Streamed in chunks rather than built in memory: this is the one table
     * where "export everything" can mean several million rows, and holding
     * them to make a string is how an export takes the site down.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->filters(self::FILTERS);
        $zone = $this->zone($request);

        $query = $this->query($filters, $zone)->with([
            'campaign' => fn ($q) => $q->withTrashed()->select('id', 'account_id', 'name', 'deleted_at'),
            'smtpAccount' => fn ($q) => $q->withTrashed()->select('id', 'account_id', 'name', 'deleted_at'),
        ]);

        $filename = 'email-log-'.now()->setTimezone($zone)->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $zone) {
            $out = fopen('php://output', 'w');

            // A BOM makes Excel open UTF-8 subject lines correctly instead of
            // showing mojibake — the same thing SubscriberExporter does.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'ID', 'Recorded at ('.$zone.')', 'Sent at ('.$zone.')', 'Recipient', 'Sender',
                'Subject', 'Type', 'Status', 'SMTP account', 'Campaign', 'Campaign ID',
                'Error', 'SMTP response',
            ]);

            // chunkById, not chunk: it pages with WHERE id > ? instead of an
            // OFFSET that grows more expensive with every chunk.
            $query->chunkById(1000, function ($rows) use ($out, $zone) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map($this->cell(...), [
                        $row->id,
                        $row->created_at?->copy()->setTimezone($zone)->toDateTimeString(),
                        $row->sent_at?->copy()->setTimezone($zone)->toDateTimeString(),
                        $row->recipient_email,
                        $row->sender_email,
                        $row->subject,
                        $row->type,
                        $row->status,
                        $row->smtpAccount?->name,
                        $this->campaignLabel($row),
                        $row->campaign_id,
                        $row->error,
                        $row->response,
                    ]));
                }

                flush();
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    // ------------------------------------------------------------- queries

    /**
     * The filtered query, shared by the list, the counts and the export, so
     * "export this" can never mean something other than what is on screen.
     *
     * Every filter is validated against a known set before it reaches SQL, and
     * every value arrives through $request->filter(), so ?status[]=x is read
     * as "no status given" rather than becoming a 500.
     *
     * @param  array<string, string|null>  $filters
     */
    protected function query(array $filters, string $zone): Builder
    {
        $campaignId = (string) ($filters['campaign'] ?? '');

        return CampaignLog::query()
            ->search($filters['q'] ?? null)
            ->when(
                in_array($filters['type'] ?? '', self::TYPES, true),
                fn (Builder $q) => $q->where('type', $filters['type'])
            )
            ->when(
                in_array($filters['status'] ?? '', self::STATUSES, true),
                fn (Builder $q) => $q->where('status', $filters['status'])
            )
            ->when(
                ctype_digit($campaignId),
                fn (Builder $q) => $q->where('campaign_id', (int) $campaignId)
            )
            // Both bounds are moved into the storage zone before they reach
            // SQL. The column holds app-timezone datetimes, so comparing a
            // date the user picked in their own zone without converting it
            // silently drops the first or last hours of that day.
            ->when(
                $this->day($filters['from'] ?? null, $zone),
                fn (Builder $q, Carbon $day) => $q->where(
                    'created_at', '>=', $day->startOfDay()->setTimezone(config('app.timezone', 'UTC'))
                )
            )
            ->when(
                $this->day($filters['to'] ?? null, $zone),
                fn (Builder $q, Carbon $day) => $q->where(
                    'created_at', '<=', $day->endOfDay()->setTimezone(config('app.timezone', 'UTC'))
                )
            );
    }

    /**
     * Campaigns offered in the filter dropdown.
     *
     * Trashed ones are included: a log row can point at a deleted campaign,
     * and dropping it from the list would make that row unfilterable. If the
     * campaign currently being filtered on falls outside the newest N, it is
     * fetched separately so the select never silently resets itself.
     *
     * @return Collection<int, Campaign>
     */
    protected function campaignChoices(?string $selected): Collection
    {
        $campaigns = Campaign::withTrashed()
            ->orderByDesc('id')
            ->limit(self::CAMPAIGN_CHOICES)
            ->get(['id', 'name', 'deleted_at']);

        if (ctype_digit((string) $selected) && ! $campaigns->contains('id', (int) $selected)) {
            $current = Campaign::withTrashed()->find((int) $selected, ['id', 'name', 'deleted_at']);

            if ($current) {
                $campaigns->prepend($current);
            }
        }

        return $campaigns;
    }

    // ------------------------------------------------------------- helpers

    /**
     * A yyyy-mm-dd bound from the date inputs, read in the account's time zone.
     *
     * The column is stored in UTC and shown in the account's zone, so a filter
     * read in UTC would quietly drop the first or last hours of the day the
     * user actually picked. Anything unparseable is no filter at all rather
     * than an exception on a plain URL.
     */
    protected function day(?string $value, string $zone): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            $day = Carbon::createFromFormat('Y-m-d', $value, $zone);
        } catch (\Throwable) {
            return null;
        }

        return $day ?: null;
    }

    /** The account's time zone, or the app default when it is unusable. */
    protected function zone(Request $request): string
    {
        $zone = (string) ($request->user()?->account?->timezone ?? '');

        return in_array($zone, timezone_identifiers_list(), true)
            ? $zone
            : (string) config('app.timezone', 'UTC');
    }

    /**
     * What the Campaign column says in the export.
     *
     * A soft-deleted campaign still has its name, so the export keeps it and
     * marks it. A campaign whose row is gone for good takes its name with it,
     * and the export says that rather than leaving a blank that reads like
     * "no campaign".
     */
    protected function campaignLabel(CampaignLog $log): string
    {
        if ($log->campaign) {
            return $log->campaign->trashed()
                ? $log->campaign->name.' (deleted)'
                : (string) $log->campaign->name;
        }

        return $log->type === 'campaign' ? 'Campaign no longer exists' : '';
    }

    /**
     * One CSV cell, safe to open in a spreadsheet.
     *
     * A subject line or an SMTP error is attacker-influenced text, and Excel,
     * LibreOffice and Sheets all treat a leading =, +, - or @ as the start of
     * a formula — so "=cmd|'/c calc'!A1" in a bounce message becomes code the
     * moment someone opens the export. Prefixing with a single quote makes the
     * cell literal text; the quote is not part of the value in any of them.
     * Leading tabs and carriage returns are stripped first, because they slip
     * past the check while still leaving the formula character first.
     */
    protected function cell(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        $trimmed = ltrim($value, "\t\r\n");

        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'".$trimmed;
        }

        return $value;
    }
}
