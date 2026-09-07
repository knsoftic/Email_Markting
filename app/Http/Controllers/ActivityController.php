<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The account's own audit trail.
 *
 * ── Why this screen has to exist ────────────────────────────────────────────
 * Every module already writes here — eighty-six distinct events across
 * seventeen areas — and until now the only screen that could read any of it was
 * the super admin's. An account whose contact list was emptied, whose campaign
 * was cancelled or whose SMTP password was changed had no way to find out who
 * did it, while the platform operator could see all of it. An audit trail only
 * the vendor can read is not an audit trail for the customer.
 *
 * ── Categories rather than a list of every event ────────────────────────────
 * The admin screen offers a dropdown built from `SELECT DISTINCT event`, which
 * reads the whole table on every page load and, at eighty-six options, is a
 * list nobody scans anyway. This filters on the part before the dot instead:
 * seventeen choices, no query to build them, and `event LIKE 'campaign.%'` is a
 * prefix match the (event, created_at) index can serve.
 */
class ActivityController extends Controller
{
    /**
     * The areas an account can filter by, and what each one covers.
     *
     * Fixed rather than discovered: building it from the data would mean an
     * expensive DISTINCT for a list that changes only when this application
     * does, and would also hide an area from an account simply because nothing
     * had happened in it yet.
     *
     * @var array<string, string>
     */
    public const AREAS = [
        'campaign' => 'Campaigns',
        'automation' => 'Automations',
        'subscriber' => 'Contacts',
        'contacts' => 'Imports and exports',
        'list' => 'Lists',
        'segment' => 'Segments',
        'tag' => 'Tags',
        'custom_field' => 'Custom fields',
        'template' => 'Templates',
        'smtp' => 'SMTP accounts',
        'mailbox' => 'Mailboxes',
        'inbox' => 'Inbox',
        'replies' => 'Campaign replies',
        'team' => 'Team and roles',
        'profile' => 'Profiles',
        'auth' => 'Sign in and out',
        'account' => 'Account',
        'admin' => 'Changes made by KN Softic',
    ];

    public function index(Request $request): View
    {
        $zone = $this->zone($request);

        $filters = $request->filters(['q', 'area', 'user', 'from', 'to']);

        $area = in_array($filters['area'] ?? '', array_keys(self::AREAS), true)
            ? $filters['area']
            : null;

        $from = $this->day($filters['from'] ?? null, $zone);
        $to = $this->day($filters['to'] ?? null, $zone);

        // Typed backwards is the commonest way to get an empty page that looks
        // like a fault. Swapping is what the person meant.
        if ($from && $to && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $userId = ctype_digit((string) ($filters['user'] ?? '')) ? (int) $filters['user'] : null;

        $logs = ActivityLog::query()
            ->with(['user:id,name,email'])
            ->when($filters['q'] ?? null, function (Builder $query, string $term) {
                $like = Search::contains($term);

                $query->where(fn (Builder $q) => $q->where('description', 'like', $like)
                    ->orWhere('event', 'like', $like));
            })
            // A prefix match, so the (event, created_at) index can serve it.
            ->when($area, fn (Builder $q, string $a) => $q->where('event', 'like', $a.'.%'))
            ->when($userId, fn (Builder $q, int $id) => $q->where('user_id', $id))
            ->when($from, fn (Builder $q, Carbon $day) => $q->where(
                'created_at', '>=', $day->copy()->startOfDay()->setTimezone(config('app.timezone', 'UTC'))
            ))
            ->when($to, fn (Builder $q, Carbon $day) => $q->where(
                'created_at', '<=', $day->copy()->endOfDay()->setTimezone(config('app.timezone', 'UTC'))
            ))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('activity.index', [
            'logs' => $logs,
            'areas' => self::AREAS,
            'area' => $area,
            /*
             * Filtered by account_id explicitly, and it has to be: User is one
             * of the few models that does NOT use the BelongsToAccount trait,
             * so there is no AccountScope on it and `User::query()` returns
             * every user of every account. Without this the "Done by" dropdown
             * listed the names of other customers' staff.
             *
             * Current members only. Somebody who has left still appears in the
             * rows they left behind — their history does not vanish with them —
             * but offering them in a filter of people who work here would not
             * be true.
             */
            'people' => User::query()
                ->where('account_id', $request->user()->account_id)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'filters' => [
                'q' => $filters['q'] ?? null,
                'area' => $area,
                'user' => $userId,
                'from' => $from?->format('Y-m-d'),
                'to' => $to?->format('Y-m-d'),
            ],
            'zone' => $zone,
        ]);
    }

    protected function zone(Request $request): string
    {
        $zone = (string) ($request->user()?->account?->timezone ?? '');

        return in_array($zone, timezone_identifiers_list(), true)
            ? $zone
            : (string) config('app.timezone', 'UTC');
    }

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
}
