<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Models\Segment;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Services\Contacts\SubscriberExporter;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(protected SubscriberExporter $exporter) {}

    public function index(Request $request): View
    {
        return view('exports.index', [
            'lists' => SubscriberList::orderBy('name')->get(['id', 'name', 'total_count']),
            'tags' => Tag::orderBy('name')->get(['id', 'name', 'subscribers_count']),
            'segments' => Segment::orderBy('name')->get(['id', 'name', 'cached_count']),
            'columns' => SubscriberExporter::COLUMNS,
            'customFields' => $this->exporter->customFieldLabels(),
            'statuses' => Subscriber::STATUSES,
            'total' => Subscriber::query()->count(),
            // Pre-selected when the user arrived from a bulk selection.
            'selectedIds' => array_filter(explode(',', (string) $request->query('ids'))),
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        $accountId = $request->user()->account_id;

        $validated = $request->validate([
            'source' => ['required', Rule::in(['all', 'selected', 'list', 'tag', 'segment'])],
            'ids' => ['array'],
            'ids.*' => ['integer'],
            'list_ids' => ['array'],
            'list_ids.*' => [Rule::exists('subscriber_lists', 'id')->where('account_id', $accountId)],
            'tag_ids' => ['array'],
            'tag_ids.*' => [Rule::exists('tags', 'id')->where('account_id', $accountId)],
            'segment_id' => ['nullable', Rule::exists('segments', 'id')->where('account_id', $accountId)],
            'status' => ['nullable', Rule::in(Subscriber::STATUSES)],
            'mailable_only' => ['boolean'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string', 'max:100'],
        ]);

        $validated['mailable_only'] = $request->boolean('mailable_only');

        $filename = 'contacts-'.$validated['source'].'-'.now()->format('Y-m-d-Hi').'.csv';

        ActivityLogger::log(
            'contacts.exported',
            'Exported contacts ('.$validated['source'].')',
            ['source' => $validated['source'], 'columns' => count($validated['columns'])]
        );

        return $this->exporter->stream($validated, $validated['columns'], $filename);
    }
}
