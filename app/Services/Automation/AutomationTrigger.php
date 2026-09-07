<?php

namespace App\Services\Automation;

use App\Jobs\Automation\EnrolSubscribersJob;
use App\Models\Automation;
use App\Models\Scopes\AccountScope;
use App\Models\Subscriber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns something that happened into an automation enrolment.
 *
 * ── The cost of the common case is the whole design ─────────────────────────
 * These fire from the middle of ordinary work: saving a contact, importing a
 * spreadsheet, recording an open. Almost every account, almost every time, has
 * no automation listening. So the first thing this does is one indexed query
 * on (account_id, status) — and where nothing matches, that is the entire cost.
 * A trigger that made every contact save more expensive would be paid for by
 * every customer to benefit the few who use automations.
 *
 * ── A trigger must never break what triggered it ────────────────────────────
 * An import of ten thousand contacts must not fail because one automation is
 * misconfigured. Every path here is wrapped: the failure is logged with the
 * automation that caused it, and the import finishes. The alternative — a
 * customer's contact upload rolled back by somebody's broken welcome email —
 * is a far worse outcome than a missed enrolment.
 *
 * ── Why it hands large batches to the queue ─────────────────────────────────
 * Enrolling is one insert per contact. For a form submission that is nothing;
 * for a 50,000-row import it is 50,000 inserts inside an HTTP request. Above
 * INLINE_LIMIT the work goes to the queue, so the person who pressed Import
 * gets their page back.
 */
class AutomationTrigger
{
    /** Contacts enrolled inline before the rest is handed to the queue. */
    public const INLINE_LIMIT = 50;

    public function __construct(protected AutomationEnroller $enroller) {}

    /**
     * A contact was created.
     *
     * @param  array<int>  $subscriberIds
     * @param  array<int>  $listIds  the lists they were put on, if any
     */
    public function subscriberAdded(int $accountId, array $subscriberIds, array $listIds = []): void
    {
        $this->dispatch($accountId, 'subscriber_added', $subscriberIds,
            fn (Automation $a) => $this->listMatches($a, $listIds),
            ['list_ids' => array_values($listIds)]
        );
    }

    /**
     * Contacts were put on a list.
     *
     * @param  array<int>  $subscriberIds
     */
    public function listJoined(int $accountId, array $subscriberIds, int $listId, bool $allowReentry = true): void
    {
        $this->dispatch($accountId, 'list_joined', $subscriberIds,
            fn (Automation $a) => $this->idMatches($a, 'list_id', $listId),
            ['list_id' => $listId],
            $allowReentry
        );
    }

    /**
     * Tags were added to contacts.
     *
     * @param  array<int>  $subscriberIds
     * @param  array<int>  $tagIds  only the tags that are NEW on these contacts
     */
    public function tagsAdded(int $accountId, array $subscriberIds, array $tagIds, bool $allowReentry = true): void
    {
        foreach (array_unique($tagIds) as $tagId) {
            $this->dispatch($accountId, 'tag_added', $subscriberIds,
                fn (Automation $a) => $this->idMatches($a, 'tag_id', (int) $tagId),
                ['tag_id' => (int) $tagId],
                $allowReentry
            );
        }
    }

    /**
     * A recipient opened a campaign — for the first time.
     *
     * Only the first open triggers. A person who reads the same email on their
     * phone and again on their laptop has not done a second thing, and the
     * caller passes only firsts; the enroller's unique index is the backstop
     * if a caller ever gets that wrong.
     */
    public function campaignOpened(int $accountId, int $subscriberId, int $campaignId): void
    {
        $this->dispatch($accountId, 'campaign_opened', [$subscriberId],
            fn (Automation $a) => $this->idMatches($a, 'campaign_id', $campaignId),
            ['campaign_id' => $campaignId]
        );
    }

    /**
     * A recipient clicked a link in a campaign.
     *
     * A `link_id` in the trigger config narrows it to one link, which is what
     * "clicked the pricing link" means. Without one, any link counts.
     */
    public function linkClicked(int $accountId, int $subscriberId, int $campaignId, int $linkId): void
    {
        $this->dispatch($accountId, 'link_clicked', [$subscriberId],
            fn (Automation $a) => $this->idMatches($a, 'campaign_id', $campaignId)
                && $this->idMatches($a, 'link_id', $linkId),
            ['campaign_id' => $campaignId, 'link_id' => $linkId]
        );
    }

