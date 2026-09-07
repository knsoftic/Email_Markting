<?php

namespace App\Services\Smtp;

use App\Models\Account;
use App\Models\Scopes\AccountScope;
use App\Models\SmtpAccount;
use App\Support\PlanLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chooses which SMTP account the next message goes through, and reserves
 * capacity on it.
 *
 * ── Why reservation is a single UPDATE ──────────────────────────────────────
 * Several queue workers send at once. If a worker READ the counters, decided
 * there was room, and then WROTE, two workers could both see 499 of 500 and
 * both send. So the check and the increment are one statement: the limits are
 * in the WHERE clause and the increments in the SET. MariaDB holds the row
 * lock for the duration of that statement, so exactly one of two concurrent
 * workers gets the last slot — the other's WHERE no longer matches and it
 * gets 0 affected rows.
 *
 * The same statement also performs the hour/day/month rollover with CASE
 * expressions. Doing rollover as a separate UPDATE would open a second race
 * where one worker resets a counter while another is incrementing it.
 */
class SmtpSelector
{
    /**
     * Per-day usage accumulated in memory and flushed once per batch, so a
     * 10,000-recipient campaign does not add 10,000 writes to smtp_usage.
     *
     * @var array<string, array{smtp_account_id:int, account_id:int|null, date:string, sent:int, failed:int}>
     */
    protected array $usageBuffer = [];

    /**
     * Every SMTP account the given account is allowed to send through, in the
     * order they should be tried.
     *
     * @return Collection<int, SmtpAccount>
     */
    public function candidatesFor(Account $account): Collection
    {
        $limits = PlanLimits::for($account);

        $query = SmtpAccount::withoutGlobalScope(AccountScope::class)
            ->sendable()
            ->where(function ($q) use ($account, $limits) {
                $any = false;

                if ($limits->allows('allow_custom_smtp')) {
                    $q->orWhere(fn ($own) => $own
                        ->where('account_id', $account->id)
                        ->where('is_global', false));
                    $any = true;
                }

                if ($limits->allows('allow_admin_smtp')) {
                    $q->orWhere(fn ($global) => $global
                        ->where('is_global', true)
                        ->whereNull('account_id')
                        ->whereExists(fn ($sub) => $this->assignmentSubquery($sub, $account)));
                    $any = true;
                }

                // A plan that allows neither must match nothing, not everything.
                if (! $any) {
                    $q->whereRaw('1 = 0');
                }
            });

        $candidates = $query
            // Priority first, so an admin can say "drain this one before that
            // one". Within the same priority, least-used-today first, which is
            // what rotation means in practice: an even spread rather than
            // hammering one account until it trips a provider's rate limit.
            ->orderBy('priority')
            ->orderBy('sent_today')
            ->orderBy('id')
            ->get();

        // Without the rotation feature only the single best account is used,
        // so a plan cannot quietly spread load across many providers.
        return $limits->allows('allow_smtp_rotation')
            ? $candidates
            : $candidates->take(1);
    }

    /**
     * Shared admin accounts this account is actually assigned, regardless of
     * whether they are currently sendable.
     *
     * The screens use this so a tenant sees exactly the shared accounts it may
     * use — including one that is paused or cooling down, which it needs to
     * know about — while sending uses candidatesFor(), which additionally
     * filters to what can send right now.
     *
     * @return Collection<int, SmtpAccount>
     */
    public function visibleSharedFor(Account $account): Collection
    {
        if (! PlanLimits::for($account)->allows('allow_admin_smtp')) {
            return collect();
        }

        return SmtpAccount::withoutGlobalScope(AccountScope::class)
            ->where('is_global', true)
            ->whereNull('account_id')
            ->whereExists(fn ($sub) => $this->assignmentSubquery($sub, $account))
            ->orderBy('priority')
            ->orderBy('name')
            ->get();
    }

    /**
     * Reserves one send on the first candidate that has room.
     *
     * Returns the account that was charged, or null when nothing has capacity.
     * The caller MUST call release() if the message never left this machine.
     */
    public function reserve(Account $account, ?Collection $candidates = null): ?SmtpAccount
    {
        $candidates ??= $this->candidatesFor($account);

        foreach ($candidates as $candidate) {
            if ($this->reserveOn($candidate)) {
                return $candidate->refresh();
            }
        }

        return null;
    }

