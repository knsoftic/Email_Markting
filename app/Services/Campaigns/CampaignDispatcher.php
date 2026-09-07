<?php

namespace App\Services\Campaigns;

use App\Exceptions\PlanLimitException;
use App\Jobs\Campaigns\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\CampaignVariant;
use App\Models\Subscriber;
use App\Services\Smtp\SmtpSelector;
use App\Services\Smtp\SmtpSender;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The campaign state machine: what may be started, paused, resumed, cancelled
 * and scheduled, and the checks that stand between "Send" and a real send.
 *
 *   draft ──► queued ──► sending ──► completed
 *     │         │           │  ▲
 *     │         │           ▼  │
 *     │         │        paused
 *     ▼         ▼           │
 *  scheduled ───┘        cancelled / failed
 */
class CampaignDispatcher
{
    public function __construct(
        protected RecipientGenerator $generator,
        protected CampaignRunner $runner,
        protected MessageBuilder $messages,
        protected SmtpSelector $selector,
        protected SmtpSender $sender,
        protected PersonalizationEngine $personalization,
        protected AbTestService $ab,
    ) {}

    /**
     * Everything that must be true before a campaign may go out. Returned as a
     * list rather than thrown, so the confirmation screen can show all of them
     * at once instead of one per attempt.
     *
     * @return array<int, string>
     */
    public function blockers(Campaign $campaign): array
    {
        $problems = [];

        if (blank($campaign->subject)) {
            $problems[] = 'The campaign has no subject line.';
        }

        if (blank($campaign->from_email)) {
            $problems[] = 'No sender address is set.';
        }

        if (blank($campaign->html)) {
            $problems[] = 'The campaign has no content yet.';
        }

        // Non-negotiable: a marketing email without a working opt-out is not
        // sendable, whatever the operator wants.
        if (! str_contains((string) $campaign->html, '{{unsubscribe_link}}')
            && ! str_contains((string) $campaign->html, '/unsubscribe/')) {
            $problems[] = 'The content has no unsubscribe link. Add a footer block before sending.';
        }

        if ($this->generator->count($campaign) === 0) {
            $problems[] = 'The audience is empty — no contact matches the selected lists, tags or segments.';
        }

        if ($this->selector->candidatesFor($campaign->account)->isEmpty()) {
            $problems[] = 'No SMTP account is available to send through.';
        }

        foreach ($this->personalization->unknownTokens((string) $campaign->html) as $token) {
            $problems[] = 'The content uses an unknown placeholder: {{'.$token.'}}';
        }

        // Every link inside a sent email — the unsubscribe link included — is
        // built from APP_URL, because a queue worker has no incoming request
        // to take a host from. If that is localhost, the opt-out in 50,000
        // inboxes points at a machine nobody can reach, and that is a
        // compliance failure, not a cosmetic one. Blocked in production;
        // outside it, a local URL is exactly what you want, so it is only a
        // warning.
        if ($this->appUrlIsLocal() && app()->environment('production')) {
            $problems[] = 'APP_URL is set to a local address, so the unsubscribe and tracking links '
                .'in this email would point somewhere recipients cannot reach. Set it to your real domain.';
        }

        if ($campaign->is_ab_test) {
            $variants = $this->ab->variants($campaign);

            if ($variants->count() < 2) {
                $problems[] = 'This is marked as a split test but has fewer than two versions to compare.';
            }

            // A "test" whose versions are identical is not a test: it splits
            // the audience, waits, and declares a winner between two copies of
            // the same email. Saying so beats letting somebody wait four hours
            // for a meaningless result.
            if ($variants->count() >= 2 && $this->variantsAreIdentical($campaign, $variants)) {
                $problems[] = 'The versions in this split test are identical, so there is nothing to compare. '
                    .'Change the subject, sender or content on at least one of them.';
            }
        }

        return $problems;
    }

