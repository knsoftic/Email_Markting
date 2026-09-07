<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
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
            ->when($request->date('from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->date('to'), fn ($q, $to) => $q->where('created_at', '<=', $to->endOfDay()))
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
}