    /**
     * The atomic reservation. Rollover, limit check and increment in ONE
     * statement — see the class docblock for why that matters.
     */
    public function reserveOn(SmtpAccount $account): bool
    {
        $now = Carbon::now();
        $hour = $now->copy()->startOfHour();
        $day = $now->copy()->startOfDay();
        $month = $now->copy()->startOfMonth();

        // Each window's "current" value is 0 when the window has rolled over,
        // and the stored counter otherwise. The same expression is used in the
        // WHERE (to test the limit) and the SET (to increment), so the two can
        // never disagree.
        $currentHour = '(CASE WHEN hour_reset_at IS NULL OR hour_reset_at < ? THEN 0 ELSE sent_this_hour END)';
        $currentDay = '(CASE WHEN day_reset_at IS NULL OR day_reset_at < ? THEN 0 ELSE sent_today END)';
        $currentMonth = '(CASE WHEN month_reset_at IS NULL OR month_reset_at < ? THEN 0 ELSE sent_this_month END)';

        $sql = "UPDATE smtp_accounts SET
                sent_this_hour  = {$currentHour} + 1,
                hour_reset_at   = CASE WHEN hour_reset_at IS NULL OR hour_reset_at < ? THEN ? ELSE hour_reset_at END,
                sent_today      = {$currentDay} + 1,
                day_reset_at    = CASE WHEN day_reset_at IS NULL OR day_reset_at < ? THEN ? ELSE day_reset_at END,
                sent_this_month = {$currentMonth} + 1,
                month_reset_at  = CASE WHEN month_reset_at IS NULL OR month_reset_at < ? THEN ? ELSE month_reset_at END,
                total_sent      = total_sent + 1,
                updated_at      = ?
            WHERE id = ?
              AND deleted_at IS NULL
              AND is_active = 1
              AND (cooldown_until IS NULL OR cooldown_until <= ?)
              AND (hourly_limit  IS NULL OR {$currentHour} < hourly_limit)
              AND (daily_limit   IS NULL OR {$currentDay} < daily_limit)
              AND (monthly_limit IS NULL OR {$currentMonth} < monthly_limit)";

        $bindings = [
            $hour, $hour, $hour,          // sent_this_hour CASE, then hour_reset_at CASE + value
            $day, $day, $day,
            $month, $month, $month,
            $now,                          // updated_at
            $account->id,
            $now,                          // cooldown comparison
            $hour, $day, $month,           // the three limit-check CASE expressions
        ];

        return DB::update($sql, $bindings) === 1;
    }

    /**
     * Gives a reservation back when the message never actually went out —
     * a connection refused, a TLS failure, an auth rejection. Anything the
     * provider accepted stays counted.
     *
     * GREATEST(x - 1, 0) keeps a counter from going negative if a release
     * arrives after a window rollover has already zeroed it.
     */
    public function release(SmtpAccount $account): void
    {
        DB::update(
            'UPDATE smtp_accounts SET
                sent_this_hour  = GREATEST(CAST(sent_this_hour AS SIGNED) - 1, 0),
                sent_today      = GREATEST(CAST(sent_today AS SIGNED) - 1, 0),
                sent_this_month = GREATEST(CAST(sent_this_month AS SIGNED) - 1, 0),
                total_sent      = GREATEST(CAST(total_sent AS SIGNED) - 1, 0)
             WHERE id = ?',
            [$account->id]
        );
    }

    // ------------------------------------------------------------- health

    /**
     * Records a successful send: clears the failure streak so a single blip
     * does not creep towards a cooldown over days.
     */
    public function recordSuccess(SmtpAccount $account, ?int $forAccountId = null): void
    {
        DB::table('smtp_accounts')->where('id', $account->id)->update([
            'consecutive_failures' => 0,
            'cooldown_until' => null,
            'last_success_at' => now(),
            'last_error' => null,
        ]);

        $this->bufferUsage($account->id, $forAccountId, sent: 1);
    }

