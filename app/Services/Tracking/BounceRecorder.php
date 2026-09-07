<?php

namespace App\Services\Tracking;

use App\Models\Bounce;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Subscriber;
use App\Services\Contacts\SuppressionService;
use App\Services\Smtp\SendOutcome;
use Illuminate\Support\Collection;

/**
 * The bounce log, and the decision to stop mailing an address because of it.
 *
 * ── Soft and hard are not the same event ────────────────────────────────────
 * A hard bounce means the address does not exist: mailing it again is what
 * gets a sender's domain a bad reputation, so it goes on the do-not-send list.
 * A soft bounce is a full mailbox, a greylist, a server having a bad day — the
 * address is real and will very likely accept the next campaign. Suppressing
 * on a soft bounce would quietly delete a good contact for somebody else's
 * outage, so it is recorded and shown, and nothing else happens.
 *
 * ── Why the threshold lives here ────────────────────────────────────────────
 * config/knsoftic.php has always advertised `hard_bounce_limit`, but nothing
 * read it: the send path suppressed on the first hard bounce whatever the
 * setting said. An operator who set it to 3 got 1. The count is now taken from
 * the bounce log, which is also what makes the setting meaningful — you cannot
 * count to three without keeping a record of the first two.
 */
class BounceRecorder
{
    public function __construct(protected SuppressionService $suppressions) {}

    /**
     * Records one bounce and suppresses the address if it has now bounced
     * hard often enough.
     *
     * @return bool whether the address was suppressed by this bounce
     */
    public function record(
        Campaign $campaign,
        CampaignRecipient $recipient,
        Subscriber $subscriber,
        SendOutcome $outcome,
    ): bool {
        return $this->log(
            $campaign->account_id, $subscriber, $outcome,
            campaignId: $campaign->id, recipientId: $recipient->id, source: 'campaign'
        );
    }

    /**
     * The same recording for a send that belongs to no campaign.
     *
     * An automation email that hard-bounces is exactly as much evidence that
     * an address does not exist as a campaign email that hard-bounces. Leaving
     * it unrecorded would let a sequence keep mailing a dead address every
     * week while the bounce log showed nothing — and would make the operator's
     * hard-bounce threshold count only half the bounces it should.
     */
    public function recordStandalone(
        int $accountId,
        Subscriber $subscriber,
        SendOutcome $outcome,
        string $source = 'automation',
    ): bool {
        return $this->log($accountId, $subscriber, $outcome, source: $source);
    }

    protected function log(
        int $accountId,
        Subscriber $subscriber,
        SendOutcome $outcome,
        ?int $campaignId = null,
        ?int $recipientId = null,
        string $source = 'campaign',
    ): bool {
        $type = $outcome->bounceType();

        if ($type === null) {
            // Not a recipient-level rejection — an authentication failure or a
            // connection problem is our fault, not the address's, and must
            // never be written to a contact's bounce history.
            return false;
        }

        Bounce::withoutGlobalScopes()->create([
            'account_id' => $accountId,
            'campaign_id' => $campaignId,
            'campaign_recipient_id' => $recipientId,
            'subscriber_id' => $subscriber->id,
            'email' => $subscriber->email,
            'type' => $type,
            'code' => mb_substr((string) $outcome->failure?->code, 0, 20) ?: null,
            'description' => mb_substr((string) $outcome->reason, 0, 500),
            // The enum on `bounces.source` describes HOW we learned of the
            // bounce, not which feature sent the mail. Both paths learned it
            // from the SMTP conversation.
            'source' => 'smtp',
            'occurred_at' => now(),
        ]);

        if ($type !== 'hard') {
            return false;
        }

        return $this->suppressIfOverLimit($subscriber, $accountId, $campaignId, (string) $outcome->reason, $source);
    }

    /**
     * Suppresses once the address has reached the configured number of hard
     * bounces. At the default of 1 this fires on the first one, which is the
     * right default: an address that does not exist will not start existing.
     */
    protected function suppressIfOverLimit(
        Subscriber $subscriber,
        int $accountId,
        ?int $campaignId,
        string $reason,
        string $source,
    ): bool {
        $limit = max(1, (int) config('knsoftic.hard_bounce_limit', 1));

        $hardBounces = Bounce::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('email', $subscriber->email)
            ->where('type', 'hard')
            ->count();

        if ($hardBounces < $limit) {
            return false;
        }

        if ($this->suppressions->isSuppressed($subscriber->email, $accountId)) {
            return false;
        }

        $this->suppressions->suppress(
            $subscriber->email,
            'hard_bounce',
            $campaignId,
            mb_substr($reason, 0, 200),
            $source,
            $accountId,
            log: false,
        );

        return true;
    }

    /**
     * The bounce history for one address, newest first — what the contact
     * screen shows when someone asks why a person stopped receiving mail.
     *
     * @return Collection<int, Bounce>
     */
    public function historyFor(string $email, int $accountId, int $limit = 20)
    {
        return Bounce::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('email', $email)
            ->latest('occurred_at')
            ->limit($limit)
            ->get();
    }
}