    // ------------------------------------------------------------- matching

    /**
     * An automation with no id in its config listens to all of them. That is
     * the useful default — "when anyone is tagged", "when any campaign is
     * opened" — and it is also the only reading that cannot silently listen to
     * the wrong thing when the id it named has since been deleted.
     */
    protected function idMatches(Automation $automation, string $key, int $id): bool
    {
        $wanted = $automation->trigger_config[$key] ?? null;

        if ($wanted === null || $wanted === '' || $wanted === []) {
            return true;
        }

        return in_array($id, array_map('intval', (array) $wanted), true);
    }

    /**
     * @param  array<int>  $listIds
     */
    protected function listMatches(Automation $automation, array $listIds): bool
    {
        $wanted = $automation->trigger_config['list_ids'] ?? $automation->trigger_config['list_id'] ?? null;

        if ($wanted === null || $wanted === '' || $wanted === []) {
            return true;
        }

        return array_intersect(array_map('intval', (array) $wanted), array_map('intval', $listIds)) !== [];
    }

    // ------------------------------------------------------------ mechanics

    /**
     * @param  array<int>  $subscriberIds
     * @param  callable(Automation): bool  $matches
     * @param  array<string, mixed>  $context
     */
    protected function dispatch(int $accountId, string $type, array $subscriberIds, callable $matches, array $context, bool $allowReentry = true): void
    {
        $subscriberIds = array_values(array_unique(array_filter(array_map('intval', $subscriberIds))));

        if ($subscriberIds === []) {
            return;
        }

        try {
            $automations = $this->listening($accountId, $type)->filter($matches);
        } catch (Throwable $e) {
            Log::warning('Automation trigger lookup failed', [
                'account_id' => $accountId, 'trigger' => $type, 'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($automations->isEmpty()) {
            return;
        }

        foreach ($automations as $automation) {
            try {
                if (count($subscriberIds) > self::INLINE_LIMIT) {
                    // Queueing can fail too — a locked queue table, a queue
                    // connection that is down. That must cost a missed
                    // enrolment, never the import that triggered it.
                    EnrolSubscribersJob::dispatch($automation->id, $subscriberIds, $context + ['trigger' => $type], $allowReentry);

                    continue;
                }

                $this->enrolNow($automation, $subscriberIds, $context + ['trigger' => $type], $allowReentry);
            } catch (Throwable $e) {
                Log::warning('Automation enrolment dispatch failed', [
                    'automation_id' => $automation->id, 'trigger' => $type, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Enrols a small batch here and now.
     *
     * @param  array<int>  $subscriberIds
     * @param  array<string, mixed>  $context
     */
    public function enrolNow(Automation $automation, array $subscriberIds, array $context, bool $allowReentry = true): int
    {
        $enrolled = 0;

        try {
            $subscribers = Subscriber::withoutGlobalScope(AccountScope::class)
                ->where('account_id', $automation->account_id)
                ->whereIn('id', $subscriberIds)
                ->get();
        } catch (Throwable $e) {
            Log::warning('Automation enrolment lookup failed', [
                'automation_id' => $automation->id, 'error' => $e->getMessage(),
            ]);

            return 0;
        }

        foreach ($subscribers as $subscriber) {
            try {
                if ($this->enroller->enrol($automation, $subscriber, $context, $allowReentry) !== null) {
                    $enrolled++;
                }
            } catch (Throwable $e) {
                // One contact's enrolment failing must not stop the rest, and
                // must never take down the thing that triggered it.
                Log::warning('Automation enrolment failed', [
                    'automation_id' => $automation->id,
                    'subscriber_id' => $subscriber->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $enrolled;
    }

    /**
     * The active automations on this account listening for this trigger.
     *
     * @return Collection<int, Automation>
     */
    protected function listening(int $accountId, string $type): Collection
    {
        return Automation::withoutGlobalScope(AccountScope::class)
            ->where('account_id', $accountId)
            ->where('status', 'active')
            ->where('trigger_type', $type)
            ->whereHas('steps')
            ->get();
    }
}
