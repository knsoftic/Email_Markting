<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Jobs\Contacts\ProcessSubscriberImport;
use App\Models\CustomField;
use App\Models\Import;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Services\Contacts\CsvReader;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ImportController extends Controller
{
    /** Subscriber columns a CSV column can be mapped onto. */
    public const TARGET_FIELDS = [
        'email' => 'Email address (required)',
        'name' => 'Full name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'phone' => 'Phone',
        'company' => 'Company',
        'country' => 'Country',
        'city' => 'City',
        'timezone' => 'Timezone',
        'source' => 'Source',
        'notes' => 'Notes',
    ];

    public function index(Request $request): View
    {
        return view('imports.index', [
            'imports' => Import::with('user:id,name')->latest('id')->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $limits = PlanLimits::for($request->user()->account);

        return view('imports.create', [
            'contactLimit' => $limits->limit('max_contacts'),
            'contactsUsed' => $limits->usageFor('max_contacts'),
            'maxUploadKb' => 51200,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
        ], [], ['file' => 'CSV file']);

        $file = $request->file('file');

        // Stored on the local (private) disk under the account's own folder —
        // an uploaded contact list must never be reachable from the web root.
        $path = $file->storeAs(
            'imports/'.$request->user()->account_id,
            Str::uuid()->toString().'.csv',
            'local'
        );

        $import = Import::create([
            'user_id' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'mapping',
        ]);

        try {
            $reader = CsvReader::open(Storage::disk('local')->path($path));
            $headers = $reader->headers();

            if (empty($headers)) {
                throw new \RuntimeException('No column headers were found in the first row.');
            }

            $import->forceFill([
                'total_rows' => $reader->countRows(),
                'options' => [
                    'headers' => $headers,
                    'delimiter' => $reader->detectDelimiter(),
                    'encoding' => $reader->detectEncoding(),
                ],
            ])->save();
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            $import->delete();

            return back()->with('error', 'That file could not be read: '.$e->getMessage());
        }

        return to_route('imports.map', $import);
    }

    public function map(Import $import): View
    {
        abort_unless(in_array($import->status, ['mapping', 'pending'], true), 404);

        $reader = CsvReader::open(Storage::disk('local')->path($import->file_path));
        $headers = $import->options['headers'] ?? $reader->headers();

        $customFields = CustomField::orderBy('sort_order')->get();

        return view('imports.map', [
            'import' => $import,
            'headers' => $headers,
            'sample' => $reader->sample(5),
            'targets' => self::TARGET_FIELDS,
            'customFields' => $customFields,
            'guessed' => $this->guessMapping($headers, $customFields),
            'lists' => SubscriberList::orderBy('name')->get(['id', 'name']),
            'tags' => Tag::orderBy('name')->get(['id', 'name', 'color']),
        ]);
    }

    public function saveMapping(Request $request, Import $import): RedirectResponse
    {
        abort_unless(in_array($import->status, ['mapping', 'pending'], true), 404);

        $accountId = $request->user()->account_id;

        $validated = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:100'],
            'duplicates' => ['required', 'in:skip,update'],
            'status' => ['required', 'in:active,pending'],
            'consent_status' => ['required', 'in:explicit,implied,unknown'],
            'source' => ['nullable', 'string', 'max:100'],
            'list_ids' => ['array'],
            'list_ids.*' => [Rule::exists('subscriber_lists', 'id')->where('account_id', $accountId)],
            'tag_ids' => ['array'],
            'tag_ids.*' => [Rule::exists('tags', 'id')->where('account_id', $accountId)],
        ]);

        $mapping = $this->sanitiseMapping($validated['mapping']);

        if (! in_array('email', $mapping, true)) {
            return back()->with('error', 'Map one column to the email address before continuing.');
        }

        $import->forceFill([
            'mapping' => $mapping,
            'list_ids' => $validated['list_ids'] ?? [],
            'tag_ids' => $validated['tag_ids'] ?? [],
            'options' => array_merge($import->options ?? [], [
                'duplicates' => $validated['duplicates'],
                'status' => $validated['status'],
                'consent_status' => $validated['consent_status'],
                // 'source' is optional, so it is absent from the validated
                // array rather than null when the field is not submitted.
                'source' => ($validated['source'] ?? null) ?: 'import',
            ]),
        ])->save();

        return to_route('imports.show', $import)
            ->with('success', 'Mapping saved. Review the summary and start the import.');
    }

    /**
     * Queues the import. The HTTP request never processes rows itself.
     *
     * A failed run that already got part way is RESUMED rather than restarted:
     * the job reads from $import->processed_rows, so keeping the counters means
     * a 100k-row file that died at row 60k does not re-read the first 60k.
     * Anything else is a fresh start and the counters are zeroed.
     */
    public function start(Request $request, Import $import): RedirectResponse
    {
        abort_unless(in_array($import->status, ['mapping', 'pending', 'failed'], true), 404);

        if (empty($import->mapping)) {
            return back()->with('error', 'Set the column mapping first.');
        }

        $canResume = $import->status === 'failed' && $import->processed_rows > 0;
        $resuming = $canResume && ! $request->boolean('restart');

        $import->forceFill(array_merge(
            ['status' => 'pending', 'last_error' => null],
            $resuming ? [] : [
                'processed_rows' => 0,
                'imported_count' => 0,
                'updated_count' => 0,
                'duplicate_count' => 0,
                'invalid_count' => 0,
                'suppressed_count' => 0,
                'error_rows' => [],
            ],
        ))->save();

        ProcessSubscriberImport::dispatch($import->id);

        ActivityLogger::log(
            'contacts.import_started',
            ($resuming ? 'Resumed' : 'Started')." import {$import->original_name}",
            [],
            $import
        );

        return to_route('imports.show', $import)->with('success', $resuming
            ? 'Resuming from row '.number_format($import->processed_rows).'. Progress updates below.'
            : 'Import queued. Progress updates below as the worker processes it.');
    }

    public function show(Import $import): View
    {
        return view('imports.show', [
            'import' => $import->load('user:id,name'),
            'targets' => self::TARGET_FIELDS,
        ]);
    }

    /** Polled by the progress screen. */
    public function progress(Import $import): JsonResponse
    {
        return response()->json([
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'percent' => $import->progressPercent(),
            'imported' => $import->imported_count,
            'updated' => $import->updated_count,
            'duplicates' => $import->duplicate_count,
            'invalid' => $import->invalid_count,
            'suppressed' => $import->suppressed_count,
            'errors' => count($import->error_rows ?? []),
            'last_error' => $import->last_error,
            'finished' => in_array($import->status, ['completed', 'failed', 'cancelled'], true),
        ]);
    }

    public function downloadErrors(Import $import): StreamedResponse
    {
        $rows = $import->error_rows ?? [];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['row', 'value', 'reason']);

            foreach ($rows as $row) {
                fputcsv($out, [$row['row'] ?? '', $row['value'] ?? '', $row['reason'] ?? '']);
            }

            fclose($out);
        }, 'import-'.$import->id.'-errors.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Takes effect at the next chunk boundary in the running job. */
    public function cancel(Import $import): RedirectResponse
    {
        abort_unless(in_array($import->status, ['pending', 'processing', 'mapping'], true), 404);

        $import->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();

        return back()->with('warning', 'Import cancelled. Contacts already imported were kept.');
    }

    public function destroy(Import $import): RedirectResponse
    {
        if ($import->file_path && Storage::disk('local')->exists($import->file_path)) {
            Storage::disk('local')->delete($import->file_path);
        }

        $name = $import->original_name;
        $import->delete();

        return to_route('imports.index')
            ->with('success', "Import record for \"{$name}\" removed. Imported contacts were kept.");
    }

    /**
     * Pre-selects an obvious target for each column so a tidy CSV needs no
     * manual mapping at all.
     *
     * @param  array<int, string>  $headers
     * @return array<string, string>
     */
    protected function guessMapping(array $headers, $customFields): array
    {
        $aliases = [
            'email' => ['email', 'email address', 'e-mail', 'mail', 'email_address'],
            'name' => ['name', 'full name', 'fullname', 'contact', 'contact name'],
            'first_name' => ['first name', 'firstname', 'first', 'given name'],
            'last_name' => ['last name', 'lastname', 'last', 'surname', 'family name'],
            'phone' => ['phone', 'mobile', 'telephone', 'phone number', 'contact number'],
            'company' => ['company', 'organisation', 'organization', 'business'],
            'country' => ['country'],
            'city' => ['city', 'town'],
            'timezone' => ['timezone', 'time zone'],
            'source' => ['source', 'origin'],
            'notes' => ['notes', 'note', 'comment', 'comments'],
        ];

        $customKeys = collect($customFields)->pluck('key')->all();
        $guess = [];

        foreach ($headers as $header) {
            $needle = Str::lower(trim(str_replace('_', ' ', $header)));
            $match = 'ignore';

            foreach ($aliases as $field => $names) {
                if (in_array($needle, $names, true)) {
                    $match = $field;
                    break;
                }
            }

            if ($match === 'ignore') {
                $slug = Str::slug($header, '_');

                if (in_array($slug, $customKeys, true)) {
                    $match = 'custom:'.$slug;
                }
            }

            $guess[$header] = $match;
        }

        return $guess;
    }

    /**
     * Keeps only targets that actually exist, so a tampered mapping cannot
     * name an arbitrary column, and drops duplicate targets after the first.
     *
     * @param  array<string, string|null>  $mapping
     * @return array<string, string>
     */
    protected function sanitiseMapping(array $mapping): array
    {
        $customKeys = CustomField::pluck('key')->all();
        $used = [];
        $clean = [];

        foreach ($mapping as $column => $target) {
            if (! is_string($target) || $target === '' || $target === 'ignore') {
                continue;
            }

            $isCore = array_key_exists($target, self::TARGET_FIELDS);
            $isCustom = str_starts_with($target, 'custom:')
                && in_array(substr($target, 7), $customKeys, true);

            if (! $isCore && ! $isCustom) {
                continue;
            }

            if (in_array($target, $used, true)) {
                continue;
            }

            $used[] = $target;
            $clean[(string) $column] = $target;
        }

        return $clean;
    }
}
