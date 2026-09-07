<?php

namespace App\Services\Contacts;

use App\Models\Subscriber;
use App\Models\Suppression;
use App\Support\ActivityLogger;
use App\Support\TenantManager;
use Illuminate\Support\Collection;

/**
 * The do-not-send list.
 *
 * Every marketing send checks this, so it is deliberately the single place
 * that adds to and removes from it — suppression must never be written
 * ad hoc from a controller.
 */
class SuppressionService
{
    public function __construct(protected TenantManager $tenant) {}

    protected function accountId(?int $accountId = null): int
    {
        $id = $accountId ?? $this->tenant->id() ?? auth()->user()?->account_id;

        abort_if($id === null, 500, 'Suppression requires an account context.');

        return (int) $id;
    }

    public function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function isSuppressed(string $email, ?int $accountId = null): bool
    {
        return Suppression::withoutGlobalScopes()
            ->where('account_id', $this->accountId($accountId))
            ->where('email', $this->normalise($email))
            ->exists();
    }

    /**
     * Which of the given addresses are already suppressed. One indexed query
     * instead of one per address — used by the importer and by recipient
     * generation.
     *
     * @param  iterable<string>  $emails
     * @return array<string, true> keyed by normalised email for O(1) lookups
     */
    public function suppressedMap(iterable $emails, ?int $accountId = null): array
    {
        $normalised = collect($emails)->map(fn ($e) => $this->normalise((string) $e))->filter()->unique()->values();

        if ($normalised->isEmpty()) {
            return [];
        }

        return Suppression::withoutGlobalScopes()
            ->where('account_id', $this->accountId($accountId))
            ->whereIn('email', $normalised->all())
            ->pluck('email')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Adds an address to the list and pulls the matching contact out of
     * circulation, so the two can never disagree.
     */
    public function suppress(
        string $email,
        string $reason = 'manual',
        ?int $campaignId = null,
        ?string $notes = null,
        ?string $source = null,
        ?int $accountId = null,
        bool $log = true,
    ): Suppression {
        $accountId = $this->accountId($accountId);
        $email = $this->normalise($email);

        $suppression = Suppression::withoutGlobalScopes()->updateOrCreate(
            ['account_id' => $accountId, 'email' => $email],
            [
                'reason' => in_array($reason, Suppression::REASONS, true) ? $reason : 'manual',
                'campaign_id' => $campaignId,
                'notes' => $notes,
                'source' => $source,
            ],
        );

        $this->markSubscriber($email, $reason, $accountId);

        if ($log) {
            ActivityLogger::log(
                'suppression.added',
                "Suppressed {$email} ({$reason})",
                ['account_id' => $accountId],
                $suppression
            );
        }

        return $suppression;
    }

    /**
     * Bulk add. Returns how many rows were newly created.
     *
     * @param  iterable<string>  $emails
     */
    public function suppressMany(
        iterable $emails,
        string $reason = 'manual',
        ?string $source = null,
        ?int $accountId = null,
        ?string $notes = null,
    ): int {
        $accountId = $this->accountId($accountId);
        $added = 0;

        foreach (collect($emails)->map(fn ($e) => $this->normalise((string) $e))->filter()->unique() as $email) {
            if (! $this->isSuppressed($email, $accountId)) {
                $added++;
            }

            $this->suppress($email, $reason, null, $notes, $source, $accountId, log: false);
        }

        if ($added > 0) {
            ActivityLogger::log(
                'suppression.bulk_added',
                "Suppressed {$added} addresses ({$reason})",
                ['account_id' => $accountId]
            );
        }

        return $added;
    }

    /**
     * Removes an address from the list. The contact is NOT auto-reactivated:
     * re-consent is a deliberate act, not a side effect of tidying a list.
     */
    public function release(string $email, ?int $accountId = null): bool
    {
        $accountId = $this->accountId($accountId);
        $email = $this->normalise($email);

        $deleted = Suppression::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('email', $email)
            ->delete();

        if ($deleted) {
            ActivityLogger::log(
                'suppression.removed',
                "Removed {$email} from the suppression list",
                ['account_id' => $accountId]
            );
        }

        return (bool) $deleted;
    }

    /**
     * @param  iterable<int>  $ids
     */
    public function releaseMany(iterable $ids, ?int $accountId = null): int
    {
        $accountId = $this->accountId($accountId);

        $emails = Suppression::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->whereIn('id', collect($ids)->all())
            ->pluck('email');

        if ($emails->isEmpty()) {
            return 0;
        }

        $count = Suppression::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->whereIn('email', $emails->all())
            ->delete();

        ActivityLogger::log(
            'suppression.bulk_removed',
            "Removed {$count} addresses from the suppression list",
            ['account_id' => $accountId]
        );

        return $count;
    }

    /**
     * @return Collection<int, string>
     */
    public function reasons(): Collection
    {
        return collect(Suppression::REASONS);
    }

    /**
     * Keeps the contact record consistent with the suppression reason.
     */
    protected function markSubscriber(string $email, string $reason, int $accountId): void
    {
        $status = match ($reason) {
            'unsubscribed' => 'unsubscribed',
            'hard_bounce', 'soft_bounce' => 'bounced',
            default => 'blocked',
        };

        $subscriber = Subscriber::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('email', $email)
            ->first();

        if (! $subscriber || $subscriber->status === $status) {
            return;
        }

        $subscriber->forceFill(array_filter([
            'status' => $status,
            'unsubscribed_at' => $status === 'unsubscribed' ? now() : $subscriber->unsubscribed_at,
        ]))->save();
    }
}
