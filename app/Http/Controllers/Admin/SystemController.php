<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SystemController extends Controller
{
    public function __construct(protected SystemHealthService $health) {}

    public function healthReport(): View
    {
        return view('admin.system.health', [
            'checks' => $this->health->checks(),
            'environment' => [
                'PHP version' => PHP_VERSION,
                'Laravel version' => app()->version(),
                'Environment' => app()->environment(),
                'Database' => config('database.default').' · '.config('database.connections.'.config('database.default').'.database'),
                'Queue driver' => config('queue.default'),
                'Cache driver' => config('cache.default'),
                'Session driver' => config('session.driver'),
                'Mail driver' => config('mail.default'),
                'Timezone' => config('app.timezone'),
                'Debug mode' => config('app.debug') ? 'On' : 'Off',
            ],
        ]);
    }

    public function queue(): View
    {
        $failed = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);

                return [
                    'id' => $job->id,
                    'uuid' => $job->uuid,
                    'queue' => $job->queue,
                    'job' => $payload['displayName'] ?? 'Unknown job',
                    'failed_at' => $job->failed_at,
                    'exception' => \Illuminate\Support\Str::limit((string) $job->exception, 300),
                ];
            });

        $pendingByQueue = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('queue')
            ->pluck('aggregate', 'queue');

        return view('admin.system.queue', [
            'queue' => $this->health->queue(),
            'failedJobs' => $failed,
            'pendingByQueue' => $pendingByQueue,
        ]);
    }

    public function retryFailed(Request $request): RedirectResponse
    {
        $uuid = $request->input('uuid');

        Artisan::call('queue:retry', ['id' => $uuid ? [$uuid] : ['all']]);

        ActivityLogger::log('admin.queue.retry', $uuid ? "Retried failed job {$uuid}" : 'Retried all failed jobs');

        return back()->with('success', $uuid ? 'Job queued for retry.' : 'All failed jobs queued for retry.');
    }

    public function flushFailed(): RedirectResponse
    {
        $count = DB::table('failed_jobs')->count();

        Artisan::call('queue:flush');

        ActivityLogger::log('admin.queue.flush', "Cleared {$count} failed jobs");

        return back()->with('success', "Cleared {$count} failed jobs.");
    }

    public function clearCaches(): RedirectResponse
    {
        Artisan::call('optimize:clear');

        ActivityLogger::log('admin.system.cache_cleared', 'Cleared application caches');

        return back()->with('success', 'Config, route, view and application caches cleared.');
    }
}
