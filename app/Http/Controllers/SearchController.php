<?php

namespace App\Http\Controllers;

use App\Services\Search\GlobalSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The one search box.
 *
 * No permission middleware on the route, deliberately: the service decides
 * group by group what this user may look at, and a slug on the route would
 * either have to be the union of all of them — refusing somebody who can see
 * contacts because they cannot see the inbox — or the intersection, which is
 * nothing at all.
 */
class SearchController extends Controller
{
    public function __construct(protected GlobalSearch $search) {}

    public function index(Request $request): View
    {
        $zone = $this->zone($request);

        $term = $request->filter('q');
        $only = $request->filter('in');
        $from = $this->day($request->filter('from'), $zone);
        $to = $this->day($request->filter('to'), $zone);

        // An unknown group name is dropped rather than 404'd: it arrives from a
        // hand-edited URL or a stale bookmark, and the useful answer is the
        // search across everything, not an error page.
        if ($only !== null && ! array_key_exists($only, $this->search->groups())) {
            $only = null;
        }

        // A range typed backwards is the commonest way to get an empty result
        // that looks like a bug. Swapping is what the person meant.
        if ($from && $to && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $outcome = $this->search->run($request->user(), $term, $from, $to, $only);

        return view('search.index', [
            'term' => $outcome['term'],
            'results' => $outcome['results'],
            'counts' => $outcome['counts'],
            'searched' => $outcome['searched'],
            'skipped' => $outcome['skipped'],
            'groups' => $this->search->groups(),
            'fieldNotes' => $this->search->fieldNotes(),
            'only' => $only,
            'filters' => [
                'q' => $outcome['term'],
                'in' => $only,
                'from' => $from?->format('Y-m-d'),
                'to' => $to?->format('Y-m-d'),
            ],
            'minTerm' => GlobalSearch::MIN_TERM,
            'perGroup' => $only ? GlobalSearch::PER_GROUP_EXPANDED : GlobalSearch::PER_GROUP,
            'zone' => $zone,
        ]);
    }

    /** The account's own timezone, so a date the user picks means their day. */
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
