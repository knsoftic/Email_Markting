<?php

namespace App\Services\Automation;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
use App\Models\CampaignLog;
use App\Models\EmailTemplate;
use App\Models\Scopes\AccountScope;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Services\Campaigns\LinkSigner;
use App\Services\Campaigns\PersonalizationEngine;
use App\Services\Contacts\SuppressionService;
use App\Services\Smtp\SendOutcome;
use App\Services\Smtp\SmtpSelector;
use App\Services\Smtp\SmtpSender;
use App\Services\Tracking\BounceRecorder;
use App\Support\PlanLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;
use Throwable;

/**
 * Advances automation runs through their steps.
 *
 * ── Claim before work ───────────────────────────────────────────────────────
 * The scheduler ticks every minute and a slow tick can still be going when the
 * next one starts. A run is claimed with a conditional UPDATE that flips it to
 * `running`; the loser's UPDATE matches nothing. Without it two ticks would
 * both execute the same send step and one subscriber would get the same email
 * twice — the failure an automation makes quietly, at scale, to everybody at
 * once.
 *
 * ── Why a step budget, and why it is not negotiable ─────────────────────────
 * Instant steps (tag, list, condition) chain: a run can legitimately execute
 * five of them in one tick and then hit a wait. But an automation whose steps
 * were built wrong can also execute forever, and a runner with no ceiling
 * turns that into a worker that never returns and a subscriber who is tagged
 * ten thousand times. MAX_STEPS_PER_TICK bounds the honest case and stops the
 * broken one; a run that hits it is rescheduled, not killed, so a long-but-
 * finite sequence still finishes on the next tick.
 *
 * ── Three ways a send can not happen, and only one is a failure ─────────────
 * Deferred (no SMTP capacity right now) is a wait — burning the run because a
 * daily limit was reached would silently drop a sequence that is fine.
 * Bounced is the end of the road for this contact, and is written to the
 * bounce log so the address stops being mailed everywhere, not just here.
 * Anything else is a real failure and says so on the run.
 */
class AutomationRunner
{
    /** Instant steps one run may chain through in a single tick. */
    public const MAX_STEPS_PER_TICK = 20;

    /** Runs claimed per tick. */
    public const BATCH = 100;

    /** A claim older than this belonged to a worker that died. */
    public const LEASE_MINUTES = 15;

    public function __construct(
        protected SuppressionService $suppressions,
        protected PersonalizationEngine $personalization,
        protected LinkSigner $links,
        protected SmtpSender $sender,
        protected SmtpSelector $selector,
        protected BounceRecorder $bounces,
        protected AutomationEnroller $enroller,
        protected AutomationTrigger $triggers,
    ) {}

    /**
     * Advances every run that is due.
     *
     * @return array{claimed: int, advanced: int, completed: int, sent: int, failed: int, deferred: int}
     */
    public function tick(int $limit = self::BATCH): array
    {
        $result = ['claimed' => 0, 'advanced' => 0, 'completed' => 0, 'sent' => 0, 'failed' => 0, 'deferred' => 0];

        $this->reclaimStaleRuns();

        $runs = AutomationRun::withoutGlobalScope(AccountScope::class)
            ->where('status', 'waiting')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit(max(1, $limit))
            ->get();

        foreach ($runs as $run) {
            if (! $this->claim($run)) {
                continue;
            }

            $result['claimed']++;

            try {
                $outcome = $this->advance($run);

                $result['advanced'] += $outcome['steps'];
                $result['sent'] += $outcome['sent'];
                $result['deferred'] += $outcome['deferred'];
                $result['completed'] += $outcome['completed'] ? 1 : 0;
            } catch (Throwable $e) {
                $result['failed']++;
                $this->fail($run, $e->getMessage());
            }
        }

        // Buffered per-day SMTP usage, written once for the whole tick rather
        // than once per message.
        $this->sender->flush();

        return $result;
    }

