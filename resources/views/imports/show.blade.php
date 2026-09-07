<x-app-layout>
    <x-slot name="header">Import</x-slot>

    @php
        $mapping = $import->mapping ?? [];
        $options = $import->options ?? [];
        $errorRows = array_values($import->error_rows ?? []);
        $errorCount = count($errorRows);

        $running = in_array($import->status, ['pending', 'processing'], true);
        $finished = in_array($import->status, ['completed', 'failed', 'cancelled'], true);
        $notStarted = $import->status === 'mapping';
        $hasMapping = ! empty($mapping);

        /** Turns a stored mapping target into the label the user picked. */
        $fieldLabel = function ($target) use ($targets) {
            if (str_starts_with((string) $target, 'custom:')) {
                return 'Custom field · '.substr((string) $target, 7);
            }

            return $targets[$target] ?? (string) $target;
        };

        $duplicateLabel = ($options['duplicates'] ?? 'skip') === 'update'
            ? 'Update the contact with the values in the file'
            : 'Skip it and leave the existing contact untouched';

        $consentLabels = ['explicit' => 'Explicit', 'implied' => 'Implied', 'unknown' => 'Unknown'];

        // Names are not passed in, so they are resolved here — two extra
        // queries, and only when lists or tags were actually chosen. Both
        // models are account-scoped, so this cannot leak another tenant's rows.
        $listNames = ! empty($import->list_ids)
            ? \App\Models\SubscriberList::whereIn('id', $import->list_ids)->orderBy('name')->pluck('name')->all()
            : [];
        $tagNames = ! empty($import->tag_ids)
            ? \App\Models\Tag::whereIn('id', $import->tag_ids)->orderBy('name')->pluck('name')->all()
            : [];

        $initialProgress = [
            'status' => $import->status,
            'total_rows' => (int) $import->total_rows,
            'processed_rows' => (int) $import->processed_rows,
            'percent' => $import->progressPercent(),
            'imported' => (int) $import->imported_count,
            'updated' => (int) $import->updated_count,
            'duplicates' => (int) $import->duplicate_count,
            'invalid' => (int) $import->invalid_count,
            'suppressed' => (int) $import->suppressed_count,
            'errors' => $errorCount,
            'last_error' => $import->last_error,
            'finished' => false,
        ];

        $steps = [
            1 => ['label' => 'Upload the file', 'hint' => $import->original_name],
            2 => ['label' => 'Map the columns', 'hint' => count($mapping).' '.\Illuminate\Support\Str::plural('column', count($mapping)).' mapped'],
            3 => ['label' => 'Review and import', 'hint' => $finished ? 'Report below' : ($running ? 'Running now' : 'Ready when you are')],
        ];
        $currentStep = 3;

        $subtitleParts = [
            number_format((int) $import->total_rows).' '.\Illuminate\Support\Str::plural('row', (int) $import->total_rows),
            'uploaded by '.($import->user?->name ?? 'a deleted user'),
            $import->created_at?->format('j M Y, H:i') ?? '',
        ];
    @endphp

    <x-page-header :title="$import->original_name"
                   :subtitle="implode(' · ', array_filter($subtitleParts))"
                   :back="route('imports.index')">
        <x-slot name="actions">
            <x-status-badge :status="$import->status" />

            @if ($notStarted)
                <a href="{{ route('imports.map', $import) }}" class="kn-btn-secondary">Edit mapping</a>
            @endif

            @if (! $running)
                @permission('contacts.import')
                    <x-confirm-form :action="route('imports.destroy', $import)"
                                    label="Delete record"
                                    button-class="kn-btn-ghost"
                                    :message="'Delete the import record for '.$import->original_name.'? The contacts it already imported are kept — only this record and the uploaded file are removed.'" />
                @endpermission
            @endif
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------ step indicator --}}
    <ol class="mb-6 grid gap-3 sm:grid-cols-3">
        @foreach ($steps as $number => $step)
            @php $state = $number === $currentStep ? 'current' : 'done'; @endphp
            <li class="flex items-center gap-3 rounded-lg border px-4 py-3
                       {{ $state === 'current' ? 'border-brand-200 bg-brand-50' : 'border-ink-200 bg-white' }}">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-semibold
                             {{ $state === 'current' ? 'bg-brand-600 text-white' : 'bg-emerald-100 text-emerald-700' }}">
                    {{ $state === 'current' ? $number : '✓' }}
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium {{ $state === 'current' ? 'text-brand-800' : 'text-ink-700' }}">
                        {{ $step['label'] }}
                    </span>
                    <span class="block truncate text-xs {{ $state === 'current' ? 'text-brand-700' : 'text-ink-500' }}"
                          title="{{ $step['hint'] }}">
                        {{ $step['hint'] }}
                    </span>
                </span>
            </li>
        @endforeach
    </ol>

    {{-- --------------------------------------------------- nothing mapped yet --}}
    @if (! $hasMapping)
        <div class="kn-card mb-6 border-amber-200">
            <div class="kn-card-body sm:flex sm:items-center sm:justify-between sm:gap-4">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-ink-900">This file has no column mapping yet</h3>
                    <p class="mt-1 text-sm text-ink-600">
                        @if ($notStarted)
                            Tell us which column holds the email address before the import can run.
                        @else
                            This upload was {{ $import->status }} before the columns were confirmed, so nothing
                            was read from it. Upload the file again to start over.
                        @endif
                    </p>
                </div>

                {{-- The mapping screen only accepts an import that is still in the mapping
                     or pending state, so anything past that is sent back to the upload step
                     rather than at a link that would 404. --}}
                @if ($notStarted)
                    <a href="{{ route('imports.map', $import) }}" class="kn-btn-primary mt-3 shrink-0 sm:mt-0">Map the columns</a>
                @else
                    <a href="{{ route('imports.create') }}" class="kn-btn-primary mt-3 shrink-0 sm:mt-0">Upload it again</a>
                @endif
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------ ready --}}
    @if ($notStarted && $hasMapping)
        <div class="kn-card mb-6 border-brand-200">
            <div class="kn-card-body sm:flex sm:items-center sm:justify-between sm:gap-6">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-ink-900">Ready to import</h3>
                    <p class="mt-1 text-sm text-ink-600">
                        {{ number_format((int) $import->total_rows) }}
                        {{ \Illuminate\Support\Str::plural('row', (int) $import->total_rows) }} will be read using the
                        mapping below. The work runs in the background, so you can close this tab and come back to it.
                    </p>
                </div>

                <div class="mt-4 flex shrink-0 flex-wrap items-center gap-3 sm:mt-0">
                    <a href="{{ route('imports.map', $import) }}" class="kn-btn-secondary">Edit mapping</a>

                    <form method="POST" action="{{ route('imports.start', $import) }}">
                        @csrf
                        <button type="submit" class="kn-btn-primary">Start import</button>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- --------------------------------------------------------- live progress --}}
    @if ($running)
        <div class="kn-card mb-6"
             x-data="{
                 p: @js($initialProgress),
                 timer: null,
                 init() {
                     this.poll();
                     this.timer = setInterval(() => this.poll(), 2000);
                 },
                 stop() {
                     if (this.timer) { clearInterval(this.timer); this.timer = null }
                 },
                 destroy() { this.stop() },
                 async poll() {
                     try {
                         const meta = document.querySelector('meta[name=csrf-token]');
                         const res = await fetch(@js(route('imports.progress', $import)), {
                             headers: {
                                 'Accept': 'application/json',
                                 'X-Requested-With': 'XMLHttpRequest',
                                 'X-CSRF-TOKEN': meta ? meta.content : ''
                             },
                             credentials: 'same-origin'
                         });
                         if (! res.ok) { this.failures = this.failures + 1; return }
                         this.p = await res.json();
                         this.failures = 0;
                         if (this.p.finished) { this.stop(); window.location.reload() }
                     } catch (error) {
                         this.failures = this.failures + 1;
                     }
                 },
                 failures: 0,
                 percent() { return Math.min(100, Math.max(0, Number(this.p.percent) || 0)) },
                 fmt(value) { return Number(value || 0).toLocaleString() }
             }">

            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">
                    <span x-text="p.status === 'pending' ? 'Queued' : 'Importing now'">{{ $import->status === 'pending' ? 'Queued' : 'Importing now' }}</span>
                </h3>
                <span class="text-xs text-ink-500">
                    Updates every 2 seconds · <span x-show="failures === 0">connected</span><span x-show="failures !== 0" x-cloak>reconnecting</span>
                </span>
            </div>

            <div class="space-y-5 p-5">
                <div>
                    <div class="mb-2 flex items-end justify-between gap-4">
                        <p class="text-sm text-ink-600">
                            <span class="font-semibold text-ink-900" x-text="fmt(p.processed_rows)">{{ number_format((int) $import->processed_rows) }}</span>
                            of
                            <span x-text="fmt(p.total_rows)">{{ number_format((int) $import->total_rows) }}</span>
                            rows read
                        </p>
                        <p class="text-sm font-semibold text-brand-700">
                            <span x-text="percent()">{{ $import->progressPercent() }}</span>%
                        </p>
                    </div>

                    <div class="h-2.5 w-full overflow-hidden rounded-full bg-ink-200"
                         role="progressbar" aria-label="Import progress">
                        <div class="h-2.5 rounded-full bg-brand-600 transition-all duration-500"
                             :style="'width: ' + percent() + '%'"
                             style="width: {{ $import->progressPercent() }}%"></div>
                    </div>

                    <p class="mt-2 text-xs text-ink-500" x-show="p.status === 'pending'">
                        Waiting for a background worker to pick this up. Nothing has been read yet.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <div class="rounded-lg border border-ink-200 px-3 py-2.5">
                        <p class="kn-stat-label">Imported</p>
                        <p class="mt-0.5 text-lg font-semibold text-emerald-600" x-text="fmt(p.imported)">{{ number_format((int) $import->imported_count) }}</p>
                    </div>
                    <div class="rounded-lg border border-ink-200 px-3 py-2.5">
                        <p class="kn-stat-label">Updated</p>
                        <p class="mt-0.5 text-lg font-semibold text-ink-900" x-text="fmt(p.updated)">{{ number_format((int) $import->updated_count) }}</p>
                    </div>
                    <div class="rounded-lg border border-ink-200 px-3 py-2.5">
                        <p class="kn-stat-label">Duplicates</p>
                        <p class="mt-0.5 text-lg font-semibold text-ink-900" x-text="fmt(p.duplicates)">{{ number_format((int) $import->duplicate_count) }}</p>
                    </div>
                    <div class="rounded-lg border border-ink-200 px-3 py-2.5">
                        <p class="kn-stat-label">Invalid</p>
                        <p class="mt-0.5 text-lg font-semibold text-red-600" x-text="fmt(p.invalid)">{{ number_format((int) $import->invalid_count) }}</p>
                    </div>
                    <div class="rounded-lg border border-ink-200 px-3 py-2.5">
                        <p class="kn-stat-label">Suppressed</p>
                        <p class="mt-0.5 text-lg font-semibold text-amber-600" x-text="fmt(p.suppressed)">{{ number_format((int) $import->suppressed_count) }}</p>
                    </div>
                </div>

                <div x-show="p.last_error" x-cloak
                     class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
                     x-text="p.last_error"></div>
            </div>

            <div class="flex flex-col gap-3 border-t border-ink-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-ink-500">
                    This page refreshes itself the moment the import finishes. Closing the tab does not stop it.
                </p>

                @permission('contacts.import')
                    <x-confirm-form :action="route('imports.cancel', $import)"
                                    method="POST"
                                    label="Cancel import"
                                    button-class="kn-btn-secondary kn-btn-sm"
                                    message="Cancel this import? It stops at the next batch of rows. Contacts already imported are kept." />
                @endpermission
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------- report --}}
    @if ($finished)
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <x-stat-card label="Imported"
                         :value="number_format((int) $import->imported_count)"
                         meta="New contacts created"
                         tone="positive" />
            <x-stat-card label="Updated"
                         :value="number_format((int) $import->updated_count)"
                         meta="Existing contacts changed" />
            <x-stat-card label="Duplicates skipped"
                         :value="number_format((int) $import->duplicate_count)"
                         meta="Already in your account" />
            <x-stat-card label="Invalid rows"
                         :value="number_format((int) $import->invalid_count)"
                         meta="Missing or malformed email"
                         :tone="(int) $import->invalid_count > 0 ? 'danger' : 'default'" />
            <x-stat-card label="Suppressed"
                         :value="number_format((int) $import->suppressed_count)"
                         meta="Kept unsubscribed on purpose"
                         :tone="(int) $import->suppressed_count > 0 ? 'warning' : 'default'" />
        </div>

        @if ($import->last_error)
            <div class="mb-6 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                </svg>
                <div class="min-w-0">
                    <p class="font-medium">The import reported a problem</p>
                    <p class="mt-1 break-words">{{ $import->last_error }}</p>
                </div>
            </div>
        @endif

        <div class="kn-card mb-6">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Outcome</h3>
                <span class="text-xs text-ink-500">
                    @if ($import->completed_at)
                        Finished {{ $import->completed_at->format('j M Y, H:i') }}
                    @else
                        No finish time recorded
                    @endif
                </span>
            </div>

            <div class="kn-card-body sm:flex sm:items-center sm:justify-between sm:gap-6">
                <p class="text-sm text-ink-600">
                    @if ($import->status === 'completed')
                        {{ number_format((int) $import->processed_rows) }} of
                        {{ number_format((int) $import->total_rows) }} rows were read.
                        @if ((int) $import->suppressed_count > 0)
                            {{ number_format((int) $import->suppressed_count) }}
                            {{ \Illuminate\Support\Str::plural('address', (int) $import->suppressed_count) }}
                            on your suppression list were stored as unsubscribed rather than active.
                        @endif
                    @elseif ($import->status === 'cancelled')
                        Cancelled after {{ number_format((int) $import->processed_rows) }} of
                        {{ number_format((int) $import->total_rows) }} rows. Everything imported up to that point was kept.
                    @else
                        Stopped after {{ number_format((int) $import->processed_rows) }} of
                        {{ number_format((int) $import->total_rows) }} rows. Contacts imported before the failure were
                        kept.
                        @if ((int) $import->processed_rows > 0)
                            Resuming picks up at row {{ number_format((int) $import->processed_rows + 1) }} and keeps
                            the counts above. Starting over re-reads the file from the top — already-imported rows then
                            fall to the "existing contacts" choice.
                        @else
                            It failed before any row was read, so running it again starts from the top.
                        @endif
                    @endif
                </p>

                <div class="mt-4 flex shrink-0 flex-wrap items-center gap-3 sm:mt-0">
                    @if ($errorCount > 0)
                        <a href="{{ route('imports.errors', $import) }}" class="kn-btn-secondary">
                            Download error rows ({{ number_format($errorCount) }})
                        </a>
                    @endif

                    @if ($import->status === 'failed' && $hasMapping)
                        @if ((int) $import->processed_rows > 0)
                            {{-- Resume is the default; starting over is the deliberate,
                                 secondary choice because it re-reads everything. --}}
                            <form method="POST" action="{{ route('imports.start', $import) }}">
                                @csrf
                                <button type="submit" class="kn-btn-primary">
                                    Resume from row {{ number_format((int) $import->processed_rows + 1) }}
                                </button>
                            </form>

                            <form method="POST" action="{{ route('imports.start', $import) }}"
                                  onsubmit="return confirm('Start over? The whole file is read again from the top.');">
                                @csrf
                                <input type="hidden" name="restart" value="1">
                                <button type="submit" class="kn-btn-secondary">Start over</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('imports.start', $import) }}">
                                @csrf
                                <button type="submit" class="kn-btn-primary">Run it again</button>
                            </form>
                        @endif
                    @endif

                    @permission('contacts.view')
                        <a href="{{ route('subscribers.index') }}" class="kn-btn-primary">View contacts</a>
                    @endpermission
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------- error rows --}}
        @if ($errorCount > 0)
            <div class="kn-card mb-6 overflow-hidden">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Rows that could not be imported</h3>
                    <span class="text-xs text-ink-500">
                        Showing {{ min(20, $errorCount) }} of {{ number_format($errorCount) }}
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="kn-table">
                        <thead>
                            <tr>
                                <th class="w-24">Row</th>
                                <th class="min-w-[14rem]">Value</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_slice($errorRows, 0, 20) as $errorRow)
                                <tr>
                                    <td class="whitespace-nowrap text-ink-500">{{ $errorRow['row'] ?? '—' }}</td>
                                    <td>
                                        @if (filled($errorRow['value'] ?? null))
                                            <span class="block max-w-[20rem] truncate font-medium text-ink-900"
                                                  title="{{ $errorRow['value'] }}">{{ $errorRow['value'] }}</span>
                                        @else
                                            <span class="text-ink-400">empty</span>
                                        @endif
                                    </td>
                                    <td class="text-ink-600">{{ $errorRow['reason'] ?? 'Not recorded' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($errorCount > 20)
                    <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                        {{ number_format($errorCount - 20) }} more
                        {{ \Illuminate\Support\Str::plural('row', $errorCount - 20) }} are in the downloadable CSV.
                        Fix them in your source file and import just those rows again.
                    </p>
                @endif
            </div>
        @endif
    @endif

    {{-- ------------------------------------------------------------ summary --}}
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="kn-card overflow-hidden lg:col-span-2">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Column mapping</h3>
                <span class="text-xs text-ink-500">
                    {{ count($mapping) }} of {{ count($import->options['headers'] ?? []) ?: count($mapping) }} columns imported
                </span>
            </div>

            @if (! $hasMapping)
                <x-empty-state title="No mapping saved yet"
                               :message="$notStarted
                                    ? 'Choose which column holds each piece of contact data, then come back here to start the import.'
                                    : 'The columns were never confirmed for this upload, so nothing was read from the file.'">
                    <x-slot name="action">
                        @if ($notStarted)
                            <a href="{{ route('imports.map', $import) }}" class="kn-btn-primary">Map the columns</a>
                        @else
                            <a href="{{ route('imports.create') }}" class="kn-btn-primary">Upload it again</a>
                        @endif
                    </x-slot>
                </x-empty-state>
            @else
                <div class="overflow-x-auto">
                    <table class="kn-table">
                        <thead>
                            <tr>
                                <th class="min-w-[12rem]">Column in your file</th>
                                <th class="min-w-[12rem]">Imported as</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($mapping as $column => $target)
                                <tr>
                                    <td>
                                        <span class="block max-w-[16rem] truncate font-medium text-ink-900" title="{{ $column }}">
                                            {{ $column }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-ink-400">&rarr;</span>
                                        <span class="{{ $target === 'email' ? 'font-semibold text-ink-900' : 'text-ink-700' }}">
                                            {{ $fieldLabel($target) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                    Any column not listed here was set to "ignore" and is not imported.
                </p>
            @endif
        </div>

        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">{{ $hasMapping ? 'Options used' : 'Options' }}</h3>
            </div>

            {{-- Nothing is stored against the import until the mapping is saved, so the
                 values below would otherwise read as choices the user never made. --}}
            @if (! $hasMapping)
                <p class="border-b border-ink-100 bg-ink-50 px-5 py-3 text-xs text-ink-500">
                    No options have been chosen yet. These are the defaults you will see on the mapping screen.
                </p>
            @endif

            <dl class="divide-y divide-ink-100 text-sm">
                <div class="px-5 py-3">
                    <dt class="kn-stat-label">File</dt>
                    <dd class="mt-0.5 break-words font-medium text-ink-900">{{ $import->original_name }}</dd>
                </div>

                <div class="px-5 py-3">
                    <dt class="kn-stat-label">Rows in file</dt>
                    <dd class="mt-0.5 font-medium text-ink-900">{{ number_format((int) $import->total_rows) }}</dd>
                </div>

                <div class="px-5 py-3">
                    <dt class="kn-stat-label">Existing contacts</dt>
                    <dd class="mt-0.5 text-ink-700">{{ $duplicateLabel }}</dd>
                </div>

                <div class="px-5 py-3">
                    <dt class="kn-stat-label">Status for new contacts</dt>
                    <dd class="mt-0.5 text-ink-700">{{ ucfirst($options['status'] ?? 'active') }}</dd>
                </div>

                <div class="px-5 py-3">
                    <dt class="kn-stat-label">Consent recorded as</dt>
                    <dd class="mt-0.5 text-ink-700">{{ $consentLabels[$options['consent_status'] ?? 'unknown'] ?? 'Unknown' }}</dd>
                </div>

                <div class="px-5 py-3">
                    <dt class="kn-stat-label">Source label</dt>
                    <dd class="mt-0.5 text-ink-700">{{ $options['source'] ?? 'import' }}</dd>
                </div>

                <div class="px-5 py-3">
                    <dt class="kn-stat-label">Added to lists</dt>
                    <dd class="mt-1">
                        @if (empty($listNames))
                            <span class="text-ink-500">None</span>
                        @else
                            <span class="flex flex-wrap gap-1">
                                @foreach ($listNames as $listName)
                                    <span class="kn-badge-blue">{{ $listName }}</span>
                                @endforeach
                            </span>
                        @endif
                    </dd>
                </div>

                <div class="px-5 py-3">
                    <dt class="kn-stat-label">Tagged with</dt>
                    <dd class="mt-1">
                        @if (empty($tagNames))
                            <span class="text-ink-500">None</span>
                        @else
                            <span class="flex flex-wrap gap-1">
                                @foreach ($tagNames as $tagName)
                                    <span class="kn-badge-gray">{{ $tagName }}</span>
                                @endforeach
                            </span>
                        @endif
                    </dd>
                </div>

                @if ($import->started_at)
                    <div class="px-5 py-3">
                        <dt class="kn-stat-label">Started</dt>
                        <dd class="mt-0.5 text-ink-700">{{ $import->started_at->format('j M Y, H:i') }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-ink-200 pt-5 text-sm">
        <a href="{{ route('imports.index') }}" class="text-ink-500 hover:text-ink-700">All imports</a>

        @permission('contacts.view')
            <a href="{{ route('subscribers.index') }}" class="font-medium text-brand-600 hover:text-brand-700">
                Back to contacts
            </a>
        @endpermission
    </div>
</x-app-layout>