    /**
     * Records an account-level failure and cools the account down once the
     * streak reaches the configured threshold.
     *
     * Returns true when this failure triggered the cooldown.
     */
    public function recordFailure(SmtpAccount $account, string $error, ?int $forAccountId = null): bool
    {
        $threshold = max(1, (int) config('knsoftic.smtp_failure_threshold', 5));
        $cooldown = max(1, (int) config('knsoftic.smtp_cooldown_minutes', 30));

        // One statement so two workers failing at once cannot both read the
        // same streak value and both write threshold-1.
        DB::update(
            'UPDATE smtp_accounts SET
                consecutive_failures = consecutive_failures + 1,
                total_failed = total_failed + 1,
                last_error = ?,
                last_error_at = ?,
                cooldown_until = CASE WHEN consecutive_failures + 1 >= ? THEN ? ELSE cooldown_until END
             WHERE id = ?',
            [
                mb_substr($this->scrub($error, $account), 0, 1000),
                now(),
                $threshold,
                now()->addMinutes($cooldown),
                $account->id,
            ]
        );

        $this->bufferUsage($account->id, $forAccountId, failed: 1);

        return (int) DB::table('smtp_accounts')->where('id', $account->id)->value('consecutive_failures') >= $threshold;
    }

    /**
     * Never let a stored error string carry the credential. Symfony's SMTP
     * exceptions quote the server dialogue, and a misconfigured server can
     * echo the AUTH line back.
     */
    protected function scrub(string $message, SmtpAccount $account): string
    {
        $secrets = array_filter([
            $account->password,
            $account->username,
            $account->password ? base64_encode((string) $account->password) : null,
            $account->username ? base64_encode((string) $account->username) : null,
        ]);

        $clean = str_replace($secrets, '[redacted]', $message);

        // Also strip anything that looks like a raw AUTH exchange.
        return (string) preg_replace('/\b(AUTH\s+(?:PLAIN|LOGIN)\s+)\S+/i', '$1[redacted]', $clean);
    }

    // -------------------------------------------------------------- usage

    /**
     * Buffers per-day usage in memory. Call flushUsage() once per batch.
     */
    public function bufferUsage(int $smtpAccountId, ?int $accountId, int $sent = 0, int $failed = 0): void
    {
        $date = now()->toDateString();
        $key = $smtpAccountId.':'.($accountId ?? 0).':'.$date;

        $this->usageBuffer[$key] ??= [
            'smtp_account_id' => $smtpAccountId,
            'account_id' => $accountId,
            'date' => $date,
            'sent' => 0,
            'failed' => 0,
        ];

        $this->usageBuffer[$key]['sent'] += $sent;
        $this->usageBuffer[$key]['failed'] += $failed;
    }

    /**
     * Writes the buffered totals with one upsert. The unique index
     * (smtp_account_id, account_id, date) makes the increment idempotent per
     * flush rather than per send.
     */
    public function flushUsage(): void
    {
        if (empty($this->usageBuffer)) {
            return;
        }

        $now = now();

        foreach (array_chunk($this->usageBuffer, 200) as $chunk) {
            $rows = array_map(fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now], $chunk);

            DB::table('smtp_usage')->upsert(
                $rows,
                ['smtp_account_id', 'account_id', 'date'],
                [
                    'sent' => DB::raw('smtp_usage.sent + VALUES(sent)'),
                    'failed' => DB::raw('smtp_usage.failed + VALUES(failed)'),
                    'updated_at' => DB::raw('VALUES(updated_at)'),
                ]
            );
        }

        $this->usageBuffer = [];
    }

    // ------------------------------------------------------------ helpers

    /**
     * An admin SMTP account reaches an account when it is assigned to
     * everyone, to that account's plan, or to the account itself.
     */
    protected function assignmentSubquery($sub, Account $account)
    {
        $planId = $account->subscription?->plan_id;

        return $sub->selectRaw(1)
            ->from('smtp_assignments')
            ->whereColumn('smtp_assignments.smtp_account_id', 'smtp_accounts.id')
            ->where(function ($q) use ($account, $planId) {
                $q->where('smtp_assignments.scope', 'all')
                    ->orWhere(fn ($a) => $a->where('smtp_assignments.scope', 'account')
                        ->where('smtp_assignments.account_id', $account->id));

                if ($planId) {
                    $q->orWhere(fn ($p) => $p->where('smtp_assignments.scope', 'plan')
                        ->where('smtp_assignments.plan_id', $planId));
                }
            });
    }
}
