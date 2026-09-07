<x-app-layout>
    <x-slot name="header">Import contacts</x-slot>

    @php
        $maxUploadLabel = $maxUploadKb >= 1024
            ? rtrim(rtrim(number_format($maxUploadKb / 1024, 1, '.', ''), '0'), '.').' MB'
            : number_format($maxUploadKb).' KB';

        $headroom = $contactLimit === null ? null : max(0, (int) $contactLimit - (int) $contactsUsed);
        $usagePercent = $contactLimit ? min(100, (int) round($contactsUsed / max((int) $contactLimit, 1) * 100)) : 0;
        $usageBar = $usagePercent >= 90 ? 'bg-red-500' : ($usagePercent >= 75 ? 'bg-amber-500' : 'bg-brand-600');
        $isFull = $headroom !== null && $headroom === 0;

        $steps = [
            1 => ['label' => 'Upload the file', 'hint' => 'Pick your CSV'],
            2 => ['label' => 'Map the columns', 'hint' => 'Say what each column holds'],
            3 => ['label' => 'Review and import', 'hint' => 'Watch it run'],
        ];
        $currentStep = 1;

        // Illustrates the shape of a good file — static example content, not
        // data from the account.
        $exampleHeaders = ['Email', 'First name', 'Last name', 'Company', 'Country'];
        $exampleRows = [
            ['ada@example.com', 'Ada', 'Lovelace', 'Analytical Engines', 'United Kingdom'],
            ['grace@example.com', 'Grace', 'Hopper', 'Naval Systems', 'United States'],
            ['omar@example.com', 'Omar', 'Khan', 'Northwind Trading', 'Pakistan'],
        ];
    @endphp

    <x-page-header title="Import contacts"
                   subtitle="Step 1 of 3 — upload a CSV file. Nothing is saved to your contacts until you confirm the mapping in the next step."
                   :back="route('imports.index')">
        <x-slot name="actions">
            <a href="{{ route('imports.index') }}" class="kn-btn-secondary">Past imports</a>
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------ step indicator --}}
    <ol class="mb-6 grid gap-3 sm:grid-cols-3">
        @foreach ($steps as $number => $step)
            @php $state = $number === $currentStep ? 'current' : ($number < $currentStep ? 'done' : 'todo'); @endphp
            <li class="flex items-center gap-3 rounded-lg border px-4 py-3
                       {{ $state === 'current' ? 'border-brand-200 bg-brand-50' : 'border-ink-200 bg-white' }}">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-semibold
                             {{ $state === 'current' ? 'bg-brand-600 text-white' : 'bg-ink-100 text-ink-500' }}">
                    {{ $number }}
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium {{ $state === 'current' ? 'text-brand-800' : 'text-ink-700' }}">
                        {{ $step['label'] }}
                    </span>
                    <span class="block truncate text-xs {{ $state === 'current' ? 'text-brand-700' : 'text-ink-500' }}">
                        {{ $step['hint'] }}
                    </span>
                </span>
            </li>
        @endforeach
    </ol>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            {{-- ------------------------------------------------------- upload --}}
            <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="kn-card">
                @csrf

                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Choose your CSV file</h3>
                    <span class="text-xs text-ink-500">Up to {{ $maxUploadLabel }}</span>
                </div>

                <div class="space-y-5 p-5">
                    <div>
                        <x-input-label for="file" value="CSV file" />
                        {{-- Windows and some exporters label a CSV as text/plain, and the server
                             accepts csv and txt, so the picker must not hide those files. --}}
                        <input id="file" name="file" type="file" accept=".csv,.txt,text/csv,text/plain" class="kn-input" required>
                        <p class="kn-help">
                            A comma-separated file with a header row on top, saved as UTF-8. Files up to
                            {{ $maxUploadLabel }} are accepted. Exports from Excel, Google Sheets, Mailchimp
                            and most CRMs work as they are.
                        </p>
                        <x-input-error :messages="$errors->get('file')" />
                    </div>

                    @if ($isFull)
                        <div class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                            </svg>
                            <p>
                                Your plan's contact limit is already reached. You can still upload and map a file,
                                but the import will stop as soon as it needs to create a new contact. Existing
                                contacts can still be updated.
                            </p>
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <a href="{{ route('imports.index') }}" class="kn-btn-secondary">Cancel</a>
                        <button type="submit" class="kn-btn-primary">Upload and continue</button>
                    </div>
                </div>
            </form>

            {{-- ---------------------------------------------- example file shape --}}
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">What your file should look like</h3>
                    <span class="text-xs text-ink-500">Only the email column is required</span>
                </div>

                <div class="px-5 pt-4 text-sm text-ink-600">
                    <p>
                        Put the column names in the first row. Everything else is optional — you can map
                        as many or as few columns as you like on the next screen, and any column you do not
                        need can be ignored.
                    </p>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="kn-table">
                        <thead>
                            <tr>
                                @foreach ($exampleHeaders as $exampleHeader)
                                    <th>{{ $exampleHeader }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($exampleRows as $exampleRow)
                                <tr>
                                    @foreach ($exampleRow as $cell)
                                        <td class="whitespace-nowrap">{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-ink-100 px-5 py-4 text-sm text-ink-600">
                    <p class="font-medium text-ink-800">A few things worth knowing</p>
                    <ul class="mt-2 space-y-1.5">
                        <li class="flex gap-2">
                            <span class="text-ink-400">·</span>
                            <span>Rows without a valid email address are skipped and listed in the error report at the end.</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-ink-400">·</span>
                            <span>If an address is already in your account you choose what happens next — skip it, or update the details you supplied.</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-ink-400">·</span>
                            <span>A single "Full name" column is split into first and last name for you.</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-ink-400">·</span>
                            <span>Custom fields you have created appear in the mapping list alongside the built-in fields.</span>
                        </li>
                    </ul>
                </div>
            </div>

            {{-- ------------------------------------------------ suppression note --}}
            <div class="kn-card border-amber-200">
                <div class="kn-card-body sm:flex sm:items-start sm:gap-4">
                    <div class="mb-3 grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-600 sm:mb-0">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-ink-900">People who have opted out stay opted out</h3>
                        <p class="mt-1 text-sm text-ink-600">
                            If an address in your file is on the suppression list — someone who unsubscribed,
                            complained, or hard bounced — it is still imported so you keep a complete record,
                            but it is saved as unsubscribed and is never made active again. Re-uploading a
                            file cannot undo an opt-out, and those contacts are counted separately in the
                            final report.
                        </p>
                        @permission('contacts.view')
                            <a href="{{ route('suppressions.index') }}"
                               class="mt-2 inline-block text-sm font-medium text-brand-600 hover:text-brand-700">
                                See the suppression list
                            </a>
                        @endpermission
                    </div>
                </div>
            </div>
        </div>

        {{-- -------------------------------------------------------- plan sidebar --}}
        <div class="space-y-6">
            <div class="kn-card">
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Plan headroom</h3></div>
                <div class="space-y-4 p-5">
                    <div>
                        <p class="kn-stat-label">Contacts used</p>
                        <p class="kn-stat-value">{{ number_format((int) $contactsUsed) }}</p>
                        <p class="mt-1 text-xs text-ink-500">
                            @if ($contactLimit === null)
                                Your plan has no contact limit.
                            @else
                                of {{ number_format((int) $contactLimit) }} allowed on your plan
                            @endif
                        </p>
                    </div>

                    @if ($contactLimit !== null)
                        <div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-ink-200">
                                <div class="h-2 rounded-full {{ $usageBar }}" style="width: {{ $usagePercent }}%"></div>
                            </div>
                            <p class="mt-1.5 text-xs {{ $usagePercent >= 90 ? 'font-semibold text-red-600' : 'text-ink-500' }}">
                                {{ $usagePercent }}% used
                            </p>
                        </div>

                        <div class="rounded-lg border border-ink-200 bg-ink-50 px-4 py-3">
                            <p class="kn-stat-label">Room for</p>
                            <p class="mt-0.5 text-lg font-semibold {{ $isFull ? 'text-red-600' : 'text-ink-900' }}">
                                {{ number_format($headroom) }} more {{ \Illuminate\Support\Str::plural('contact', $headroom) }}
                            </p>
                            <p class="mt-1 text-xs text-ink-500">
                                Rows beyond this point are not imported. Updates to contacts you already have
                                do not count against the limit.
                            </p>
                        </div>
                    @else
                        <span class="kn-badge-blue">Unlimited contacts</span>
                    @endif
                </div>
            </div>

            <div class="kn-card">
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">What happens next</h3></div>
                <ol class="space-y-3 p-5 text-sm text-ink-600">
                    <li class="flex gap-3">
                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-ink-100 text-xs font-semibold text-ink-600">1</span>
                        <span>We read the header row and show you the first few rows, so you can check the file was read correctly.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-ink-100 text-xs font-semibold text-ink-600">2</span>
                        <span>You match each column to a contact field. Obvious ones such as "Email" are matched for you.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-ink-100 text-xs font-semibold text-ink-600">3</span>
                        <span>You start the import and watch it run. Large files keep going in the background — you can close this tab.</span>
                    </li>
                </ol>
            </div>
        </div>
    </div>
</x-app-layout>
