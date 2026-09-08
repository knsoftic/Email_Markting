<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Scopes\AccountScope;
use App\Models\SmtpAccount;
use App\Models\SmtpAssignment;
use App\Services\Smtp\SmtpSelector;
use App\Support\PlanLimits;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Says which accounts can send, and for the ones that cannot, why.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 * Whether a tenant can send is decided by four separate things agreeing: a
 * global SMTP account exists and is usable, an assignment row reaches this
 * tenant, the tenant's plan allows admin SMTP, and the tenant is not
 * suspended. Each of those lives on a different screen, and when one is wrong
 * the symptom is the same everywhere — an empty "Provided by …" section and a
 * campaign that will not leave. From the outside there is no way to tell which
 * of the four it was.
 *
 * The overwhelmingly common one is the second. Creating a platform SMTP
 * account is not the same as sharing it: without an assignment it reaches
 * nobody, and the only hint is a "Reaching nobody" label on a list the
 * operator has already navigated away from. This prints the whole chain at
 * once and names the broken link.
 *
 * ── It is read-only, and safe on a live server ──────────────────────────────
 * It writes nothing and it prints no credentials — host and port only, never a
 * username or password — so its output can be pasted into a support thread.
 *
 * Exits non-zero when at least one active account has no way to send at all,
 * so it is usable as a health check rather than only by eye.
 */
class CheckSmtpRouting extends Command
{
    protected $signature = 'kn:smtp-check
                            {account? : Only this account, by id or slug. Defaults to all of them.}';

    protected $description = 'Show which accounts can send email, and why the others cannot';

    public function handle(SmtpSelector $selector): int
    {
        // This walks every account deliberately, so the strict-mode guard that
        // is right for a web request would only turn a diagnostic into a crash.
        Model::preventLazyLoading(false);

        $this->globals();
        $this->assignments();

        return $this->accounts($selector);
    }

    // ------------------------------------------------------------- section 1

    protected function globals(): void
    {
        $this->heading('Platform SMTP accounts (Admin → Admin SMTP)');

        $globals = SmtpAccount::withoutGlobalScope(AccountScope::class)
            ->where('is_global', true)
            ->orderBy('id')
            ->get();

        if ($globals->isEmpty()) {
            $this->line('  <fg=red>None.</> Nobody can send through a platform account, because there is not one.');
            $this->line('  Fix: Admin → Admin SMTP → Add, then set its assignment.');

            return;
        }

        foreach ($globals as $smtp) {
            $problems = [];

            // A global account belongs to the platform, not to a tenant. One
            // that carries an account_id is invisible to the queries that look
            // for shared accounts, and it is not obvious from any screen.
            if ($smtp->account_id !== null) {
                $problems[] = "belongs to account #{$smtp->account_id} — a global account must not";
            }

            if (! $smtp->is_active) {
                $problems[] = 'paused';
            }

            if ($smtp->cooldown_until && $smtp->cooldown_until->isFuture()) {
                $problems[] = 'in cooldown until '.$smtp->cooldown_until->format('j M H:i');
            }

            $count = SmtpAssignment::where('smtp_account_id', $smtp->id)->count();

            if ($count === 0) {
                $problems[] = 'NO ASSIGNMENT — reaches nobody';
            }

            $this->line(sprintf(
                '  #%-4d %-30s %s',
                $smtp->id,
                $this->clip($smtp->name, 30),
                $problems === []
                    ? "<fg=green>ok</> · {$count} assignment(s) · {$smtp->host}:{$smtp->port}"
                    : '<fg=red>'.implode('; ', $problems).'</>'
            ));
        }
    }

    // ------------------------------------------------------------- section 2

    protected function assignments(): void
    {
        $this->heading('Assignments (who each platform account reaches)');

        $assignments = SmtpAssignment::with(['plan', 'account'])->orderBy('smtp_account_id')->get();

        if ($assignments->isEmpty()) {
            $this->line('  <fg=red>None.</> This is the usual cause.');
            $this->line('  Creating a platform SMTP account does not share it. Open it in');
            $this->line('  Admin → Admin SMTP and set the assignment scope: everybody, a plan,');
            $this->line('  or named accounts.');

            return;
        }

        foreach ($assignments as $row) {
            $this->line(sprintf('  smtp #%-4d %-9s %s', $row->smtp_account_id, $row->scope, match ($row->scope) {
                'all' => 'every account',
                'plan' => 'plan: '.$this->target($row->plan, $row->plan_id, 'plan'),
                'account' => 'account: '.$this->target($row->account, $row->account_id, 'account'),
                default => $row->scope,
            }));
        }
    }

