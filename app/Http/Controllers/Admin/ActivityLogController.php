<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = ActivityLog::withoutGlobalScopes()
            ->with(['user', 'account'])
            ->when($request->filter('q'), function ($query, string $term) {
                $like = '%'.$term.'%';
                $query->where(fn ($q) => $q->where('description', 'like', $like)
                    ->orWhere('event', 'like', $like)
                    ->orWhere('ip', 'like', $like));
            })
            ->when($request->filter('event'), fn ($q, $e) => $q->where('event', $e))
            ->when($request->integer('account_id'), fn ($q, $id) => $q->where('account_id', $id))
            // $request->date() throws on anything it cannot parse, so
            // ?from=hello answered with a 500 instead of a page. A date nobody
            // can read is not a filter — it is dropped.
            ->when($this->day($request->filter('from')), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($this->day($request->filter('to')), fn ($q, $to) => $q->where('created_at', '<=', $to->endOfDay()))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin.activity.index', [
            'logs' => $logs,
            'events' => ActivityLog::withoutGlobalScopes()
                ->select('event')
                ->distinct()
                ->orderBy('event')
                ->pluck('event'),
            'filters' => $request->filters(['q', 'event', 'account_id', 'from', 'to']),
        ]);
    }

    /**
     * A date the operator typed, or null when it is not one.
     *
     * `$request->date()` throws `InvalidFormatException` on unparseable input,
     * which reached the user as a 500. A malformed date in a URL is ordinary —
     * a stale bookmark, a hand-edited link — and the right answer is to show
     * the page without that filter.
     */
    protected function day(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