    /**
     * @param  Collection<int, CampaignVariant>  $variants
     */
    protected function variantsAreIdentical(Campaign $campaign, $variants): bool
    {
        $fingerprints = $variants->map(fn ($v) => implode('|', [
            (string) ($v->subject ?: $campaign->subject),
            (string) ($v->from_name ?: $campaign->from_name),
            (string) ($v->from_email ?: $campaign->from_email),
            md5((string) ($v->html ?: $campaign->html)),
        ]))->unique();

        return $fingerprints->count() < $variants->count();
    }

    /**
     * Things worth saying before a send that are not reasons to refuse it.
     *
     * Kept separate from blockers() on purpose: a warning that stops the send
     * teaches people to ignore warnings, and a blocker that is only advice
     * teaches them to look for a way around it.
     *
     * @return array<int, string>
     */
    public function warnings(Campaign $campaign): array
    {
        $warnings = [];

        if ($this->appUrlIsLocal() && ! app()->environment('production')) {
            $warnings[] = 'APP_URL is '.config('app.url').', so the unsubscribe and tracking links in this '
                .'email will point there. That is fine for a test, but not for a real audience.';
        }

        if (! $campaign->track_opens && ! $campaign->track_clicks) {
            $warnings[] = 'Both open and click tracking are off, so this campaign will report deliveries '
                .'and bounces but no engagement at all.';
        }

        $bytes = strlen((string) $campaign->html);

        if ($bytes > 102000) {
            $warnings[] = 'The email is '.round($bytes / 1024).' KB. Gmail clips messages over about 102 KB '
                .'behind "View entire message", which usually hides the unsubscribe link.';
        }

        return $warnings;
    }

    /**
     * Whether the configured application URL is one only this machine can
     * reach. Host-only: a real domain on a non-standard port is fine.
     */
    protected function appUrlIsLocal(): bool
    {
        $host = mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || (bool) preg_match('/^(127\.|0\.0\.0\.0$|::1$|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
    }

    /**
     * Generates recipients and starts sending.
     *
     * @throws PlanLimitException
     */
    public function sendNow(Campaign $campaign): int
    {
        $this->assertSendable($campaign);

        $recipients = $this->prepare($campaign);

        $campaign->forceFill([
            'status' => 'queued',
            'started_at' => $campaign->started_at ?? now(),
            'scheduled_at' => null,
            'paused_at' => null,
            'last_error' => null,
        ])->save();

        SendCampaignChunk::dispatch($campaign->id);

        ActivityLogger::log('campaign.sent', "Started sending {$campaign->name} to {$recipients} contact(s)", [], $campaign);

        return $recipients;
    }

    /**
     * Schedules for later. Recipients are NOT generated now: the audience is
     * resolved at send time so a contact who joins the list tomorrow is
     * included, and one who unsubscribes tonight is not.
     */
    public function schedule(Campaign $campaign, string $localDateTime, string $timezone): void
    {
        $this->assertSendable($campaign);

        $when = Carbon::parse($localDateTime, $timezone)->utc();

        abort_if($when->isPast(), 422, 'That time has already passed.');

        $campaign->forceFill([
            'status' => 'scheduled',
            'scheduled_at' => $when,
            'timezone' => $timezone,
            'paused_at' => null,
            'last_error' => null,
        ])->save();

        ActivityLogger::log(
            'campaign.scheduled',
            "Scheduled {$campaign->name} for ".$when->timezone($timezone)->toDayDateTimeString()." ({$timezone})",
            [], $campaign
        );
    }

    public function unschedule(Campaign $campaign): void
    {
        abort_unless($campaign->status === 'scheduled', 422, 'That campaign is not scheduled.');

        $campaign->forceFill(['status' => 'draft', 'scheduled_at' => null])->save();

        ActivityLogger::log('campaign.unscheduled', "Unscheduled {$campaign->name}", [], $campaign);
    }

    public function pause(Campaign $campaign): void
    {
        abort_unless(in_array($campaign->status, ['queued', 'sending'], true), 422, 'That campaign is not sending.');

        $campaign->forceFill(['status' => 'paused', 'paused_at' => now()])->save();

        ActivityLogger::log('campaign.paused', "Paused {$campaign->name}", [], $campaign);
    }

    public function resume(Campaign $campaign): void
    {
        abort_unless($campaign->status === 'paused', 422, 'That campaign is not paused.');

        $campaign->forceFill(['status' => 'sending', 'paused_at' => null])->save();

        SendCampaignChunk::dispatch($campaign->id);

        ActivityLogger::log('campaign.resumed', "Resumed {$campaign->name}", [], $campaign);
    }

    /**
     * Stops for good. Already-sent messages cannot be recalled, so the ones
     * that went out stay counted and only the queue is cleared.
     */
    public function cancel(Campaign $campaign): int
    {
        abort_unless(
            in_array($campaign->status, ['queued', 'sending', 'paused', 'scheduled'], true),
            422, 'That campaign cannot be cancelled.'
        );

        $dropped = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'sending'])
            ->update(['status' => 'skipped', 'locked_by' => null, 'locked_at' => null,
                'error' => 'Campaign cancelled before this message was sent.', 'updated_at' => now()]);

