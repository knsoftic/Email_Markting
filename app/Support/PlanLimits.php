<?php

namespace App\Support;

use App\Exceptions\PlanLimitException;
use App\Models\Account;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Illuminate\Support\Facades\DB;

/**
 * One place that answers "is this account allowed to do this, and how much
 * headroom is left?".
 *
 * Reads through Subscription::limit(), so a per-account override always beats
 * the plan value. A NULL limit means unlimited; 0 means the capability is
 * switched off entirely.
 */
class PlanLimits
{
    protected ?Subscription $subscription;

    public function __construct(protected ?Account $account)
    {
        // Read WITHOUT the tenant scope on purpose. This class is always given
        // an explicit Account, and the answer must not depend on whichever
        // tenant happens to be bound — a scoped read returns null for another
        // account, and allows() would then quietly report every feature as
        // switched off instead of failing loudly.
        $this->subscription = $account
            ? Subscription::withoutGlobalScopes()
                ->where('account_id', $account->id)
                ->with('plan')
                ->latest('id')
                ->first()
            : null;
    }

    public static function for(?Account $account): self
    {
        return new self($account);
    }

    public static function forCurrentUser(): self
    {
        return new self(auth()->user()?->account);
    }

    /** Effective value of a limit or feature flag, after overrides. */
    public function limit(string $key): int|bool|null
    {
        return $this->subscription?->limit($key);
    }

    public function allows(string $feature): bool
    {
        // With no subscription at all nothing is unlocked — an account without
        // a plan should not silently get the full product.
        return (bool) ($this->subscription?->limit($feature) ?? false);
    }

    public function isUnlimited(string $key): bool
    {
        return $this->limit($key) === null;
    }

    /**
     * How many more of something this account may create. NULL = unlimited.
     */
    public function remaining(string $key, ?int $used = null): ?int
    {
        $limit = $this->limit($key);

        if ($limit === null) {
            return null;
        }

        return max(0, (int) $limit - ($used ?? $this->usageFor($key)));
    }

    public function hasRoomFor(string $key, int $adding = 1, ?int $used = null): bool
    {
        $remaining = $this->remaining($key, $used);

        return $remaining === null || $remaining >= $adding;
    }

    /**
     * Guard clause for controllers and jobs.
     *
     * @throws PlanLimitException
     */
    public function ensure(string $key, int $adding = 1, ?int $used = null, ?string $noun = null): void
    {
        $limit = $this->limit($key);

        if ($limit === null) {
            return;
        }

        $current = $used ?? $this->usageFor($key);
        $noun ??= $this->nounFor($key);

        if ((int) $limit === 0) {
            throw new PlanLimitException(
                "Your plan does not include {$noun}. Upgrade to enable it.",
                $key, 0, $current
            );
        }

        if ($current + $adding > (int) $limit) {
            throw new PlanLimitException(
                "Your plan allows ".number_format((int) $limit)." {$noun}. You are using ".number_format($current).".",
                $key, (int) $limit, $current
            );
        }
    }

    /**
     * @throws PlanLimitException
     */
    public function ensureFeature(string $feature, string $noun): void
    {
        if (! $this->allows($feature)) {
            throw new PlanLimitException(
                "Your plan does not include {$noun}. Upgrade to enable it.",
                $feature
            );
        }
    }

    /**
     * Live usage for the limits that are counted from real rows rather than
     * from the monthly usage counter.
     */
    public function usageFor(string $key): int
    {
        if (! $this->account) {
            return 0;
        }

        $accountId = $this->account->id;

        return match ($key) {
            // whereNull('deleted_at'), like every other count below it. These
            // two used withoutGlobalScopes(), which lifts SoftDeletingScope as
            // well as tenancy, so deleted contacts and lists went on being
            // charged against the customer's plan: delete five hundred people
            // and the allowance still says they are there — and eventually
            // refuses to let any more be added.
            'max_contacts' => DB::table('subscribers')
                ->where('account_id', $accountId)->whereNull('deleted_at')->count(),
            'max_lists' => DB::table('subscriber_lists')
                ->where('account_id', $accountId)->whereNull('deleted_at')->count(),
            'max_smtp_accounts' => DB::table('smtp_accounts')->where('account_id', $accountId)->whereNull('deleted_at')->count(),
            'max_mailboxes' => DB::table('mailboxes')->where('account_id', $accountId)->whereNull('deleted_at')->count(),
            'max_templates' => DB::table('email_templates')->where('account_id', $accountId)->whereNull('deleted_at')->count(),
            'max_automations' => DB::table('automations')->where('account_id', $accountId)->whereNull('deleted_at')->count(),
            'max_team_members' => DB::table('users')->where('account_id', $accountId)->whereNull('deleted_at')->count(),
            'max_emails_per_month' => (int) $this->counter()->emails_sent,
            'max_emails_received_per_month' => (int) $this->counter()->emails_received,
            'max_campaigns_per_month' => (int) $this->counter()->campaigns_created,

            // Stored attachments, in whole megabytes. Advertised by every plan
            // since Phase 1 and never computed, so the limit could not be hit
            // however much an account stored. Rounded UP: 1.2 MB used against
            // a 1 MB allowance is over it, and reporting 1 would say otherwise.
            'max_storage_mb' => (int) ceil(
                ((int) DB::table('email_attachments')->where('account_id', $accountId)->sum('size')) / 1048576
            ),

            default => 0,
        };
    }

    public function counter(): UsageCounter
    {
        return UsageCounter::withoutGlobalScopes()->firstOrNew([
            'account_id' => $this->account?->id,
            'period' => UsageCounter::currentPeriod(),
        ]);
    }

    /** Atomically bump a monthly usage counter. */
    public function increment(string $column, int $by = 1): void
    {
        if (! $this->account || $by === 0) {
            return;
        }

        UsageCounter::withoutGlobalScopes()->updateOrCreate(
            ['account_id' => $this->account->id, 'period' => UsageCounter::currentPeriod()],
            [],
        );

        UsageCounter::withoutGlobalScopes()
            ->where('account_id', $this->account->id)
            ->where('period', UsageCounter::currentPeriod())
            ->increment($column, $by);
    }

    protected function nounFor(string $key): string
    {
        return match ($key) {
            'max_contacts' => 'contacts',
            'max_lists' => 'lists',
            'max_templates' => 'templates',
            'max_smtp_accounts' => 'SMTP accounts',
            'max_mailboxes' => 'mailboxes',
            'max_automations' => 'automations',
            'max_team_members' => 'team members',
            'max_emails_per_month' => 'emails per month',
            'max_emails_per_day' => 'emails per day',
            'max_campaigns_per_month' => 'campaigns per month',
            'max_emails_received_per_month' => 'received emails per month',
            'max_storage_mb' => 'MB of storage',
            default => str_replace(['max_', '_'], ['', ' '], $key),
        };
    }
}