    /**
     * A soft-deleted plan is not a broken assignment: the accounts already on
     * it keep it, and the selector matches on plan_id without looking at
     * deleted_at. So it is reported as still working, marked deleted — saying
     * "no longer exists" about a live arrangement is how a diagnostic sends
     * somebody to fix the wrong thing.
     */
    protected function target(?Model $target, ?int $id, string $noun): string
    {
        if (! $target) {
            return "<fg=red>{$noun} #{$id} no longer exists</>";
        }

        return $target->name.($target->trashed() ? ' <fg=yellow>(deleted — still matches accounts already on it)</>' : '');
    }

    // ------------------------------------------------------------- section 3

    protected function accounts(SmtpSelector $selector): int
    {
        $this->heading('Accounts');

        $query = Account::withoutGlobalScope(AccountScope::class)->with('subscription.plan');

        if ($needle = $this->argument('account')) {
            $query->where(fn ($q) => $q->where('id', $needle)->orWhere('slug', $needle));
        }

        $accounts = $query->orderBy('name')->get();

        if ($accounts->isEmpty()) {
            $this->warn($needle ? "No account matches \"{$needle}\"." : 'There are no accounts yet.');

            return self::SUCCESS;
        }

        $stuck = 0;

        foreach ($accounts as $account) {
            $limits = PlanLimits::for($account);
            $canSend = $selector->candidatesFor($account)->count();
            $shared = $selector->visibleSharedFor($account)->count();
            $own = SmtpAccount::withoutGlobalScope(AccountScope::class)
                ->where('account_id', $account->id)->where('is_global', false)->count();

            $this->newLine();
            $this->line(sprintf(
                '  <options=bold>%s</>  plan: %s%s',
                $this->clip($account->name, 34),
                $account->subscription?->plan?->name ?? '<fg=red>none assigned</>',
                $account->isActive() ? '' : '  <fg=yellow>['.$account->status.']</>'
            ));

            $this->line(sprintf(
                '      own SMTP: %d (%s)   platform SMTP visible: %d   can send through: %s',
                $own,
                $limits->allows('allow_custom_smtp') ? 'allowed' : 'not in plan',
                $shared,
                $canSend > 0 ? "<fg=green>{$canSend}</>" : '<fg=red>0</>'
            ));

            if ($canSend === 0 && $account->isActive()) {
                $stuck++;
            }

            foreach ($this->reasons($account, $limits, $own, $shared, $canSend) as $reason) {
                $this->line("      <fg=yellow>→</> {$reason}");
            }
        }

        $this->newLine();

        if ($stuck > 0) {
            $this->error("{$stuck} active account(s) cannot send at all.");

            return self::FAILURE;
        }

        $this->info('Every active account has at least one way to send.');

        return self::SUCCESS;
    }

    /**
     * The specific reason, not a list of possibilities. Ordered so the first
     * line printed is the one to act on.
     *
     * @return list<string>
     */
    protected function reasons(Account $account, PlanLimits $limits, int $own, int $shared, int $canSend): array
    {
        $reasons = [];

        if (! $account->subscription) {
            $reasons[] = 'No subscription, so no plan limits apply. Assign a plan in Admin → Accounts.';
        }

        if (! $account->isActive()) {
            $reasons[] = "Account is {$account->status}: nothing sends for it whatever the SMTP setup says.";
        }

        if (! $limits->allows('allow_admin_smtp') && ! $limits->allows('allow_custom_smtp')) {
            $reasons[] = 'The plan allows neither its own SMTP nor the platform\'s — this account has no route out by design. Switch one on in Admin → Plans.';
        } elseif ($shared === 0 && $limits->allows('allow_admin_smtp')) {
            $reasons[] = 'No assignment reaches this account. Assign a platform SMTP to it, to its plan, or to everybody.';
        }

        if ($own === 0 && $limits->allows('allow_custom_smtp') && $shared === 0) {
            $reasons[] = 'It may add its own SMTP but has not: SMTP Accounts → Add.';
        }

        // Reachable on paper but nothing is sendable right now — a different
        // problem with the same symptom, and worth separating.
        if ($canSend === 0 && ($shared > 0 || $own > 0)) {
            $reasons[] = 'Every account it can reach is paused or in cooldown.';
        }

        return $reasons;
    }

    // ---------------------------------------------------------------- output

    protected function heading(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
        $this->line(str_repeat('─', 72));
    }

    protected function clip(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1).'…' : $value;
    }
}