        $campaign->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();

        ActivityLogger::log('campaign.cancelled', "Cancelled {$campaign->name} with {$dropped} unsent", [], $campaign);

        return $dropped;
    }

    /**
     * Sends one message to an arbitrary address.
     *
     * It deliberately touches no campaign counter and creates no recipient
     * row: a test send must not show up in the campaign's reporting or consume
     * a slot in the audience.
     *
     * @return array{ok: bool, message: string}
     */
    public function sendTest(Campaign $campaign, string $email, ?Subscriber $sample = null): array
    {
        $sample ??= Subscriber::query()->mailable()->first()
            ?? new Subscriber([
                'email' => $email,
                'name' => 'Sample Contact',
                'first_name' => 'Sample',
                'last_name' => 'Contact',
                'company' => $campaign->account?->name,
            ]);

        $blueprint = $this->messages->blueprint($campaign);
        $blueprint['subject'] = '[TEST] '.$blueprint['subject'];

        $message = $this->messages->forRecipient($blueprint, $campaign, $sample);
        $message->to($email);

        $outcome = $this->sender->send($campaign->account, $message);
        $this->sender->flush();

        ActivityLogger::log(
            'campaign.test_sent',
            "Test of {$campaign->name} to {$email}: ".($outcome->sent ? 'sent' : 'failed'),
            [], $campaign
        );

        return $outcome->sent
            ? ['ok' => true, 'message' => "Test sent to {$email} using the details of ".$sample->email.'.']
            : ['ok' => false, 'message' => (string) ($outcome->reason ?: 'The test could not be sent.')];
    }

    /**
     * Generates the recipient rows and charges the plan's campaign counter.
     *
     * @throws PlanLimitException
     */
    protected function prepare(Campaign $campaign): int
    {
        $limits = PlanLimits::for($campaign->account);

        $this->generator->generate($campaign);
        $total = $this->generator->refreshTotal($campaign);

        // The split is decided once, here, before the first chunk claims
        // anybody. Assigning later would mean the first recipients were mailed
        // before they belonged to a group.
        $this->ab->assign($campaign);

        // Checked against what the campaign will ACTUALLY send, after
        // suppression and status filtering — not against the raw list size.
        $limits->ensure('max_emails_per_month', $total);

        if ($campaign->started_at === null) {
            $limits->increment('campaigns_created');
        }

        return $total;
    }

    /**
     * @throws HttpException
     */
    protected function assertSendable(Campaign $campaign): void
    {
        abort_unless(
            in_array($campaign->status, ['draft', 'scheduled', 'paused', 'failed'], true),
            422, 'That campaign has already been sent or is sending.'
        );

        $blockers = $this->blockers($campaign);

        // Not abort_if(): PHP evaluates every argument before the call, so
        // $blockers[0] would be read even when the list is empty.
        if ($blockers !== []) {
            abort(422, $blockers[0]);
        }
    }
}
