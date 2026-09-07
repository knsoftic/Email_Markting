<x-admin-layout>
    <x-slot name="header">Queue status</x-slot>

    <x-page-header title="Queue status"
                   subtitle="Bulk sending, imports and mailbox sync all run through this queue.">
        <x-slot name="actions">
            @if ($queue['failed'] > 0)
                <form method="POST" action="{{ route('admin.system.queue.retry') }}">
                    @csrf
                    <button type="submit" class="kn-btn-secondary">Retry all failed</button>
                </form>
                <x-confirm-form :action="route('admin.system.queue.flush')"
                                method="POST"
                                label="Clear failed jobs"
                                message="Permanently delete all failed job records?" />
            @endif
            <a href="{{ route('admin.system.queue') }}" class="kn-btn-primary">Refresh</a>
        </x-slot>
    </x-page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Pending jobs" :value="number_format($queue['pending'])"
                     :meta="$queue['oldest_wait'] ? 'Oldest queued '.$queue['oldest_wait'] : 'Nothing waiting'"
                     :tone="$queue['pending'] > 500 ? 'warning' : 'default'" />
        <x-stat-card label="In progress" :value="number_format($queue['reserved'])"
                     :meta="$queue['worker_seen'] ? 'A worker is processing' : 'No job currently reserved'" />
        <x-stat-card label="Failed jobs" :value="number_format($queue['failed'])"
                     :tone="$queue['failed'] > 0 ? 'danger' : 'default'" meta="Retryable" />
        <x-stat-card label="Open batches" :value="number_format($queue['batches'])" meta="Unfinished campaign batches" />
    </div>

    @if ($queue['pending'] > 0 && ! $queue['worker_seen'])
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Jobs are waiting but nothing is reserved. Make sure a worker is running:
            <code class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs">php artisan queue:work</code>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="kn-card lg:col-span-2 overflow-hidden">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Recent failed jobs</h3></div>

            @if ($failedJobs->isEmpty())
                <x-empty-state title="No failed jobs" message="Everything the queue has processed so far succeeded." />
            @else
                <div class="overflow-x-auto">
                    <table class="kn-table">
                        <thead><tr><th>Job</th><th>Queue</th><th>Failed at</th><th>Error</th><th class="text-right">Action</th></tr></thead>
                        <tbody>
                            @foreach ($failedJobs as $job)
                                <tr>
                                    <td class="font-medium text-ink-900">{{ $job['job'] }}</td>
                                    <td>{{ $job['queue'] }}</td>
                                    <td class="whitespace-nowrap text-xs text-ink-500">{{ $job['failed_at'] }}</td>
                                    <td class="max-w-md">
                                        <p class="truncate text-xs text-red-600" title="{{ $job['exception'] }}">{{ $job['exception'] }}</p>
                                    </td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('admin.system.queue.retry') }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="uuid" value="{{ $job['uuid'] }}">
                                            <button type="submit" class="kn-btn-secondary kn-btn-sm">Retry</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Pending by queue</h3></div>
            <div class="p-5 text-sm">
                @forelse ($pendingByQueue as $name => $count)
                    <div class="flex items-center justify-between border-b border-ink-100 py-2 last:border-0">
                        <span class="text-ink-600">{{ $name }}</span>
                        <span class="font-semibold text-ink-900">{{ number_format($count) }}</span>
                    </div>
                @empty
                    <p class="text-ink-500">The queue is empty.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-admin-layout>
