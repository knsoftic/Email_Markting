<?php

namespace App\Services\Search;

use App\Models\Automation;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Email;
use App\Models\EmailTemplate;
use App\Models\Segment;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\User;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One search box over everything the account owns.
 *
 * ── It reuses each model's own idea of "search" ─────────────────────────────
 * Four models already carry a `scopeSearch`, written when their own list screen
 * was built. This calls those rather than restating them: a global search that
 * matched different fields from the screen it sends you to would answer a
 * question nobody asked, and the two definitions would drift the first time one
 * of them gained a column.
 *
 * ── Why it orders by id and takes very few rows ─────────────────────────────
 * A substring match cannot use an index — `LIKE '%term%'` has to look at rows.
 * What keeps that honest is `ORDER BY id DESC LIMIT n` over the
 * `(account_id, id)` indexes added in 2026_09_07_000193: MySQL walks the
 * account's rows newest-first and stops as soon as it has enough, so a term
 * that matches anything at all is cheap however large the table is.
 *
 * A term that matches NOTHING is the expensive case — it walks the whole
 * account before it can say so — which is why very short terms are refused
 * outright rather than run: "a" would scan everything to return a list nobody
 * can use anyway.
 *
 * ── Permissions decide what is searched, not what is hidden afterwards ──────
 * Each group carries the permission its own screen requires, and a group the
 * user may not open is never queried. Filtering results after the fact would
 * still tell them how many matches exist behind a door they cannot open.
 */
class GlobalSearch
{
    /** Shorter than this and the term is refused rather than run. */
    public const MIN_TERM = 2;

    /** Rows per group on the overview. */
    public const PER_GROUP = 5;

    /** Rows when one group is opened on its own. */
    public const PER_GROUP_EXPANDED = 50;

    /**
     * Everything searchable, in the order it is shown.
     *
     * @return array<string, array{label: string, permission: ?string, noun: string}>
     */
    public function groups(): array
    {
        return [
            'contacts' => ['label' => 'Contacts', 'permission' => 'contacts.view', 'noun' => 'contact'],
            'campaigns' => ['label' => 'Campaigns', 'permission' => 'campaigns.view', 'noun' => 'campaign'],
            'messages' => ['label' => 'Inbox messages', 'permission' => 'inbox.view', 'noun' => 'message'],
            'logs' => ['label' => 'Email log', 'permission' => 'logs.view', 'noun' => 'log entry'],
            'templates' => ['label' => 'Templates', 'permission' => 'templates.view', 'noun' => 'template'],
            'automations' => ['label' => 'Automations', 'permission' => 'automation.view', 'noun' => 'automation'],
            'lists' => ['label' => 'Lists', 'permission' => 'contacts.view', 'noun' => 'list'],
            'segments' => ['label' => 'Segments', 'permission' => 'contacts.view', 'noun' => 'segment'],
            'tags' => ['label' => 'Tags', 'permission' => 'contacts.view', 'noun' => 'tag'],
            'suppressions' => ['label' => 'Do-not-send list', 'permission' => 'contacts.view', 'noun' => 'address'],
        ];
    }

    /**
     * What this actually looks at, in plain words, so the screen can say so
     * rather than leaving somebody to guess why their search missed.
     *
     * @return array<string, string>
     */
    public function fieldNotes(): array
    {
        return [
            'contacts' => 'email address, name, company and phone',
            'campaigns' => 'name and subject line',
            'messages' => 'subject, sender name and address, and the preview line',
            'logs' => 'recipient address, sender address and subject',
            'templates' => 'name and subject line',
            'automations' => 'name and description',
            'lists' => 'name and description',
            'segments' => 'name',
            'tags' => 'name',
            'suppressions' => 'email address',
        ];
    }

