<?php

namespace App\Services\Automation;

use App\Models\Automation;
use App\Models\Campaign;
use App\Models\Scopes\AccountScope;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The two triggers that are not events.
 *
 * `campaign_opened` happens to somebody; "did NOT open" happens to nobody. It
 * is the absence of an event, and an absence can only be noticed by looking —
 * so these two are swept on a schedule rather than fired from the code path
 * that caused them.
 *
 * ── Why every sweep asks "does this contact already have a run?" ────────────
 * A sweep repeats. Where an event trigger fires once and relies on the unique
 * index to reject a duplicate, a sweep would present the same person every
 * minute for as long as they match — and on an automation with `allow_reentry`
 * the enroller would obligingly re-arm them each time, mailing somebody the
 * same sequence over and over. So the sweep excludes anybody who already has a
 * run, and re-entry stays what it is meant to be: something a NEW event
 * triggers, not something a clock does.
 */
class AutomationSweeper
{
    /** Contacts enrolled per automation per sweep. */
    public const CHUNK = 500;

    public function __construct(protected AutomationTrigger $trigger) {}

    /**
     * @return array{swept: int, enrolled: int}
     */
    public function sweep(): array
    {
        $result = ['swept' => 0, 'enrolled' => 0];

        $automations = Automation::withoutGlobalScope(AccountScope::class)
            ->where('status', 'active')
            ->whereIn('trigger_type', ['campaign_not_opened', 'specific_date'])
            ->whereHas('steps')
            ->get();

        foreach ($automations as $automation) {
            try {
                $result['enrolled'] += $automation->trigger_type === 'campaign_not_opened'
                    ? $this->sweepNotOpened($automation)
                    : $this->sweepDate($automation);

                $result['swept']++;
            } catch (Throwable $e) {
                Log::warning('Automation sweep failed', [
                    'automation_id' => $automation->id,
                    'trigger' => $automation->trigger_type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * "They were sent it and did not open it within N hours."
     *
     * The window is measured from each recipient's own `sent_at`, not from the
     * campaign's completion. A large campaign can take hours to go out, and
     * measuring from the end would give the first recipient a longer grace
     * period than the last — the same person judged differently depending on
     * where in the queue they happened to sit.
     */
    protected function sweepNotOpened(Automation $automation): int
    {
        $config = (array) ($automation->trigger_config ?? []);
        $campaignId = (int) ($config['campaign_id'] ?? 0);
        $hours = max(1, (int) ($config['after_hours'] ?? 48));

        if ($campaignId === 0) {
            // Without a campaign this cannot mean anything: "did not open"
            // needs something specific not to have been opened.
            return 0;
        }

        $campaign = Campaign::withoutGlobalScope(AccountScope::class)
            ->where('account_id', $automation->account_id)
            ->find($campaignId);

        if ($campaign === null) {
            return 0;
        }

        $cutoff = now()->subHours($hours);

        $ids = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->where('status', 'sent')
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $cutoff)
            ->whereNull('first_opened_at')
            ->whereNotNull('subscriber_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('automation_runs')
                ->where('automation_runs.automation_id', $automation->id)
                ->whereColumn('automation_runs.subscriber_id', 'campaign_recipients.subscriber_id'))
            ->limit(self::CHUNK)
            ->pluck('subscriber_id')
            ->map('intval')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return $this->trigger->enrolNow($automation, $ids, [
            'trigger' => 'campaign_not_opened',
            'campaign_id' => $campaign->id,
            'after_hours' => $hours,
        ]);
    }

    /**
     * "On this date, start everybody on these lists."
     *
     * One-shot by design. Once the date has passed and the audience is in, the
     * automation is marked completed rather than left active — an active date
     * trigger whose date is in the past would enrol every contact added
     * afterwards, weeks late, for a day that has been and gone.
     */
    protected function sweepDate(Automation $automation): int
    {
        $config = (array) ($automation->trigger_config ?? []);
        $date = trim((string) ($config['date'] ?? ''));

        if ($date === '') {
            return 0;
        }

        try {
            $when = \Illuminate\Support\Carbon::parse(
                $date.' '.trim((string) ($config['time'] ?? '00:00')),
                $automation->account?->timezone ?: config('app.timezone')
            );
        } catch (Throwable) {
            return 0;
        }

        if ($when->isFuture()) {
            return 0;
        }

        $listIds = array_filter(array_map('intval', (array) ($config['list_ids'] ?? [])));

        $query = Subscriber::withoutGlobalScope(AccountScope::class)
            ->where('account_id', $automation->account_id)
            ->where('status', 'active')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('automation_runs')
                ->where('automation_runs.automation_id', $automation->id)
                ->whereColumn('automation_runs.subscriber_id', 'subscribers.id'))
            // The suppression check is here as well as in the enroller, and it
            // has to be. A sweep completes itself when it selects nobody, so a
            // contact this query offers but the enroller always refuses would
            // be offered again every minute — the automation would never reach
            // the end of its audience, never mark itself completed, and would
            // then go on enrolling contacts added weeks after its date had
            // passed. The query must select exactly who can actually enter.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('suppressions')
                ->whereColumn('suppressions.email', 'subscribers.email')
                ->where('suppressions.account_id', $automation->account_id));

        if ($listIds !== []) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('list_subscriber')
                ->whereColumn('list_subscriber.subscriber_id', 'subscribers.id')
                ->whereIn('list_subscriber.subscriber_list_id', $listIds));
        }

        $ids = $query->limit(self::CHUNK)->pluck('id')->map('intval')->all();

        if ($ids === []) {
            // Everybody who was going to enter has entered. The date has
            // passed and the automation has done its job.
            $automation->forceFill(['status' => 'completed'])->save();

            return 0;
        }

        return $this->trigger->enrolNow($automation, $ids, [
            'trigger' => 'specific_date',
            'date' => $when->toDateTimeString(),
        ]);
    }
}