    /**
     * Runs one claimed run forward until it waits, ends, or spends its budget.
     *
     * @return array{steps: int, sent: int, deferred: int, completed: bool}
     */
    public function advance(AutomationRun $run): array
    {
        // withoutGlobalScope(AccountScope::class), never withoutGlobalScopes():
        // the plural form strips SoftDeletingScope too, and a deleted
        // automation that keeps mailing people is the worst kind of bug —
        // the operator has already done the one thing that should stop it.
        $automation = Automation::withoutGlobalScope(AccountScope::class)->find($run->automation_id);

        if ($automation === null) {
            // Deleted. Parking would leave the run waking every fifteen
            // minutes forever for an automation nobody can see any more.
            $this->enroller->cancel($run, 'The automation was deleted.');

            return $this->tally();
        }

        if ($automation->status !== 'active') {
            // A paused automation stops where it is. The run keeps its place,
            // so resuming continues the sequence rather than restarting it.
            $this->park($run, now()->addMinutes(15));

            return $this->tally();
        }

        $subscriber = Subscriber::withoutGlobalScope(AccountScope::class)->find($run->subscriber_id);

        if ($subscriber === null) {
            $this->enroller->cancel($run, 'The contact no longer exists.');

            return $this->tally();
        }

        $steps = 0;
        $sent = 0;
        $deferred = 0;

        while ($steps < self::MAX_STEPS_PER_TICK) {
            $step = $run->current_step_id
                ? AutomationStep::query()
                    ->where('automation_id', $automation->id)
                    ->whereKey($run->current_step_id)
                    ->first()
                : null;

            if ($step === null) {
                // Nothing left to do — including the case where the step this
                // run was sitting on was deleted from the automation.
                $this->complete($run);

                return $this->tally($steps, $sent, $deferred, completed: true);
            }

            $outcome = $this->execute($automation, $run, $step, $subscriber);
            $steps++;
            $sent += $outcome['sent'] ? 1 : 0;
            $deferred += $outcome['deferred'] ? 1 : 0;

            // The step settled the run's fate itself (cancelled, completed, or
            // parked for capacity) and has already saved it.
            if ($outcome['stop']) {
                return $this->tally($steps, $sent, $deferred, completed: $outcome['completed']);
            }

            if ($outcome['wait_until'] !== null) {
                $run->forceFill([
                    'status' => 'waiting',
                    'next_run_at' => $outcome['wait_until'],
                    'current_step_id' => $outcome['next_step_id'],
                    'steps_completed' => $run->steps_completed + 1,
                ])->save();

                return $this->tally($steps, $sent, $deferred);
            }

            $run->forceFill([
                'current_step_id' => $outcome['next_step_id'],
                'steps_completed' => $run->steps_completed + 1,
            ])->save();

            if ($outcome['next_step_id'] === null) {
                $this->complete($run);

                return $this->tally($steps, $sent, $deferred, completed: true);
            }
        }

        // Budget spent on a run that is still going. Rescheduled rather than
        // killed: a long-but-finite sequence finishes on the next tick, and a
        // broken one stops eating this worker.
        $this->park($run, now()->addMinute());

        return $this->tally($steps, $sent, $deferred);
    }

    /**
     * Performs one step.
     *
     * @return array{sent: bool, deferred: bool, stop: bool, completed: bool, wait_until: ?Carbon, next_step_id: ?int}
     */
    protected function execute(Automation $automation, AutomationRun $run, AutomationStep $step, Subscriber $subscriber): array
    {
        $config = (array) ($step->config ?? []);
        $next = $this->nextStepId($automation, $step);

        switch ($step->type) {
            case 'wait':
                return $this->result(wait: $step->waitUntil() ?? now(), next: $next);

            case 'send_email':
                return $this->sendEmail($automation, $run, $step, $subscriber, $config, $next);

            case 'add_tag':
                $this->applyTag($subscriber, $config, attach: true);

                return $this->result(next: $next);

            case 'remove_tag':
                $this->applyTag($subscriber, $config, attach: false);

                return $this->result(next: $next);

            case 'move_list':
                $this->moveList($subscriber, $config);

                return $this->result(next: $next);

            case 'unsubscribe':
                // Ends the run: there is nothing sensible left to do to
                // somebody the automation has just opted out.
                $this->suppressions->suppress(
                    $subscriber->email,
                    'unsubscribed',
                    null,
                    'Opted out by the automation "'.$automation->name.'".',
                    'automation',
                    $automation->account_id,
                    log: false,
                );

                $this->complete($run);

                return $this->result(stop: true, completed: true);

            case 'condition':
                return $this->evaluateCondition($automation, $run, $subscriber, $config, $next);

            default:
                // An unknown step type is a data problem, not a reason to mail
                // somebody. Stepping over it keeps the sequence moving.
                return $this->result(next: $next);
        }
    }

