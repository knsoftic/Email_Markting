<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\CampaignRecipient;
use App\Models\CampaignVariant;
use App\Models\Subscriber;
use App\Notifications\AccountNotifier;
use App\Notifications\CampaignCompleted;
use App\Services\Contacts\SuppressionService;
use App\Services\Smtp\SmtpSender;
use App\Services\Tracking\BounceRecorder;
use App\Support\PlanLimits;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Sends one chunk of a campaign.
 *
 * Everything here exists to survive several workers running at once, and a
 * worker dying at any point. The rules that follow are each guarding against
 * a specific way this goes wrong:
 *
 *  1. CLAIM BEFORE READ. Rows are flipped to `sending` with this worker's
 *     token in one UPDATE, then read back by that token. A worker never sees a
 *     row another worker is holding, so nobody is mailed twice.
 *
 *  2. SUPPRESSION IS RE-CHECKED AT SEND TIME. Generation filters the audience,
 *     but a 100k send takes hours; anyone who opts out during it still has a
 *     row. Filtering only at generation is a compliance hole, so every chunk
 *     re-checks the suppression list in one query before sending.
 *
 *  3. NO CAPACITY IS NOT A FAILURE. When every SMTP account is at its limit
 *     the rows go back to `pending` and the campaign stays `sending`. Marking
 *     them failed would silently drop people because a daily quota ran out.
 *
 *  4. COUNTERS ARE ATOMIC. Increments only — never read-modify-write — or two
 *     workers overwrite each other and the progress bar lies.
 *
 *  5. COMPLETION IS A CONDITIONAL UPDATE. It fires only when no row is left
 *     pending or sending, so it can neither fire early ("Completed — 0 sent")
 *     nor fire twice.
 */
class CampaignRunner
{
    /** Recipients claimed per pass when the config says nothing. */
    public const CHUNK = 100;

    /**
     * Upper bound on a chunk. A claim is held for the whole pass, so a huge
     * chunk makes Pause take minutes to bite and leaves more work stranded if
     * the worker dies mid-pass.
     */
    public const MAX_CHUNK = 1000;

    /** A claim older than this is assumed to belong to a dead worker. */
    public const LEASE_MINUTES = 15;

    public function __construct(
        protected SmtpSender $sender,
        protected MessageBuilder $messages,
        protected SuppressionService $suppressions,
        protected BounceRecorder $bounces,
        protected AbTestService $ab,
    ) {}

    /**
     * Processes up to one chunk.
     *
     * @return array{claimed:int, sent:int, failed:int, deferred:int, skipped:int, finished:bool, paused:bool, awaiting_decision:bool}
     */
    public function runChunk(Campaign $campaign): array
    {
        $result = ['claimed' => 0, 'sent' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => 0,
            'finished' => false, 'paused' => false, 'awaiting_decision' => false];

        $campaign->refresh();

        if (in_array($campaign->status, ['paused', 'cancelled', 'completed', 'failed'], true)) {
            $result['paused'] = $campaign->status === 'paused';

            return $result;
        }

        $this->reclaimStaleLeases($campaign);

        $token = (string) Str::uuid();
        $result['claimed'] = $this->claim($campaign, $token);

        if ($result['claimed'] === 0) {
            $result['finished'] = $this->tryFinalise($campaign);

            // Nothing claimable and rows still pending means the holdback is
            // waiting on the split test's decision. Saying so is what stops
            // the chunk job re-queueing itself every second for four hours.
            $result['awaiting_decision'] = ! $result['finished'] && $this->ab->isAwaitingDecision($campaign);

            return $result;
        }

        $recipients = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('locked_by', $token)
            ->with('subscriber')
            ->get();

        // Rule 2: one suppression lookup for the whole chunk.
        $suppressed = $this->suppressions->suppressedMap(
            $recipients->pluck('email')->all(),
            $campaign->account_id
        );

        $blueprints = $this->blueprints($campaign, $recipients);
        $limits = PlanLimits::for($campaign->account);

        // Where the monthly allowance stood before this chunk. Comparing it
        // with where it stands afterwards is what lets the allowance warning
        // fire on the one chunk that crossed a threshold, instead of on every
        // chunk for the rest of the month.
        $allowanceBefore = $limits->usageFor('max_emails_per_month');

        foreach ($recipients as $recipient) {
            // A pause pressed mid-chunk takes effect on the next recipient,
            // not at the end of the chunk.
            if ($campaign->fresh()?->status === 'paused') {
                $this->release($campaign, $token);
                $result['paused'] = true;
                break;
            }

            if (isset($suppressed[mb_strtolower($recipient->email)])) {
                $this->markSkipped($recipient, 'Unsubscribed or suppressed before this message was sent.');
                $result['skipped']++;

                continue;
            }

            // The monthly plan ceiling is checked per message, not once at the
            // start, so a long send cannot walk past it.
            if (! $limits->hasRoomFor('max_emails_per_month')) {
                $this->releaseOne($recipient);
                $result['deferred']++;
                break;
            }

            $outcome = $this->sendOne(
                $campaign, $recipient,
                $blueprints[(int) $recipient->campaign_variant_id] ?? $blueprints[0]
            );

            match (true) {
                $outcome === 'sent' => $result['sent']++,
                $outcome === 'deferred' => $result['deferred']++,
                default => $result['failed']++,
            };

            if ($outcome === 'deferred') {
                // Nothing has capacity; stop the chunk rather than churning
                // through every remaining recipient to no purpose.
                $this->release($campaign, $token);
                break;
            }
        }

        $this->sender->flush();
        $this->release($campaign, $token);

        AccountNotifier::sendingAllowance($campaign->account, $allowanceBefore);

        $result['finished'] = $this->tryFinalise($campaign);

        return $result;
    }

