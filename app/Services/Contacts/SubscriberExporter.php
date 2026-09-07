<?php

namespace App\Services\Contacts;

use App\Models\CustomField;
use App\Models\Segment;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams contacts out as CSV.
 *
 * Rows are written straight to the output stream in chunks, so exporting
 * 100k contacts costs the same memory as exporting ten. Nothing is buffered
 * and no temporary file is created.
 */
class SubscriberExporter
{
    public function __construct(
        protected SubscriberService $subscribers,
        protected SegmentCompiler $compiler,
    ) {}

    /** Columns offered in the export UI, in output order. */
    public const COLUMNS = [
        'email' => 'Email',
        'name' => 'Name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'phone' => 'Phone',
        'company' => 'Company',
        'country' => 'Country',
        'city' => 'City',
        'status' => 'Status',
        'source' => 'Source',
        'consent_status' => 'Consent',
        'subscribed_at' => 'Subscribed at',
        'unsubscribed_at' => 'Unsubscribed at',
        'last_activity_at' => 'Last activity',
        'created_at' => 'Date added',
        'lists' => 'Lists',
        'tags' => 'Tags',
    ];

    /**
     * Resolves the requested audience into a query.
     *
     * Every branch goes through a tenant-scoped model, so a foreign list, tag
     * or segment id resolves to nothing rather than to another account's data.
     *
     * @param  array<string, mixed>  $input
     */
    public function query(array $input): Builder
    {
        $query = match ($input['source'] ?? 'all') {
            'selected' => Subscriber::query()->whereIn('id', (array) ($input['ids'] ?? [])),

            'list' => Subscriber::query()->whereExists(
                fn ($sub) => $sub->selectRaw(1)->from('list_subscriber')
                    ->whereColumn('list_subscriber.subscriber_id', 'subscribers.id')
                    ->whereIn('list_subscriber.subscriber_list_id',
                        SubscriberList::whereIn('id', (array) ($input['list_ids'] ?? []))->pluck('id'))
            ),

            'tag' => Subscriber::query()->whereExists(
                fn ($sub) => $sub->selectRaw(1)->from('subscriber_tag')
                    ->whereColumn('subscriber_tag.subscriber_id', 'subscribers.id')
                    ->whereIn('subscriber_tag.tag_id',
                        Tag::whereIn('id', (array) ($input['tag_ids'] ?? []))->pluck('id'))
            ),

            'segment' => $this->segmentQuery($input['segment_id'] ?? null),

            'filtered' => $this->subscribers->filtered((array) ($input['filters'] ?? [])),

            default => Subscriber::query(),
        };

        if (! empty($input['status'])) {
            $query->where('status', $input['status']);
        }

        if (! empty($input['mailable_only'])) {
            $query->mailable();
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $columns
     */
    public function stream(array $input, array $columns, string $filename): StreamedResponse
    {
        $columns = array_values(array_filter($columns, fn ($c) => isset(self::COLUMNS[$c]) || str_starts_with($c, 'custom:')));

        if (empty($columns)) {
            $columns = ['email', 'name', 'status'];
        }

        $needsLists = in_array('lists', $columns, true);
        $needsTags = in_array('tags', $columns, true);
        $customLabels = $this->customFieldLabels();

        $query = $this->query($input);

        if ($needsLists || $needsTags) {
            $query->with(array_filter([
                $needsLists ? 'lists:id,name' : null,
                $needsTags ? 'tags:id,name' : null,
            ]));
        }

        return response()->streamDownload(function () use ($query, $columns, $customLabels) {
            $out = fopen('php://output', 'w');

            // A BOM makes Excel open UTF-8 accented names correctly instead of
            // showing mojibake.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, array_map(
                fn ($c) => str_starts_with($c, 'custom:')
                    ? ($customLabels[substr($c, 7)] ?? substr($c, 7))
                    : self::COLUMNS[$c],
                $columns
            ));

            $query->orderBy('subscribers.id')->chunk(1000, function ($rows) use ($out, $columns) {
                foreach ($rows as $subscriber) {
                    fputcsv($out, array_map(fn ($c) => $this->valueFor($subscriber, $c), $columns));
                }

                flush();
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function customFieldLabels(): array
    {
        return CustomField::orderBy('sort_order')->pluck('name', 'key')->all();
    }

    protected function segmentQuery(mixed $segmentId): Builder
    {
        $segment = $segmentId ? Segment::find($segmentId) : null;

        // An unknown or foreign segment id must export nothing, never
        // everything.
        return $segment
            ? $this->compiler->forSegment($segment)
            : Subscriber::query()->whereRaw('1 = 0');
    }

    protected function valueFor(Subscriber $subscriber, string $column): string
    {
        if (str_starts_with($column, 'custom:')) {
            $value = $subscriber->customValue(substr($column, 7));

            return is_scalar($value) ? (string) $value : '';
        }

        return match ($column) {
            'lists' => $subscriber->relationLoaded('lists') ? $subscriber->lists->pluck('name')->implode(', ') : '',
            'tags' => $subscriber->relationLoaded('tags') ? $subscriber->tags->pluck('name')->implode(', ') : '',
            'subscribed_at', 'unsubscribed_at', 'last_activity_at', 'created_at' => $subscriber->{$column}?->toDateTimeString() ?? '',
            default => (string) ($subscriber->{$column} ?? ''),
        };
    }
}