    /**
     * A condition either lets the run continue or ends it.
     *
     * The steps are a numbered line, not a graph — `position` says so — and a
     * line has two honest answers: carry on, or stop here. `if_false: skip`
     * adds the one branch a line can express, stepping over the single step
     * that follows ("if they already bought, skip the reminder"). Inventing a
     * second path through a list that has no second path is how a builder ends
     * up drawing a shape the runner does not walk.
     *
     * @param  array<string, mixed>  $config
     * @return array{sent: bool, deferred: bool, stop: bool, completed: bool, wait_until: ?Carbon, next_step_id: ?int}
     */
    protected function evaluateCondition(
        Automation $automation,
        AutomationRun $run,
        Subscriber $subscriber,
        array $config,
        ?int $next,
    ): array {
        if ($this->conditionHolds($subscriber, $config)) {
            return $this->result(next: $next);
        }

        if (($config['if_false'] ?? 'end') === 'skip') {
            $skipped = $next ? AutomationStep::query()->whereKey($next)->first() : null;

            return $this->result(next: $skipped ? $this->nextStepId($automation, $skipped) : null);
        }

        $this->complete($run);

        return $this->result(stop: true, completed: true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function conditionHolds(Subscriber $subscriber, array $config): bool
    {
        return match ((string) ($config['check'] ?? '')) {
            'has_tag' => $subscriber->tags()->whereKey((int) ($config['tag_id'] ?? 0))->exists(),
            'on_list' => $subscriber->lists()->whereKey((int) ($config['list_id'] ?? 0))->exists(),

            'opened_campaign' => DB::table('campaign_recipients')
                ->where('subscriber_id', $subscriber->id)
                ->when($config['campaign_id'] ?? null, fn ($q, $id) => $q->where('campaign_id', (int) $id))
                ->whereNotNull('first_opened_at')->exists(),

            'clicked_campaign' => DB::table('campaign_recipients')
                ->where('subscriber_id', $subscriber->id)
                ->when($config['campaign_id'] ?? null, fn ($q, $id) => $q->where('campaign_id', (int) $id))
                ->whereNotNull('first_clicked_at')->exists(),

            // An unrecognised check must not silently pass. Not-met is the
            // safe answer: the run stops rather than mailing on a condition
            // nobody can evaluate.
            default => false,
        };
    }

    /**
     * Sends one step's email.
     *
     * @param  array<string, mixed>  $config
     * @return array{sent: bool, deferred: bool, stop: bool, completed: bool, wait_until: ?Carbon, next_step_id: ?int}
     */
    protected function sendEmail(
        Automation $automation,
        AutomationRun $run,
        AutomationStep $step,
        Subscriber $subscriber,
        array $config,
        ?int $next,
    ): array {
        // The check that matters most in this whole class. Somebody enrolled
        // three weeks ago can have opted out yesterday, and the only correct
        // answer to that is to stop — not to finish the sequence they left.
        if ($this->suppressions->isSuppressed($subscriber->email, $automation->account_id)) {
            $this->enroller->cancel($run, 'The contact opted out while this automation was running.');

            return $this->result(stop: true);
        }

        if ($subscriber->status !== 'active') {
            $this->enroller->cancel($run, 'The contact is no longer active.');

            return $this->result(stop: true);
        }

        $account = $automation->account;

        if (! PlanLimits::for($account)->hasRoomFor('max_emails_per_month')) {
            // Not a failure — a wait. The allowance resets, and killing the
            // run because the month ran out would drop the rest of a sequence
            // the subscriber is halfway through.
            $this->park($run, now()->addHours(6));

            return $this->result(deferred: true, stop: true);
        }

        $message = $this->compose($automation, $step, $subscriber, $config);

        $outcome = $this->sender->send($account, $message);

        if ($outcome->deferred) {
            // Every SMTP account is at its limit. Same reasoning as above.
            $this->park($run, now()->addMinutes(30));

            return $this->result(deferred: true, stop: true);
        }

        if (! $outcome->sent) {
            return $this->rejected($automation, $run, $subscriber, $outcome, $message);
        }

        PlanLimits::for($account)->increment('emails_sent');

        DB::update('UPDATE automation_steps SET sent_count = sent_count + 1, updated_at = ? WHERE id = ?',
            [now(), $step->id]);
        DB::update('UPDATE automations SET emails_sent = emails_sent + 1, updated_at = ? WHERE id = ?',
            [now(), $automation->id]);

        $this->log($automation, $subscriber, $message, 'sent', null, $outcome->smtpAccount?->id);

        return $this->result(sent: true, next: $next);
    }

    /**
     * Builds the message for one send step.
     *
     * @param  array<string, mixed>  $config
     */
    protected function compose(Automation $automation, AutomationStep $step, Subscriber $subscriber, array $config): MimeEmail
    {
        $template = $step->email_template_id
            ? EmailTemplate::withoutGlobalScope(AccountScope::class)->find($step->email_template_id)
            : null;

        $html = (string) ($config['html'] ?? '') ?: (string) ($template?->html ?? '');
        $subject = (string) ($config['subject'] ?? '') ?: (string) ($template?->subject ?? '');

        if (trim(strip_tags($html)) === '' || trim($subject) === '') {
            throw new \RuntimeException(
                'The step "'.($step->label ?: $step->type).'" has no subject or content to send.'
            );
        }

        $plain = (string) ($config['plain_text'] ?? '') ?: (string) ($template?->plain_text ?? '');

        $message = (new MimeEmail)
            ->from($this->fromAddress($automation))
            ->to($subscriber->email)
            ->subject($this->personalization->renderText($subject, $subscriber))
            ->html($this->personalization->render($html, $subscriber))
            ->text($this->personalization->renderText($plain !== '' ? $plain : strip_tags($html), $subscriber));

        $headers = $message->getHeaders();

        // The same opt-out affordances a campaign carries. An automated email
        // is still marketing email: without the one-click header the reader
        // who wants out reaches for "report spam" instead, which costs the
        // sender far more than the unsubscribe would have.
        foreach ($this->links->listUnsubscribeHeaders($subscriber) as $name => $value) {
            $headers->addTextHeader($name, $value);
        }

        // Says plainly what this message is: bulk, and generated by a machine.
        // Both headers exist so auto-responders on the other side do not reply
        // to it — the honest thing to declare, and also the thing that stops
        // two robots writing to each other all afternoon.
        $headers->addTextHeader('Precedence', 'bulk');
        $headers->addTextHeader('Auto-Submitted', 'auto-generated');

        return $message;
    }

    /**
     * The From address for an automation's mail.
     *
     * The automation's own from address wins. Where it has none, the SMTP
     * account's from address is used — not a made-up default: it is the
     * address the provider has actually authorised this sender to use, so it
     * is the one that will not be rejected. If there is no SMTP account at all
     * there is nothing honest to put here, and the step fails saying so rather
     * than sending from a localhost address no recipient could ever reply to.
     */
    protected function fromAddress(Automation $automation): Address
    {
        $email = trim((string) $automation->from_email);
        $name = trim((string) $automation->from_name);

        if ($email !== '') {
            return new Address($email, $name);
        }

        $smtp = $automation->smtp_account_id
            ? SmtpAccount::withoutGlobalScope(AccountScope::class)->find($automation->smtp_account_id)
            : $this->selector->candidatesFor($automation->account)->first();

        if ($smtp === null) {
            throw new \RuntimeException(
                'This automation has no sender address, and there is no SMTP account to take one from. '
                .'Set a "from" address on the automation.'
            );
        }

        return new Address((string) $smtp->from_email, $name !== '' ? $name : (string) $smtp->from_name);
    }

    /**
     * The server refused the message.
     *
     * A bounce ends this contact's run and is written to the bounce log, so an
     * automation cannot keep mailing a dead address every week while the log
     * shows nothing — and so the operator's hard-bounce threshold counts these
     * bounces alongside the campaign ones. Anything else is our problem — bad
     * credentials, a dropped connection — and is recorded on the run, never
     * against the contact.
     *
     * @return array{sent: bool, deferred: bool, stop: bool, completed: bool, wait_until: ?Carbon, next_step_id: ?int}
     */
    protected function rejected(
        Automation $automation,
        AutomationRun $run,
        Subscriber $subscriber,
        SendOutcome $outcome,
        MimeEmail $message,
    ): array {
        $bounce = $outcome->bounceType();

        $this->log($automation, $subscriber, $message, $bounce ? 'bounced' : 'failed',
            (string) $outcome->reason, $outcome->smtpAccount?->id);

        if ($bounce === null) {
            throw new \RuntimeException((string) ($outcome->reason ?: 'The automation email could not be sent.'));
        }

        $this->bounces->recordStandalone($automation->account_id, $subscriber, $outcome, 'automation');

        if ($bounce === 'hard') {
            $this->enroller->cancel($run, 'The address bounced: '.mb_substr((string) $outcome->reason, 0, 200));

            return $this->result(stop: true);
        }

        // A soft bounce is a full mailbox or a server having a bad day. The
        // address is real, so the step is retried rather than abandoned.
        $this->park($run, now()->addHours(4));

        return $this->result(deferred: true, stop: true);
    }

    // ------------------------------------------------------------- mechanics

    /**
     * Claims a run for this tick. Exactly one process can win.
     */
    protected function claim(AutomationRun $run): bool
    {
        $won = DB::update(
            "UPDATE automation_runs SET status = 'running', updated_at = ?
              WHERE id = ? AND status = 'waiting'",
            [now(), $run->id]
        ) === 1;

        if ($won) {
            // The claim was raw SQL, so the model in memory still believes it
            // is `waiting`. Without this, every later write of `waiting` looks
            // unchanged to Eloquent and is dropped from the UPDATE — leaving
            // the row stuck on `running` until the stale-claim sweep notices,
            // fifteen minutes later, every single time a run is parked.
            $run->setAttribute('status', 'running')->syncOriginalAttribute('status');
        }

        return $won;
    }

    /**
     * Returns runs whose worker died mid-step. Without this a crash would
     * leave a subscriber stuck in `running` forever, with nothing anywhere to
     * notice that their sequence had stopped.
     */
    protected function reclaimStaleRuns(): void
    {
        DB::update(
            "UPDATE automation_runs
                SET status = 'waiting', next_run_at = ?, updated_at = ?
              WHERE status = 'running' AND updated_at < ?",
            [now(), now(), now()->subMinutes(self::LEASE_MINUTES)]
        );
    }

    /** Puts a claimed run back to waiting, on the same step, for later. */
    protected function park(AutomationRun $run, Carbon $until): void
    {
        $run->forceFill(['status' => 'waiting', 'next_run_at' => $until])->save();
    }

    protected function complete(AutomationRun $run): void
    {
        $run->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'next_run_at' => null,
            'current_step_id' => null,
        ])->save();

        DB::update('UPDATE automations SET completed_count = completed_count + 1, updated_at = ? WHERE id = ?',
            [now(), $run->automation_id]);
    }