    /**
     * Runs the search.
     *
     * @param  string|null  $only  one group key, to open that group on its own
     * @return array{term: string, results: Collection<string, Collection>, counts: array<string, int>, searched: array<int, string>, skipped: array<int, string>}
     */
    public function run(
        User $user,
        ?string $term,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?string $only = null,
    ): array {
        $term = trim((string) $term);

        $results = collect();
        $counts = [];
        $searched = [];
        $skipped = [];

        if (mb_strlen($term) < self::MIN_TERM) {
            return ['term' => $term, 'results' => $results, 'counts' => $counts,
                'searched' => $searched, 'skipped' => $skipped];
        }

        $limit = $only ? self::PER_GROUP_EXPANDED : self::PER_GROUP;

        foreach ($this->groups() as $key => $group) {
            if ($only !== null && $only !== $key) {
                continue;
            }

            // Never queried rather than filtered afterwards: a count of matches
            // behind a door somebody cannot open is still a leak.
            if ($group['permission'] && ! $user->hasPermission($group['permission'])) {
                $skipped[] = $key;

                continue;
            }

            $rows = $this->queryFor($key, $term, $from, $to)
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            $searched[] = $key;
            $counts[$key] = $rows->count();

            if ($rows->isNotEmpty()) {
                $results[$key] = $rows;
            }
        }

        return ['term' => $term, 'results' => $results, 'counts' => $counts,
            'searched' => $searched, 'skipped' => $skipped];
    }

    /**
     * One group's query, already tenant-scoped by the global AccountScope.
     */
    protected function queryFor(string $key, string $term, ?Carbon $from, ?Carbon $to): Builder
    {
        // Escaped, so a term containing % or _ matches itself rather than
        // acting as a wildcard. The model scopes below do the same.
        $like = Search::contains($term);

        $query = match ($key) {
            'contacts' => Subscriber::query()->search($term)
                ->select('id', 'email', 'name', 'first_name', 'last_name', 'company', 'status', 'created_at'),

            'campaigns' => Campaign::query()
                ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('subject', 'like', $like))
                ->select('id', 'name', 'subject', 'status', 'sent_count', 'created_at'),

            'messages' => Email::query()->search($term)
                ->select('id', 'subject', 'from_email', 'from_name', 'folder_type', 'is_read', 'received_at', 'created_at'),

            'logs' => CampaignLog::query()->search($term)
                ->select('id', 'recipient_email', 'sender_email', 'subject', 'status', 'type', 'created_at'),

            'templates' => EmailTemplate::query()
                ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('subject', 'like', $like))
                ->select('id', 'name', 'subject', 'is_system', 'created_at'),

            'automations' => Automation::query()
                ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('description', 'like', $like))
                ->select('id', 'name', 'description', 'status', 'trigger_type', 'created_at'),

            'lists' => SubscriberList::query()
                ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('description', 'like', $like))
                ->select('id', 'name', 'description', 'active_count', 'created_at'),

            'segments' => Segment::query()->where('name', 'like', $like)
                ->select('id', 'name', 'created_at'),

            'tags' => Tag::query()->where('name', 'like', $like)
                ->select('id', 'name', 'subscribers_count', 'created_at'),

            'suppressions' => Suppression::query()->search($term)
                ->select('id', 'email', 'reason', 'created_at'),

            default => throw new \InvalidArgumentException("Unknown search group [{$key}]."),
        };

        return $this->betweenDates($query, $key, $from, $to);
    }

    /**
     * Applies the date range to the column that means "when this happened" for
     * this kind of record.
     *
     * An inbox message is dated by when it ARRIVED, not by when our row was
     * written — those differ by however long the mailbox went unsynced, and a
     * search for "last Tuesday" means the day the mail was sent to them.
     */
    protected function betweenDates(Builder $query, string $key, ?Carbon $from, ?Carbon $to): Builder
    {
        $column = $key === 'messages' ? 'received_at' : 'created_at';
        $table = $query->getModel()->getTable();

        return $query
            ->when($from, fn (Builder $q, Carbon $day) => $q->where(
                $table.'.'.$column, '>=', $day->copy()->startOfDay()->setTimezone(config('app.timezone', 'UTC'))
            ))
            ->when($to, fn (Builder $q, Carbon $day) => $q->where(
                $table.'.'.$column, '<=', $day->copy()->endOfDay()->setTimezone(config('app.timezone', 'UTC'))
            ));
    }
}
