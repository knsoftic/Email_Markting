<?php

namespace App\Services\Contacts;

use App\Models\SubscriberList;
use App\Services\Automation\AutomationTrigger;
use Illuminate\Support\Facades\DB;

/**
 * Keeps subscriber_lists' denormalised counters honest.
 *
 * Counters are recomputed after bulk membership changes rather than
 * incremented per row, so a partially-failed batch can never leave the numbers
 * drifting from reality.
 */
class ListService
{
    /**
     * Recompute counters for the given lists in ONE grouped query rather than
     * one query per list — this runs after every import and bulk action.
     *
     * @param  iterable<int>  $listIds
     */
    public function refreshCounts(iterable $listIds, ?int $accountId = null): void
    {
        $ids = collect($listIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $rows = DB::table('list_subscriber')
            ->join('subscribers', 'subscribers.id', '=', 'list_subscriber.subscriber_id')
            ->whereIn('list_subscriber.subscriber_list_id', $ids->all())
            ->whereNull('subscribers.deleted_at')
            ->when($accountId, fn ($q) => $q->where('subscribers.account_id', $accountId))
            ->groupBy('list_subscriber.subscriber_list_id', 'subscribers.status')
            ->select(
                'list_subscriber.subscriber_list_id as list_id',
                'subscribers.status',
                DB::raw('COUNT(*) as aggregate')
            )
            ->get();

        $byList = [];

        foreach ($rows as $row) {
            $byList[$row->list_id][$row->status] = (int) $row->aggregate;
        }

        foreach ($ids as $listId) {
            $counts = $byList[$listId] ?? [];

            SubscriberList::withoutGlobalScopes()
                ->whereKey($listId)
                ->update([
                    'total_count' => array_sum($counts),
                    'active_count' => $counts['active'] ?? 0,
                    'unsubscribed_count' => $counts['unsubscribed'] ?? 0,
                    'bounced_count' => $counts['bounced'] ?? 0,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Refresh every list a given set of subscribers belongs to. Used after a
     * status change, where the membership did not move but the mix did.
     *
     * @param  iterable<int>  $subscriberIds
     */
    public function refreshCountsForSubscribers(iterable $subscriberIds, ?int $accountId = null): void
    {
        $ids = collect($subscriberIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $listIds = DB::table('list_subscriber')
            ->whereIn('subscriber_id', $ids->all())
            ->distinct()
            ->pluck('subscriber_list_id');

        $this->refreshCounts($listIds, $accountId);
    }

    /**
     * Attach subscribers to lists without creating duplicate pivot rows.
     * insertOrIgnore keeps this a single statement per list even for large
     * batches, and leans on the (list, subscriber) unique index.
     *
     * @param  iterable<int>  $subscriberIds
     * @param  iterable<int>  $listIds
     */
    public function attach(iterable $subscriberIds, iterable $listIds, ?int $accountId = null): int
    {
        $subscribers = collect($subscriberIds)->filter()->unique()->values();
        $lists = collect($listIds)->filter()->unique()->values();

        if ($subscribers->isEmpty() || $lists->isEmpty()) {
            return 0;
        }

        $now = now();
        $attached = 0;
        $joined = [];

        foreach ($lists as $listId) {
            $rows = $subscribers->map(fn ($subscriberId) => [
                'subscriber_list_id' => $listId,
                'subscriber_id' => $subscriberId,
                'subscribed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($rows, 1000) as $chunk) {
                $attached += DB::table('list_subscriber')->insertOrIgnore($chunk);
            }

            // Which of them actually JOINED, as opposed to already being on
            // the list. insertOrIgnore leaves an existing row untouched, so
            // only the rows written by this call carry this call's timestamp —
            // and an automation must fire for a new member, never for somebody
            // who was re-saved with the membership they already had.
            $joined[$listId] = DB::table('list_subscriber')
                ->where('subscriber_list_id', $listId)
                ->whereIn('subscriber_id', $subscribers->all())
                ->where('subscribed_at', $now)
                ->pluck('subscriber_id')
                ->all();
        }

        $this->refreshCounts($lists, $accountId);

        if ($accountId !== null) {
            $trigger = app(AutomationTrigger::class);

            foreach ($joined as $listId => $ids) {
                if ($ids !== []) {
                    $trigger->listJoined($accountId, $ids, (int) $listId);
                }
            }
        }

        return $attached;
    }

    /**
     * @param  iterable<int>  $subscriberIds
     * @param  iterable<int>  $listIds
     */
    public function detach(iterable $subscriberIds, iterable $listIds, ?int $accountId = null): int
    {
        $subscribers = collect($subscriberIds)->filter()->unique()->values();
        $lists = collect($listIds)->filter()->unique()->values();

        if ($subscribers->isEmpty() || $lists->isEmpty()) {
            return 0;
        }

        $removed = DB::table('list_subscriber')
            ->whereIn('subscriber_list_id', $lists->all())
            ->whereIn('subscriber_id', $subscribers->all())
            ->delete();

        $this->refreshCounts($lists, $accountId);

        return $removed;
    }
}