    protected function fail(AutomationRun $run, string $reason): void
    {
        $run->forceFill([
            'status' => 'failed',
            'next_run_at' => null,
            'last_error' => mb_substr($reason, 0, 1000),
        ])->save();
    }

    /**
     * One row in the email log.
     *
     * `campaign_logs` has always carried an `automation` type and nothing ever
     * wrote one, so the log screen would have shown campaign mail only while
     * an automation quietly sent thousands of messages beside it — the exact
     * gap somebody hits when they ask "did this person get the email?".
     */
    protected function log(
        Automation $automation,
        Subscriber $subscriber,
        MimeEmail $message,
        string $status,
        ?string $error,
        ?int $smtpId,
    ): void {
        CampaignLog::withoutGlobalScope(AccountScope::class)->create([
            'account_id' => $automation->account_id,
            'campaign_id' => null,
            'campaign_recipient_id' => null,
            'subscriber_id' => $subscriber->id,
            'smtp_account_id' => $smtpId,
            'recipient_email' => $subscriber->email,
            'sender_email' => $message->getFrom()[0]?->getAddress(),
            'subject' => mb_substr((string) $message->getSubject(), 0, 255),
            'type' => 'automation',
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 1000) : null,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }

    protected function nextStepId(Automation $automation, AutomationStep $step): ?int
    {
        $id = AutomationStep::query()
            ->where('automation_id', $automation->id)
            ->where('position', '>', $step->position)
            ->orderBy('position')
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function applyTag(Subscriber $subscriber, array $config, bool $attach): void
    {
        $tag = Tag::withoutGlobalScope(AccountScope::class)
            ->where('account_id', $subscriber->account_id)
            ->find((int) ($config['tag_id'] ?? 0));

        if ($tag === null) {
            return;
        }

        if (! $attach) {
            $subscriber->tags()->detach($tag->id);

            return;
        }

        // Only a tag the contact did not already carry is news. Re-attaching
        // one they have is not an event, and firing for it would let a
        // re-runnable automation re-arm itself off its own no-op.
        if ($subscriber->tags()->whereKey($tag->id)->exists()) {
            return;
        }

        $subscriber->tags()->attach($tag->id);

        // An automation step is as real an event as a person clicking Save, so
        // it feeds the trigger layer the same way. Without this, "tag them" in
        // one automation could never start a second automation listening for
        // that tag, and the builder would be offering a chain it never walks.
        //
        // Passed allowReentry: false, and that is what makes the chain safe to
        // have at all. A subscriber can hold one run per automation, so
        // A-tags-B-tags-A normally stops dead at the second hop on the unique
        // index. Re-entry would defeat that: two automations that tag each
        // other, both set to allow re-entry, would re-arm one another one hop
        // per tick, for ever, mailing the same person every minute.
        //
        // Re-entry stays exactly what it is for: something a NEW real-world
        // event triggers — a person re-subscribing, an operator re-tagging —
        // never something the automations do to each other.
        $this->triggers->tagsAdded($subscriber->account_id, [$subscriber->id], [$tag->id], allowReentry: false);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function moveList(Subscriber $subscriber, array $config): void
    {
        $to = SubscriberList::withoutGlobalScope(AccountScope::class)
            ->where('account_id', $subscriber->account_id)
            ->find((int) ($config['list_id'] ?? 0));

        if ($to === null) {
            return;
        }

        if ($from = (int) ($config['from_list_id'] ?? 0)) {
            $subscriber->lists()->detach($from);
        }

        if ($subscriber->lists()->whereKey($to->id)->exists()) {
            return;
        }

        $subscriber->lists()->attach($to->id, ['subscribed_at' => now()]);

        // Same reasoning as applyTag, including the re-entry ban that keeps a
        // pair of automations from feeding each other forever.
        $this->triggers->listJoined($subscriber->account_id, [$subscriber->id], $to->id, allowReentry: false);
    }

    /**
     * @return array{steps: int, sent: int, deferred: int, completed: bool}
     */
    protected function tally(int $steps = 0, int $sent = 0, int $deferred = 0, bool $completed = false): array
    {
        return ['steps' => $steps, 'sent' => $sent, 'deferred' => $deferred, 'completed' => $completed];
    }

    /**
     * @return array{sent: bool, deferred: bool, stop: bool, completed: bool, wait_until: ?Carbon, next_step_id: ?int}
     */
    protected function result(
        bool $sent = false,
        bool $deferred = false,
        bool $stop = false,
        bool $completed = false,
        ?Carbon $wait = null,
        ?int $next = null,
    ): array {
        return [
            'sent' => $sent,
            'deferred' => $deferred,
            'stop' => $stop,
            'completed' => $completed,
            'wait_until' => $wait,
            'next_step_id' => $next,
        ];
    }
}
