<?php

namespace App\Jobs\Contacts;

use App\Models\Import;
use App\Models\Subscriber;
use App\Services\Automation\AutomationTrigger;
use App\Services\Contacts\CsvReader;
use App\Services\Contacts\ListService;
use App\Services\Contacts\SuppressionService;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use App\Support\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Streams an uploaded CSV into the subscribers table.
 *
 * Design points that matter:
 *
 * - The tenant is bound explicitly for the whole run. A worker is a long-lived
 *   process, so inheriting whatever account the previous job left bound would
 *   write rows into the wrong tenant.
 * - Rows are read in chunks and never all held in memory, so a 100k-row file
 *   costs the same as a 100-row one.
 * - Per chunk there are exactly two lookup queries (existing contacts,
 *   suppressed addresses) rather than two per row.
 * - Progress is written after every chunk so the UI can poll it, and
 *   processed_rows doubles as the resume point if the worker dies.
 * - The plan's contact limit is re-checked as the import runs, not just at the
 *   start, so a big file cannot walk an account past its ceiling.
 */
class ProcessSubscriberImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $backoff = 30;

    /** How many error rows are kept for the downloadable report. */
    protected const MAX_STORED_ERRORS = 500;

    public function __construct(public int $importId) {}

    public function handle(
        TenantManager $tenant,
        SuppressionService $suppressions,
        ListService $lists,
    ): void {
        $import = Import::withoutGlobalScopes()->find($this->importId);

        if (! $import || in_array($import->status, ['completed', 'cancelled'], true)) {
            return;
        }

        $tenant->runAs($import->account_id, function () use ($import, $suppressions, $lists) {
            $this->run($import, $suppressions, $lists);
        });
    }

    protected function run(Import $import, SuppressionService $suppressions, ListService $lists): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($import->file_path)) {
            $this->fail($import, 'The uploaded file is no longer available.');

            return;
        }

        $mapping = collect($import->mapping ?? [])->filter(fn ($field) => $field && $field !== 'ignore');

        if (! $mapping->contains('email')) {
            $this->fail($import, 'No column is mapped to the email address.');

            return;
        }

        $options = $import->options ?? [];
        $duplicates = $options['duplicates'] ?? 'skip';       // skip | update
        $defaultStatus = $options['status'] ?? 'active';
        $consent = $options['consent_status'] ?? 'unknown';
        $source = $options['source'] ?? 'import';

        $listIds = $import->list_ids ?? [];
        $tagIds = $import->tag_ids ?? [];

        $limits = PlanLimits::for($import->account);
        $contactLimit = $limits->limit('max_contacts');
        $contactsUsed = $limits->usageFor('max_contacts');

        $import->forceFill([
            'status' => 'processing',
            'started_at' => $import->started_at ?? now(),
            'last_error' => null,
        ])->save();

        $errors = collect($import->error_rows ?? []);
        $counters = [
            'imported_count' => $import->imported_count,
            'updated_count' => $import->updated_count,
            'duplicate_count' => $import->duplicate_count,
            'invalid_count' => $import->invalid_count,
            'suppressed_count' => $import->suppressed_count,
        ];

        $touchedSubscriberIds = [];

        // Kept apart from the touched list: only a contact this import
        // actually CREATED is new, and re-importing the same spreadsheet must
        // not re-enter everybody in it.
        $createdSubscriberIds = [];

        // tag id => subscriber ids that did not already carry it, accumulated
        // across chunks so `tag_added` fires once per contact per tag.
        $newlyTagged = [];
        $limitHit = false;

        try {
            $reader = CsvReader::open($disk->path($import->file_path));

            foreach ($reader->chunks(500, $import->processed_rows) as [$offset, $rows]) {
                // A cancel from the UI takes effect at the next chunk boundary.
                if ($import->fresh()?->status === 'cancelled') {
                    return;
                }

                $prepared = [];

                foreach ($rows as $index => $row) {
                    $rowNumber = $offset - count($rows) + $index + 1;
                    $attributes = $this->mapRow($row, $mapping);
                    $email = mb_strtolower(trim((string) ($attributes['email'] ?? '')));

                    if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $counters['invalid_count']++;
                        $errors = $this->recordError($errors, $rowNumber, $email, 'Invalid or missing email address');

                        continue;
                    }

                    $attributes['email'] = $email;
                    $prepared[$email] = ['row' => $rowNumber, 'attributes' => $attributes];
                }

                if ($prepared) {
                    $emails = array_keys($prepared);

                    // Two queries for the whole chunk, not two per row.
                    // Trashed rows are deliberately included: UNIQUE(account_id,
                    // email) does not exclude them, so an insert for a deleted
                    // address would hit the index and throw. `deleted_at` comes
                    // back too, because what we do next depends on it.
                    $existing = Subscriber::withoutGlobalScopes()
                        ->where('account_id', $import->account_id)
                        ->whereIn('email', $emails)
                        ->get(['id', 'email', 'status', 'deleted_at'])
                        ->keyBy('email');

                    $suppressed = $suppressions->suppressedMap($emails, $import->account_id);

                    foreach ($prepared as $email => $entry) {
                        $attributes = $entry['attributes'];

                        if (isset($existing[$email])) {
                            $subscriber = $existing[$email];
                            $touchedSubscriberIds[] = $subscriber->id;

                            /*
                             * A row matching this address exists but has been
                             * deleted. Re-importing the address is the operator
                             * asking for that contact back, and restoring is the
                             * only thing the unique index allows — the single
                             * contact form has always done exactly this.
                             *
                             * Without it, "update" reported contacts updated
                             * that stayed invisible everywhere in the app, and
                             * "skip" counted them as duplicates, so a deleted
                             * contact could never be re-imported at all: the
                             * operator re-uploads their list and those people
                             * simply never come back, with nothing said.
                             */
                            if ($subscriber->deleted_at !== null) {
                                if ($contactLimit !== null && $contactsUsed >= (int) $contactLimit) {
                                    $limitHit = true;
                                    break;
                                }

                                Subscriber::withoutGlobalScopes()
                                    ->whereKey($subscriber->id)
                                    ->update(array_merge($this->updatePayload($attributes), [
                                        'deleted_at' => null,
                                        // An address on the do-not-send list comes
                                        // back as a contact, never as an active
                                        // one: restoring a row cannot undo consent.
                                        'status' => isset($suppressed[$email])
                                            ? 'unsubscribed'
                                            : ($subscriber->status ?: $defaultStatus),
                                    ]));

                                if (isset($suppressed[$email])) {
                                    $counters['suppressed_count']++;
                                }

                                $createdSubscriberIds[] = $subscriber->id;
                                $contactsUsed++;
                                $counters['imported_count']++;

                                continue;
                            }

                            if ($duplicates === 'update') {
                                Subscriber::withoutGlobalScopes()
                                    ->whereKey($subscriber->id)
                                    ->update($this->updatePayload($attributes));
                                $counters['updated_count']++;
                            } else {
                                $counters['duplicate_count']++;
                            }

                            continue;
                        }

                        if ($contactLimit !== null && $contactsUsed >= (int) $contactLimit) {
                            $limitHit = true;
                            break;
                        }

                        // An address already opted out is stored, but never as
                        // an active contact. An import must not undo consent.
                        $status = isset($suppressed[$email]) ? 'unsubscribed' : $defaultStatus;

                        if (isset($suppressed[$email])) {
                            $counters['suppressed_count']++;
                        }

                        $id = DB::table('subscribers')->insertGetId(array_merge(
                            $this->insertPayload($attributes),
                            [
                                'account_id' => $import->account_id,
                                'status' => $status,
                                'consent_status' => $attributes['consent_status'] ?? $consent,
                                'source' => $attributes['source'] ?? $source,
                                'subscribed_at' => now(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        ));

                        $touchedSubscriberIds[] = $id;
                        $createdSubscriberIds[] = $id;
                        $contactsUsed++;
                        $counters['imported_count']++;
                    }
                }

                $import->forceFill(array_merge($counters, [
                    'processed_rows' => $offset,
                    'error_rows' => $errors->values()->all(),
                ]))->save();

                if ($limitHit) {
                    break;
                }
            }

            // Membership and counters are settled once, at the end, rather
            // than per row.
            if ($touchedSubscriberIds) {
                $unique = array_values(array_unique($touchedSubscriberIds));

                if ($listIds) {
                    foreach (array_chunk($unique, 2000) as $chunk) {
                        $lists->attach($chunk, $listIds, $import->account_id);
                    }
                }

                if ($tagIds) {
                    foreach ($this->attachTags($unique, $tagIds) as $tagId => $gained) {
                        $newlyTagged[$tagId] = array_merge($newlyTagged[$tagId] ?? [], $gained);
                    }
                }

                $lists->refreshCountsForSubscribers($unique, $import->account_id);
            }

            // Automations, once the import is otherwise settled. `attach()`
            // fires the list trigger itself, for exactly the rows it inserted;
            // this covers the two it cannot see.
            $trigger = app(AutomationTrigger::class);

            if ($createdSubscriberIds) {
                $trigger->subscriberAdded(
                    $import->account_id,
                    array_values(array_unique($createdSubscriberIds)),
                    $listIds
                );
            }

            // Tags fire for whoever actually gained one — including contacts
            // the account already had. Re-importing a spreadsheet to tag
            // existing customers is the ordinary way people use this, and
            // firing only for new rows would leave that doing nothing at all.
            foreach ($newlyTagged as $tagId => $gained) {
                $trigger->tagsAdded($import->account_id, array_values(array_unique($gained)), [(int) $tagId]);
            }

            PlanLimits::for($import->account)->increment('contacts_added', $counters['imported_count']);

            $import->forceFill(array_merge($counters, [
                'status' => 'completed',
                'completed_at' => now(),
                'error_rows' => $errors->values()->all(),
                'last_error' => $limitHit
                    ? 'Stopped early: the account reached its plan contact limit.'
                    : null,
            ]))->save();

            ActivityLogger::log(
                'contacts.imported',
                "Imported {$counters['imported_count']} contact(s) from {$import->original_name}",
                ['account_id' => $import->account_id],
                $import
            );
        } catch (Throwable $e) {
            $this->fail($import, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Applies the column mapping to one CSV row.
     *
     * @param  array<string, string|null>  $row
     * @param  Collection<string, string>  $mapping
     * @return array<string, mixed>
     */
    protected function mapRow(array $row, $mapping): array
    {
        $attributes = [];
        $custom = [];

        foreach ($mapping as $column => $field) {
            $value = $row[$column] ?? null;

            if ($value === null) {
                continue;
            }

            if (str_starts_with($field, 'custom:')) {
                $custom[substr($field, 7)] = $value;

                continue;
            }

            $attributes[$field] = $value;
        }

        if ($custom) {
            $attributes['custom'] = $custom;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function insertPayload(array $attributes): array
    {
        $allowed = [
            'email', 'name', 'first_name', 'last_name', 'phone',
            'company', 'country', 'city', 'timezone', 'notes',
        ];

        $payload = array_intersect_key($attributes, array_flip($allowed));

        if (! empty($attributes['custom'])) {
            $payload['custom'] = json_encode($attributes['custom']);
        }

        // Split a single "name" column so personalisation has both parts.
        if (! empty($payload['name']) && empty($payload['first_name'])) {
            $parts = preg_split('/\s+/', trim($payload['name'])) ?: [];
            $payload['first_name'] = $parts[0] ?? null;
            $payload['last_name'] = count($parts) > 1 ? end($parts) : null;
        }

        return $payload;
    }

    /**
     * On update, only non-empty incoming values overwrite what is stored — a
     * blank cell in the CSV must not wipe existing data.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function updatePayload(array $attributes): array
    {
        $payload = array_filter(
            $this->insertPayload($attributes),
            fn ($value) => $value !== null && $value !== ''
        );

        unset($payload['email']);

        // The existing-row lookup deliberately includes soft-deleted contacts,
        // because UNIQUE(account_id, email) counts them. Updating one without
        // clearing deleted_at would report "updated" while the contact stayed
        // invisible, so an update restores it.
        $payload['deleted_at'] = null;
        $payload['updated_at'] = now();

        return $payload;
    }

    /**
     * @param  array<int, int>  $subscriberIds
     * @param  array<int, int>  $tagIds
     */
    /**
     * Attaches the import's tags and reports which contacts genuinely gained
     * each one.
     *
     * The diff matters because `tag_added` automations run off it. Reading who
     * already held the tag before inserting is one indexed query per tag —
     * SubscriberService::bulk() has always done exactly this — so restricting
     * the trigger to newly created contacts was never the only option, and it
     * silently dropped the common case: re-importing a spreadsheet to tag
     * customers the account already knows.
     *
     * @param  array<int>  $subscriberIds
     * @param  array<int>  $tagIds
     * @return array<int, array<int>>  tag id => subscriber ids that did not have it
     */
    protected function attachTags(array $subscriberIds, array $tagIds): array
    {
        $already = [];

        foreach ($tagIds as $tagId) {
            $already[(int) $tagId] = DB::table('subscriber_tag')
                ->where('tag_id', $tagId)
                ->whereIn('subscriber_id', $subscriberIds)
                ->pluck('subscriber_id')
                ->map('intval')
                ->all();
        }

        $rows = [];

        foreach ($subscriberIds as $subscriberId) {
            foreach ($tagIds as $tagId) {
                $rows[] = ['subscriber_id' => $subscriberId, 'tag_id' => $tagId];
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('subscriber_tag')->insertOrIgnore($chunk);
        }

        foreach ($tagIds as $tagId) {
            $count = DB::table('subscriber_tag')
                ->join('subscribers', 'subscribers.id', '=', 'subscriber_tag.subscriber_id')
                ->where('subscriber_tag.tag_id', $tagId)
                ->whereNull('subscribers.deleted_at')
                ->count();

            DB::table('tags')->where('id', $tagId)->update([
                'subscribers_count' => $count,
                'updated_at' => now(),
            ]);
        }

        $fresh = [];

        foreach ($tagIds as $tagId) {
            $gained = array_values(array_diff(
                array_map('intval', $subscriberIds),
                $already[(int) $tagId] ?? []
            ));

            if ($gained !== []) {
                $fresh[(int) $tagId] = $gained;
            }
        }

        return $fresh;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $errors
     * @return Collection<int, array<string, mixed>>
     */
    protected function recordError($errors, int $row, string $value, string $reason)
    {
        if ($errors->count() >= self::MAX_STORED_ERRORS) {
            return $errors;
        }

        return $errors->push(['row' => $row, 'value' => $value, 'reason' => $reason]);
    }

    protected function fail(Import $import, string $message): void
    {
        $import->forceFill([
            'status' => 'failed',
            'last_error' => mb_substr($message, 0, 1000),
            'completed_at' => now(),
        ])->save();
    }

    /** Called by the queue after the final retry. */
    public function failed(Throwable $exception): void
    {
        $import = Import::withoutGlobalScopes()->find($this->importId);

        if ($import && $import->status !== 'cancelled') {
            $this->fail($import, $exception->getMessage());
        }
    }
}
