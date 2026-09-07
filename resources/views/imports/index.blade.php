<x-app-layout>
    <x-slot name="header">Imports</x-slot>

    @php
        /*
         * Statuses a row can be in, mapped to what the user can still do:
         *   mapping    — uploaded, columns not confirmed yet (resume at step 2)
         *   pending    — queued, waiting for a worker
         *   processing — a worker is streaming rows right now
         *   completed / failed / cancelled — finished, report available
         */
        $runningStatuses = ['pending', 'processing'];

        $statusHint = [
            'mapping' => 'Waiting for you to confirm the column mapping',
            'pending' => 'Queued — a worker will pick this up shortly',
            'processing' => 'Reading rows now',
            'completed' => 'Finished',
            'failed' => 'Stopped with an error',
            'cancelled' => 'Cancelled before it finished',
        ];

        $totalImported = $imports->sum('imported_count');

        // The contacts screen needs its own permission, so the back link is
        // only offered to someone who can actually open it.
        $canViewContacts = (bool) auth()->user()?->hasPermission('contacts.view');
    @endphp

    <x-page-header title="Imports"
                   :subtitle="$imports->total() > 0
                        ? number_format($imports->total()).' import '.\Illuminate\Support\Str::plural('run', $imports->total()).' · '.number_format($totalImported).' contacts added by the runs on this page'
                        : 'Bring contacts in from a CSV file'"
                   :back="$canViewContacts ? route('subscribers.index') : null">
        <x-slot name="actions">
            @permission('contacts.export')
                <a href="{{ route('exports.index') }}" class="kn-btn-secondary">Export</a>
            @endpermission
            @permission('contacts.import')
                <a href="{{ route('imports.create') }}" class="kn-btn-primary">New import</a>
            @endpermission
        </x-slot>
    </x-page-header>

    <div class="kn-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Run by</th>
                        <th>Status</th>
                        <th class="min-w-[11rem]">Progress</th>
                        <th class="min-w-[13rem]">Result</th>
                        <th>Uploaded</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($imports as $import)
                        @php
                            $isRunning = in_array($import->status, $runningStatuses, true);
                            $percent = $import->progressPercent();
                            $errorCount = count($import->error_rows ?? []);
                        @endphp
                        <tr>
                            <td>
                                <div class="min-w-0 max-w-[16rem]">
                                    <a href="{{ route('imports.show', $import) }}"
                                       class="block truncate font-medium text-ink-900 hover:text-brand-600"
                                       title="{{ $import->original_name }}">
                                        {{ $import->original_name }}
                                    </a>
                                    <span class="block text-xs text-ink-500">
                                        {{ number_format((int) $import->total_rows) }} {{ \Illuminate\Support\Str::plural('row', (int) $import->total_rows) }} in the file
                                    </span>
                                </div>
                            </td>

                            <td class="whitespace-nowrap text-ink-600">{{ $import->user?->name ?? 'Deleted user' }}</td>

                            <td>
                                <x-status-badge :status="$import->status" />
                                <span class="mt-1 block max-w-[12rem] text-xs text-ink-500">
                                    {{ $statusHint[$import->status] ?? 'Unknown state' }}
                                </span>
                            </td>

                            <td>
                                @if ($isRunning)
                                    <div class="h-2 w-full overflow-hidden rounded-full bg-ink-200">
                                        <div class="h-2 rounded-full bg-brand-600" style="width: {{ $percent }}%"></div>
                                    </div>
                                    <span class="mt-1 block text-xs text-ink-500">
                                        {{ number_format((int) $import->processed_rows) }} of {{ number_format((int) $import->total_rows) }} rows · {{ $percent }}%
                                    </span>
                                @elseif ($import->status === 'cancelled')
                                    <span class="text-xs text-ink-500">
                                        Stopped after {{ number_format((int) $import->processed_rows) }} {{ \Illuminate\Support\Str::plural('row', (int) $import->processed_rows) }}
                                    </span>
                                @elseif ($import->status === 'mapping')
                                    <span class="text-xs text-ink-400">Not started</span>
                                @else
                                    <span class="text-xs text-ink-500">
                                        {{ number_format((int) $import->processed_rows) }} {{ \Illuminate\Support\Str::plural('row', (int) $import->processed_rows) }} read
                                        @if ($import->completed_at)
                                            · finished {{ $import->completed_at->diffForHumans() }}
                                        @endif
                                    </span>
                                @endif
                            </td>

                            <td>
                                @if ($import->status === 'mapping')
                                    <span class="text-xs text-ink-400">—</span>
                                @else
                                    <div class="flex flex-wrap items-center gap-1">
                                        <span class="kn-badge-green" title="New contacts created">
                                            {{ number_format((int) $import->imported_count) }} new
                                        </span>
                                        @if ((int) $import->updated_count > 0)
                                            <span class="kn-badge-blue" title="Existing contacts updated">
                                                {{ number_format((int) $import->updated_count) }} updated
                                            </span>
                                        @endif
                                        @if ((int) $import->duplicate_count > 0)
                                            <span class="kn-badge-gray" title="Already in your account, left untouched">
                                                {{ number_format((int) $import->duplicate_count) }} duplicate
                                            </span>
                                        @endif
                                        @if ((int) $import->invalid_count > 0)
                                            <span class="kn-badge-red" title="Rows with a missing or malformed email address">
                                                {{ number_format((int) $import->invalid_count) }} invalid
                                            </span>
                                        @endif
                                        @if ((int) $import->suppressed_count > 0)
                                            <span class="kn-badge-amber" title="Imported but kept unsubscribed — they are on your suppression list">
                                                {{ number_format((int) $import->suppressed_count) }} suppressed
                                            </span>
                                        @endif
                                    </div>

                                    @if ($import->last_error)
                                        <span class="mt-1 block max-w-[16rem] truncate text-xs text-amber-700"
                                              title="{{ $import->last_error }}">
                                            {{ $import->last_error }}
                                        </span>
                                    @elseif ($errorCount > 0)
                                        <span class="mt-1 block text-xs text-ink-500">
                                            {{ number_format($errorCount) }} error {{ \Illuminate\Support\Str::plural('row', $errorCount) }} recorded
                                        </span>
                                    @endif
                                @endif
                            </td>

                            <td class="whitespace-nowrap text-xs text-ink-500"
                                title="{{ $import->created_at?->format('D, d M Y H:i') }}">
                                {{ $import->created_at?->diffForHumans() ?? '—' }}
                            </td>

                            <td class="text-right">
                                <div class="flex justify-end gap-1.5">
                                    @if ($import->status === 'mapping')
                                        <a href="{{ route('imports.map', $import) }}" class="kn-btn-primary kn-btn-sm">Continue</a>
                                    @endif

                                    <a href="{{ route('imports.show', $import) }}" class="kn-btn-secondary kn-btn-sm">View</a>

                                    {{-- Deleting removes the uploaded CSV from disk, so it is never offered
                                         while a worker is still streaming that file. Cancel it first, from
                                         the import's own screen. --}}
                                    @if (! $isRunning)
                                        @permission('contacts.import')
                                            <x-confirm-form :action="route('imports.destroy', $import)"
                                                            label="Delete"
                                                            :message="'Delete the import record for '.$import->original_name.'? The contacts it already imported are kept — only this record and the uploaded file are removed.'" />
                                        @endpermission
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-empty-state title="No imports yet"
                                               message="Upload a CSV to bring a whole contact list in at once. You choose which column goes where before anything is saved.">
                                    <x-slot name="action">
                                        @permission('contacts.import')
                                            <a href="{{ route('imports.create') }}" class="kn-btn-primary">Import a CSV</a>
                                        @endpermission
                                    </x-slot>
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($imports->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $imports->links() }}</div>
        @endif
    </div>
</x-app-layout>
