<?php

namespace App\Services\Contacts;

use App\Exceptions\PlanLimitException;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Services\Automation\AutomationTrigger;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use App\Support\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * All writes to the contact record go through here so the rules that make a
 * contact legitimate — consent, suppression, plan limits, list counters and
 * usage accounting — are applied in exactly one place.
 */
class SubscriberService
{
    public function __construct(
        protected ListService $lists,
        protected SuppressionService $suppressions,
        protected TenantManager $tenant,
        protected AutomationTrigger $automations,
    ) {}

    /** Multi-row actions the bulk endpoint accepts. Anything else is rejected. */
    public const BULK_ACTIONS = [
        'add_tags', 'remove_tags', 'add_to_lists', 'remove_from_lists',
        'change_status', 'unsubscribe', 'suppress', 'delete',
    ];

    protected function accountId(): int
    {
        $id = $this->tenant->id() ?? auth()->user()?->account_id;

        abort_if($id === null, 500, 'Subscriber operations require an account context.');

        return (int) $id;
    }

    protected function limits(): PlanLimits
    {
        return PlanLimits::for(auth()->user()?->account);
    }

    // ------------------------------------------------------------- writes

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int>  $listIds
     * @param  array<int>  $tagIds
     *
     * @throws PlanLimitException
     */
    public function create(array $data, array $listIds = [], array $tagIds = []): Subscriber
    {
        $this->limits()->ensure('max_contacts');

        $data['email'] = $this->suppressions->normalise((string) $data['email']);

        // An address already on the do-not-send list is stored, but never as
        // an active contact — importing a name cannot undo an opt-out.
        if ($this->suppressions->isSuppressed($data['email'])) {
            $data['status'] = 'unsubscribed';
        }

        $newTagIds = [];

        $subscriber = DB::transaction(function () use ($data, $listIds, $tagIds, &$newTagIds) {
            // UNIQUE(account_id, email) does not exclude soft-deleted rows, so
            // a plain insert for a previously deleted address hits the index
            // and throws. Restoring the old record is both what the user
            // means and the only thing the constraint allows.
            $subscriber = $this->restoreTrashed($data);

            $subscriber ??= Subscriber::create($this->prepare($data));

            if ($listIds) {
                $this->lists->attach([$subscriber->id], $listIds, $subscriber->account_id);
            }

            if ($tagIds) {
                $own = $this->ownTagIds($tagIds);

                // Which tags are genuinely new to this contact. A brand new row
                // carries none, but a restored one can already have them all,
                // and firing `tag_added` for a tag somebody already had is not
                // an event — on an automation with re-entry it would re-arm a
                // finished run and re-send the whole sequence for nothing.
                $newTagIds = array_values(array_diff($own, $subscriber->tags()->pluck('tags.id')->all()));

                $subscriber->tags()->sync($own);
                $this->refreshTagCounts($tagIds);
            }

            return $subscriber;
        });

        $this->limits()->increment('contacts_added');

        ActivityLogger::log('subscriber.created', "Added contact {$subscriber->email}", [], $subscriber);

        // Deliberately outside the transaction. An automation that is
        // misconfigured must not roll back the contact that was just saved,
        // and a queued enrolment must not be dispatched for a row that a later
        // failure could still undo.
        // Restoring a soft-deleted row counts. The operator filled in the New
        // Contact form, the activity log says "Added contact" and the plan's
        // contact allowance was charged — an automation that did not fire here
        // would be the only part of the app that disagreed. Anybody who still
        // holds a run from before cannot re-enter anyway: that is what the
        // unique index on (automation_id, subscriber_id) is for.
        $this->automations->subscriberAdded(
            $subscriber->account_id, [$subscriber->id], $this->ownListIds($listIds)
        );

        if ($newTagIds !== []) {
            $this->automations->tagsAdded($subscriber->account_id, [$subscriber->id], $newTagIds);
        }

        return $subscriber;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int>|null  $listIds  null leaves membership untouched
     * @param  array<int>|null  $tagIds
     */
    public function update(Subscriber $subscriber, array $data, ?array $listIds = null, ?array $tagIds = null): Subscriber
    {
        if (isset($data['email'])) {
            $data['email'] = $this->suppressions->normalise((string) $data['email']);
        }

        $newTagIds = [];

        DB::transaction(function () use ($subscriber, $data, $listIds, $tagIds, &$newTagIds) {
            $previousStatus = $subscriber->status;

            $subscriber->fill($this->prepare($data, $subscriber));

            if ($subscriber->isDirty('status') && $subscriber->status === 'unsubscribed') {
                $subscriber->unsubscribed_at ??= now();
            }

            $subscriber->save();

            if ($listIds !== null) {
                $current = $subscriber->lists()->pluck('subscriber_lists.id');
                $target = collect($this->ownListIds($listIds));

                $this->lists->attach([$subscriber->id], $target->diff($current), $subscriber->account_id);
                $this->lists->detach([$subscriber->id], $current->diff($target), $subscriber->account_id);
            } elseif ($previousStatus !== $subscriber->status) {
                // Membership unchanged but the status mix moved.
                $this->lists->refreshCountsForSubscribers([$subscriber->id], $subscriber->account_id);
            }

            if ($tagIds !== null) {
                $before = $subscriber->tags()->pluck('tags.id')->all();
                $owned = $this->ownTagIds($tagIds);
                $subscriber->tags()->sync($owned);
                $this->refreshTagCounts(array_merge($before, $tagIds));

                // Only tags this save actually added. Re-saving a contact who
                // already carries the tag is not a new event, and firing on it
                // would re-enter anybody edited twice.
                $newTagIds = array_values(array_diff($owned, array_map('intval', $before)));
            }
        });

        ActivityLogger::log('subscriber.updated', "Updated contact {$subscriber->email}", [], $subscriber);

        if ($newTagIds !== []) {
            $this->automations->tagsAdded($subscriber->account_id, [$subscriber->id], $newTagIds);
        }

        return $subscriber->refresh();
    }

    public function delete(Subscriber $subscriber): void
    {
        $email = $subscriber->email;
        $listIds = $subscriber->lists()->pluck('subscriber_lists.id')->all();
        $tagIds = $subscriber->tags()->pluck('tags.id')->all();

        $subscriber->delete();

        $this->lists->refreshCounts($listIds, $subscriber->account_id);
        $this->refreshTagCounts($tagIds);

        ActivityLogger::log('subscriber.deleted', "Deleted contact {$email}");
    }

    // -------------------------------------------------------------- bulk

    /**
     * Applies one allow-listed action to a set of contacts.
     *
     * The id list is always re-queried through the tenant-scoped model, so
     * posting another account's ids simply selects nothing.
     *
     * @param  array<int>  $ids
     * @param  array<string, mixed>  $options
     * @return array{action: string, affected: int, message: string}
     */
    public function bulk(string $action, array $ids, array $options = []): array
    {
        abort_unless(in_array($action, self::BULK_ACTIONS, true), 422, 'Unsupported bulk action.');

        $subscribers = Subscriber::whereIn('id', $ids)->get(['id', 'email', 'account_id', 'status']);

        if ($subscribers->isEmpty()) {
            return ['action' => $action, 'affected' => 0, 'message' => 'Nothing was selected.'];
        }

        $subscriberIds = $subscribers->pluck('id')->all();
        $accountId = $this->accountId();
        $affected = 0;

        switch ($action) {
            case 'add_tags':
                $tagIds = $this->ownTagIds($options['tag_ids'] ?? []);

                // Who already carries each tag, before we write. The pivot has
                // no timestamps to tell new rows from old ones afterwards, and
                // an automation must fire only for a tag a contact did not
                // already have — otherwise re-running a bulk tag would re-enter
                // everybody who was already tagged.
                $already = DB::table('subscriber_tag')
                    ->whereIn('subscriber_id', $subscriberIds)
                    ->whereIn('tag_id', $tagIds)
                    ->get(['subscriber_id', 'tag_id'])
                    ->groupBy('tag_id')
                    ->map(fn ($rows) => $rows->pluck('subscriber_id')->map('intval')->all())
                    ->all();

                $rows = [];
                foreach ($subscriberIds as $sid) {
                    foreach ($tagIds as $tid) {
                        $rows[] = ['subscriber_id' => $sid, 'tag_id' => $tid];
                    }
                }
                foreach (array_chunk($rows, 1000) as $chunk) {
                    $affected += DB::table('subscriber_tag')->insertOrIgnore($chunk);
                }
                $this->refreshTagCounts($tagIds);

                foreach ($tagIds as $tid) {
                    $fresh = array_values(array_diff($subscriberIds, $already[$tid] ?? []));

                    if ($fresh !== []) {
                        $this->automations->tagsAdded($accountId, $fresh, [(int) $tid]);
                    }
                }

                $message = "Tagged {$affected} contact(s).";
                break;

            case 'remove_tags':
                $tagIds = $this->ownTagIds($options['tag_ids'] ?? []);
                $affected = DB::table('subscriber_tag')
                    ->whereIn('subscriber_id', $subscriberIds)
                    ->whereIn('tag_id', $tagIds)
                    ->delete();
                $this->refreshTagCounts($tagIds);
                $message = "Removed the tag from {$affected} contact(s).";
                break;

            case 'add_to_lists':
                $affected = $this->lists->attach($subscriberIds, $this->ownListIds($options['list_ids'] ?? []), $accountId);
                $message = "Added {$affected} contact(s) to the selected list(s).";
                break;

            case 'remove_from_lists':
                $affected = $this->lists->detach($subscriberIds, $this->ownListIds($options['list_ids'] ?? []), $accountId);
                $message = "Removed {$affected} contact(s) from the selected list(s).";
                break;

            case 'change_status':
                $status = $options['status'] ?? null;
                abort_unless(in_array($status, Subscriber::STATUSES, true), 422, 'Unknown status.');

                $affected = Subscriber::whereIn('id', $subscriberIds)->update(array_filter([
                    'status' => $status,
                    'unsubscribed_at' => $status === 'unsubscribed' ? now() : null,
                    'updated_at' => now(),
                ], fn ($v) => $v !== null));

                $this->lists->refreshCountsForSubscribers($subscriberIds, $accountId);
                $message = "Set {$affected} contact(s) to ".ucfirst($status).'.';
                break;

            case 'unsubscribe':
                foreach ($subscribers as $subscriber) {
                    $this->suppressions->suppress(
                        $subscriber->email, 'unsubscribed', null, 'Bulk action', 'bulk', $accountId, log: false
                    );
                    $affected++;
                }
                $this->lists->refreshCountsForSubscribers($subscriberIds, $accountId);
                $message = "Unsubscribed {$affected} contact(s) and added them to the suppression list.";
                break;

            case 'suppress':
                $reason = $options['reason'] ?? 'manual';
                foreach ($subscribers as $subscriber) {
                    $this->suppressions->suppress(
                        $subscriber->email, $reason, null, 'Bulk action', 'bulk', $accountId, log: false
                    );
                    $affected++;
                }
                $this->lists->refreshCountsForSubscribers($subscriberIds, $accountId);
                $message = "Suppressed {$affected} contact(s).";
                break;

            case 'delete':
            default:
                $listIds = DB::table('list_subscriber')->whereIn('subscriber_id', $subscriberIds)
                    ->distinct()->pluck('subscriber_list_id');
                $tagIds = DB::table('subscriber_tag')->whereIn('subscriber_id', $subscriberIds)
                    ->distinct()->pluck('tag_id');

                $affected = Subscriber::whereIn('id', $subscriberIds)->delete();

                $this->lists->refreshCounts($listIds, $accountId);
                $this->refreshTagCounts($tagIds->all());
                $message = "Deleted {$affected} contact(s).";
                break;
        }

        ActivityLogger::log(
            'subscriber.bulk',
            "Bulk action '{$action}' on {$affected} contact(s)",
            ['action' => $action, 'count' => $affected]
        );

        return ['action' => $action, 'affected' => $affected, 'message' => $message];
    }

    // ----------------------------------------------------------- queries

    /**
     * Index-screen filtering. Every filter is an indexed column or an EXISTS
     * subquery so this stays usable at 100k+ rows.
     *
     * @param  array<string, mixed>  $filters
     */
    public function filtered(array $filters): Builder
    {
        return Subscriber::query()
            ->search($filters['q'] ?? null)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['country'] ?? null, fn ($q, $v) => $q->where('country', $v))
            ->when($filters['city'] ?? null, fn ($q, $v) => $q->where('city', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($filters['consent_status'] ?? null, fn ($q, $v) => $q->where('consent_status', $v))
            ->when($filters['list_id'] ?? null, fn ($q, $listId) => $q->whereExists(
                fn ($sub) => $sub->selectRaw(1)->from('list_subscriber')
                    ->whereColumn('list_subscriber.subscriber_id', 'subscribers.id')
                    ->where('list_subscriber.subscriber_list_id', $listId)
            ))
            ->when($filters['tag_id'] ?? null, fn ($q, $tagId) => $q->whereExists(
                fn ($sub) => $sub->selectRaw(1)->from('subscriber_tag')
                    ->whereColumn('subscriber_tag.subscriber_id', 'subscribers.id')
                    ->where('subscriber_tag.tag_id', $tagId)
            ))
            ->when($filters['created_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['created_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = Subscriber::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $all = array_fill_keys(Subscriber::STATUSES, 0);

        return array_merge($all, array_map('intval', $counts));
    }

    // ----------------------------------------------------------- helpers

    /**
     * Brings back a soft-deleted contact with the same address, applying the
     * incoming values, instead of colliding with the unique index.
     *
     * @param  array<string, mixed>  $data
     */
    protected function restoreTrashed(array $data): ?Subscriber
    {
        $trashed = Subscriber::onlyTrashed()->where('email', $data['email'])->first();

        if (! $trashed) {
            return null;
        }

        $trashed->restore();
        $trashed->fill($this->prepare($data, $trashed));
        $trashed->deleted_at = null;
        $trashed->save();

        return $trashed;
    }

    /**
     * Restricts posted ids to the current account. The tenant scope already
     * does this, but going through the model rather than trusting input keeps
     * that guarantee explicit at every call site.
     *
     * @param  array<int>  $ids
     * @return array<int>
     */
    protected function ownTagIds(array $ids): array
    {
        return $ids ? Tag::whereIn('id', $ids)->pluck('id')->all() : [];
    }

    /**
     * @param  array<int>  $ids
     * @return array<int>
     */
    protected function ownListIds(array $ids): array
    {
        return $ids
            ? SubscriberList::whereIn('id', $ids)->pluck('id')->all()
            : [];
    }

    /**
     * @param  array<int>  $tagIds
     */
    protected function refreshTagCounts(array $tagIds): void
    {
        $ids = collect($tagIds)->filter()->unique();

        if ($ids->isEmpty()) {
            return;
        }

        $counts = DB::table('subscriber_tag')
            ->join('subscribers', 'subscribers.id', '=', 'subscriber_tag.subscriber_id')
            ->whereIn('subscriber_tag.tag_id', $ids->all())
            ->whereNull('subscribers.deleted_at')
            ->groupBy('subscriber_tag.tag_id')
            ->pluck(DB::raw('COUNT(*)'), 'subscriber_tag.tag_id');

        foreach ($ids as $tagId) {
            Tag::withoutGlobalScopes()->whereKey($tagId)->update([
                'subscribers_count' => (int) ($counts[$tagId] ?? 0),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Normalises inbound attributes and keeps the consent trail honest.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepare(array $data, ?Subscriber $existing = null): array
    {
        if (isset($data['name']) && $data['name'] !== '') {
            $parts = preg_split('/\s+/', trim((string) $data['name'])) ?: [];
            $data['first_name'] ??= $parts[0] ?? null;
            $data['last_name'] ??= count($parts) > 1 ? end($parts) : null;
        }

        if ($existing === null) {
            $data['subscribed_at'] ??= now();
            $data['source'] ??= 'manual';
        }

        // Recording explicit consent stamps when and from where.
        if (($data['consent_status'] ?? null) === 'explicit' && $existing?->consent_status !== 'explicit') {
            $data['consent_at'] ??= now();
            $data['consent_ip'] ??= request()->ip();
        }

        return $data;
    }
}