    // ------------------------------------------------------------- claiming

    /**
     * Rule 1. One statement flips a slice of pending rows to this worker.
     * MySQL applies UPDATE ... ORDER BY ... LIMIT atomically, so two workers
     * cannot claim the same row.
     */
    protected function claim(Campaign $campaign, string $token): int
    {
        // A split test that has not decided yet may only send its sample. The
        // holdback carries no variant, and there is nothing to put in their
        // message until the winner is known — claiming them would send the
        // whole list the control copy and make the test meaningless.
        $holdBack = $campaign->is_ab_test && $campaign->ab_decided_at === null
            ? ' AND campaign_variant_id IS NOT NULL'
            : '';

        return DB::update(
            'UPDATE campaign_recipients
                SET status = ?, locked_by = ?, locked_at = ?, attempts = attempts + 1, updated_at = ?
              WHERE campaign_id = ? AND status = ? AND locked_by IS NULL'.$holdBack.'
              ORDER BY id
              LIMIT '.$this->chunkSize(),
            ['sending', $token, now(), now(), $campaign->id, 'pending']
        );
    }

    /**
     * One compiled blueprint per variant in this chunk, keyed by variant id
     * (0 is the campaign's own copy).
     *
     * Compiling per variant rather than per message keeps the cost at one or
     * two compilations a chunk instead of one per recipient — the whole reason
     * the blueprint exists.
     *
     * @param  Collection<int, CampaignRecipient>  $recipients
     * @return array<int, array<string, mixed>>
     */
    protected function blueprints(Campaign $campaign, $recipients): array
    {
        $blueprints = [0 => $this->messages->blueprint($campaign)];

        if (! $campaign->is_ab_test) {
            return $blueprints;
        }

        $ids = $recipients->pluck('campaign_variant_id')->filter()->unique();

        if ($ids->isEmpty()) {
            return $blueprints;
        }

        foreach (CampaignVariant::query()->whereIn('id', $ids->all())->get() as $variant) {
            $blueprints[(int) $variant->id] = $this->messages->blueprint($campaign, $variant);
        }

        return $blueprints;
    }

    /**
     * Recipients claimed per pass, from config/knsoftic.php.
     *
     * Interpolated straight into the LIMIT clause, so it is cast and clamped
     * here rather than trusted — a config value is not a bound parameter.
     */
    protected function chunkSize(): int
    {
        $configured = (int) config('knsoftic.campaign_chunk', self::CHUNK);

        return max(1, min(self::MAX_CHUNK, $configured ?: self::CHUNK));
    }

    /** Returns anything still held by this token to the queue. */
    protected function release(Campaign $campaign, string $token): void
    {
        DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->where('locked_by', $token)
            ->where('status', 'sending')
            ->update(['status' => 'pending', 'locked_by' => null, 'locked_at' => null, 'updated_at' => now()]);
    }

    protected function releaseOne(CampaignRecipient $recipient): void
    {
        DB::table('campaign_recipients')->where('id', $recipient->id)->update([
            'status' => 'pending', 'locked_by' => null, 'locked_at' => null, 'updated_at' => now(),
        ]);
    }

    /**
     * A worker that died mid-chunk leaves rows in `sending` forever. After the
     * lease they go back to pending so the campaign can finish.
     */
    protected function reclaimStaleLeases(Campaign $campaign): int
    {
        return DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->where('status', 'sending')
            ->where(fn ($q) => $q->whereNull('locked_at')
                ->orWhere('locked_at', '<', now()->subMinutes(self::LEASE_MINUTES)))
            ->update(['status' => 'pending', 'locked_by' => null, 'locked_at' => null, 'updated_at' => now()]);
    }

    // -------------------------------------------------------------- sending

