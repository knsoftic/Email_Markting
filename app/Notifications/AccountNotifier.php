<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\Role;
use App\Models\User;
use App\Support\PlanLimits;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifier;
use Throwable;

/**
 * The one way an in-app notification is sent, and the one place that decides
 * who receives it.
 *
 * ── A notification must never break what it is reporting on ─────────────────
 * Every caller here is in the middle of something that matters far more than
 * the notification: finishing a 50,000-recipient send, giving up on a broken
 * mailbox, deciding a split test. If writing the row throws — the table is
 * missing on a half-migrated install, the account was deleted a millisecond
 * ago, a payload will not serialise — the send must still finish. So the whole
 * call is wrapped, the failure is logged with enough to find it, and the
 * caller is told nothing. This mirrors AutomationTrigger, and for the same
 * reason: the by-product must not be able to kill the main event.
 *
 * ── Who gets told ───────────────────────────────────────────────────────────
 * Notifications belong to the people who can act on them:
 *
 *   - only users of the account the event happened in. Never every user in the
 *     database, and never a super admin — a super admin has no account, their
 *     bell is not part of a tenant's world, and telling them about one
 *     customer's SMTP cooldown would mean telling them about all of them.
 *   - only users who could actually open the thing it links to. A staff member
 *     with no campaigns.view permission cannot be helped by "your campaign
 *     finished" — the link 403s and the line is noise in their bell forever.
 *   - only active users. A suspended account member is not on duty.
 *
 * ── Reads bypass the tenant scope on purpose ────────────────────────────────
 * These run from queue workers and scheduled commands where the bound tenant
 * is whatever the previous job left behind — or nothing at all. Every read
 * below names its account explicitly instead of trusting the ambient one.
 */
class AccountNotifier
{
    /**
     * Sends one notification to everyone in an account who should see it.
     *
     * Returns how many people were notified — 0 covers "nobody qualified" and
     * "it failed and was logged" alike, because no caller should behave
     * differently in those two cases.
     */
    public static function send(?int $accountId, AccountNotification $notification): int
    {
        if (! $accountId) {
            return 0;
        }

        try {
            $recipients = self::recipients($accountId, $notification->permission());

            if ($recipients->isEmpty()) {
                return 0;
            }

            Notifier::send($recipients, $notification);

            return $recipients->count();
        } catch (Throwable $e) {
            Log::warning('In-app notification could not be sent', [
                'account_id' => $accountId,
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Tells the people running the platform, rather than the people using it.
     *
     * `send()` above deliberately excludes super admins — a customer's own
     * notifications are not the operator's business. This is the other
     * direction: a customer asking to change plan is nobody's business but the
     * operator's, and there is no other route to them, because a super admin
     * belongs to no account.
     *
     * Failures are swallowed for the same reason as `send()`: an unsent
     * notification must never break the action it was reporting on.
     */
    public static function sendToSuperAdmins(AccountNotification $notification): int
    {
        try {
            $admins = User::query()
                ->where('is_super_admin', true)
                ->where('status', 'active')
                ->get();

            if ($admins->isEmpty()) {
                return 0;
            }

            Notifier::send($admins, $notification);

            return $admins->count();
        } catch (Throwable $e) {
            Log::warning('Super admin notification could not be sent', [
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * The account's users who should see a notification needing $permission.
     *
     * Roles and the account are loaded once and attached by hand rather than
     * through with(), so nothing here lazy-loads — these run under
     * preventLazyLoading in development — and so the reads cannot be narrowed
     * by whatever tenant a worker happens to have bound.
     *
     * @return Collection<int, User>
     */
    public static function recipients(int $accountId, ?string $permission = null): Collection
    {
        $account = Account::query()->find($accountId);

        if (! $account) {
            // A deleted account has nobody left to tell.
            return collect();
        }

        /** @var Collection<int, User> $users */
        $users = User::query()
            ->where('account_id', $accountId)
            ->where('is_super_admin', false)
            ->where('status', 'active')
            ->get();

        if ($users->isEmpty()) {
            return $users;
        }

        $roles = Role::withoutGlobalScopes()
            ->with('permissions:id,slug')
            ->whereIn('id', $users->pluck('role_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($users as $user) {
            $user->setRelation('account', $account);
            $user->setRelation('role', $roles->get($user->role_id));
        }

        if ($permission === null) {
            return $users->values();
        }

        return $users->filter(fn (User $user) => $user->hasPermission($permission))->values();
    }

    /**
     * Tells the account when a send has pushed it over a monthly-allowance
     * threshold — and only on the pass that actually crossed one.
     *
     * ── Why crossings, not levels ───────────────────────────────────────────
     * "Warn when usage is above 80%" fires on every chunk for the rest of the
     * month: a hundred identical rows in the bell, which is the same as none.
     * Comparing the count before this chunk with the count after means the
     * condition is true exactly once, on the chunk that crossed it, with no
     * dedupe table and no extra query to ask whether we already said it.
     *
     * A plan with no monthly ceiling, or one that has switched sending off
     * entirely, has no threshold to cross and says nothing at all — rather
     * than printing a percentage of a limit that does not exist.
     */
    public static function sendingAllowance(?Account $account, int $usedBefore): void
    {
        if (! $account) {
            return;
        }

        try {
            $limits = PlanLimits::for($account);
            $limit = $limits->limit('max_emails_per_month');

            if (! is_int($limit) || $limit <= 0) {
                return;
            }

            $usedAfter = $limits->usageFor('max_emails_per_month');

            if ($usedAfter <= $usedBefore) {
                return;
            }

            foreach (SendingAllowance::THRESHOLDS as $percent) {
                $at = (int) ceil($limit * $percent / 100);

                if ($usedBefore < $at && $usedAfter >= $at) {
                    self::send($account->id, new SendingAllowance($percent, $usedAfter, $limit));
                }
            }
        } catch (Throwable $e) {
            Log::warning('Sending-allowance notification could not be evaluated', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