    /** @return 'sent'|'failed'|'deferred' */
    protected function sendOne(Campaign $campaign, CampaignRecipient $recipient, array $blueprint): string
    {
        $subscriber = $recipient->subscriber;

        if (! $subscriber) {
            $this->markSkipped($recipient, 'The contact no longer exists.');

            return 'failed';
        }

        $message = $this->messages->forRecipient($blueprint, $campaign, $subscriber, $recipient);

        $outcome = $this->sender->send($campaign->account, $message);

        if ($outcome->deferred) {
            $this->releaseOne($recipient);

            return 'deferred';
        }

        if ($outcome->sent) {
            $this->recordSent($campaign, $recipient, $message, $outcome);

            return 'sent';
        }

        $this->recordFailed($campaign, $recipient, $outcome, $subscriber);

        return 'failed';
    }

    protected function recordSent(Campaign $campaign, CampaignRecipient $recipient, Email $message, $outcome): void
    {
        DB::table('campaign_recipients')->where('id', $recipient->id)->update([
            'status' => 'sent',
            'sent_at' => now(),
            'locked_by' => null,
            'locked_at' => null,
            'smtp_account_id' => $outcome->smtpAccount?->id,
            'message_id' => $this->messages->messageIdOf($message),
            'error' => null,
            'updated_at' => now(),
        ]);

        // Rule 4: atomic.
        Campaign::withoutGlobalScopes()->whereKey($campaign->id)->increment('sent_count');
        PlanLimits::for($campaign->account)->increment('emails_sent');

        $this->log($campaign, $recipient, 'sent', null, $outcome->smtpAccount?->id);
    }

    protected function recordFailed(Campaign $campaign, CampaignRecipient $recipient, $outcome, Subscriber $subscriber): void
    {
        $bounce = $outcome->bounceType();

        DB::table('campaign_recipients')->where('id', $recipient->id)->update([
            'status' => $bounce ? 'bounced' : 'failed',
            'failed_at' => now(),
            'locked_by' => null,
            'locked_at' => null,
            'smtp_account_id' => $outcome->smtpAccount?->id,
            'bounce_type' => $bounce,
            'bounced_at' => $bounce ? now() : null,
            'error' => mb_substr((string) $outcome->reason, 0, 1000),
            'updated_at' => now(),
        ]);

        Campaign::withoutGlobalScopes()->whereKey($campaign->id)->increment(
            $bounce ? 'bounced_count' : 'failed_count'
        );

        // The bounce log, and the do-not-send decision that follows from it.
        // The recorder owns the threshold: suppressing on the first hard
        // bounce is only correct because that is the configured default, and
        // an operator who raises it should get what they set.
        $this->bounces->record($campaign, $recipient, $subscriber, $outcome);

        $this->log($campaign, $recipient, $bounce ? 'bounced' : 'failed',
            (string) $outcome->reason, $outcome->smtpAccount?->id);
    }

    protected function markSkipped(CampaignRecipient $recipient, string $reason): void
    {
        DB::table('campaign_recipients')->where('id', $recipient->id)->update([
            'status' => 'skipped',
            'locked_by' => null,
            'locked_at' => null,
            'error' => $reason,
            'updated_at' => now(),
        ]);
    }

    protected function log(Campaign $campaign, CampaignRecipient $recipient, string $status, ?string $error, ?int $smtpId): void
    {
        CampaignLog::withoutGlobalScopes()->create([
            'account_id' => $campaign->account_id,
            'campaign_id' => $campaign->id,
            'campaign_recipient_id' => $recipient->id,
            'subscriber_id' => $recipient->subscriber_id,
            'smtp_account_id' => $smtpId,
            'recipient_email' => $recipient->email,
            'sender_email' => $campaign->from_email,
            'subject' => mb_substr((string) $campaign->subject, 0, 255),
            'type' => 'campaign',
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 1000) : null,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }

    // ----------------------------------------------------------- completion

    /**
     * Rule 5. Marks the campaign complete exactly once, and only when nothing
     * is left outstanding. The NOT EXISTS lives inside the UPDATE so two
     * workers racing to finalise cannot both succeed.
     */
    public function tryFinalise(Campaign $campaign): bool
    {
        $updated = DB::update(
            "UPDATE campaigns SET status = 'completed', completed_at = ?, updated_at = ?
              WHERE id = ?
                AND status IN ('queued','sending')
                AND NOT EXISTS (
                    SELECT 1 FROM campaign_recipients
                     WHERE campaign_recipients.campaign_id = campaigns.id
                       AND campaign_recipients.status IN ('pending','sending')
                )",
            [now(), now(), $campaign->id]
        );

        if ($updated === 1) {
            // Exactly once, for the same reason the status flip is exactly
            // once. AccountNotifier swallows and logs its own failures, so a
            // notification can never turn a finished send into a failed job.
            AccountNotifier::send($campaign->account_id, new CampaignCompleted((int) $campaign->id));
        }

        return $updated === 1;
    }

    /** How much of the campaign is left, for the progress endpoint. */
    public function outstanding(Campaign $campaign): int
    {
        return DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'sending'])
            ->count();
    }

    /**
     * @return Collection<string, int>
     */
    public function statusCounts(Campaign $campaign): Collection
    {
        return DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
    }
}
