<?php

namespace App\Services\Tracking;

use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Models\EmailClick;
use App\Models\EmailOpen;
use App\Services\Automation\AutomationTrigger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Records opens and clicks.
 *
 * ── Unique vs total, and why the guard is a conditional UPDATE ──────────────
 * "Opens" and "unique opens" are different numbers and people compare them, so
 * getting the second one right matters. The same person's mail client can
 * fetch the pixel twice in the same second, and two web workers can handle
 * those two requests at once. Reading first_opened_at and then writing it
 * would let both see NULL and both count a unique.
 *
 * So the guard IS the write:
 *
 *     UPDATE campaign_recipients SET first_opened_at = ?
 *      WHERE id = ? AND first_opened_at IS NULL
 *
 * Exactly one of the two gets 1 affected row; that one, and only that one,
 * increments the campaign's unique counter.
 *
 * ── What these numbers actually mean ────────────────────────────────────────
 * An open is a pixel fetch, and that is all it is. It undercounts (most
 * clients block remote images by default) and it overcounts (Apple Mail
 * Privacy Protection and Gmail's proxy fetch images the reader never saw).
 * Both directions are recorded honestly here — proxy fetches are classified
 * rather than silently dropped — and the analytics screens say so rather than
 * presenting a percentage as a fact.
 */
class TrackingRecorder
{
    public function __construct(protected AutomationTrigger $automations) {}

    /**
     * Fetchers that open mail on the reader's behalf. Recorded, but marked, so
     * a campaign report can say how much of its "open rate" is a machine.
     */
    protected const PROXIES = [
        'GoogleImageProxy' => 'gmail-proxy',
        'YahooMailProxy' => 'yahoo-proxy',
        'Barracuda' => 'security-scanner',
        'Proofpoint' => 'security-scanner',
        'Mimecast' => 'security-scanner',
        'Symantec' => 'security-scanner',
        'MessageLabs' => 'security-scanner',
        'Microsoft Office Existence Discovery' => 'link-scanner',
        'BingPreview' => 'link-scanner',
        'Slackbot' => 'link-scanner',
        'facebookexternalhit' => 'link-scanner',
        'WhatsApp' => 'link-scanner',
        'Twitterbot' => 'link-scanner',
    ];

    public function open(CampaignRecipient $recipient, Request $request): void
    {
        $campaign = $recipient->campaign;

        if (! $campaign || ! $campaign->track_opens) {
            return;
        }

        $now = now();
        $device = $this->classify((string) $request->userAgent());

        // The raw log first: if anything below fails, the event is still on
        // record and refreshCounts() can rebuild the counters from it.
        EmailOpen::query()->insert([
            'account_id' => $campaign->account_id,
            'campaign_id' => $campaign->id,
            'campaign_recipient_id' => $recipient->id,
            'subscriber_id' => $recipient->subscriber_id,
            'ip' => $this->ip($request),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'device' => $device,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $isFirst = DB::update(
            'UPDATE campaign_recipients SET first_opened_at = ?, updated_at = ?
              WHERE id = ? AND first_opened_at IS NULL',
            [$now, $now, $recipient->id]
        ) === 1;

        DB::update(
            'UPDATE campaign_recipients
                SET open_count = open_count + 1, last_opened_at = ?, updated_at = ?
              WHERE id = ?',
            [$now, $now, $recipient->id]
        );

        DB::update(
            'UPDATE campaigns
                SET opened_count = opened_count + 1,
                    unique_opens = unique_opens + ?,
                    updated_at = ?
              WHERE id = ?',
            [$isFirst ? 1 : 0, $now, $campaign->id]
        );

        // Only a person's FIRST open starts an automation. A scanner's fetch
        // is not attention — enrolling on it would send a "since you read our
        // email" follow-up to everybody behind a corporate mail gateway, none
        // of whom read anything. And the second open is not a second event.
        if ($isFirst && $device === null && $recipient->subscriber_id) {
            $this->automations->campaignOpened(
                $campaign->account_id, (int) $recipient->subscriber_id, (int) $campaign->id
            );
        }
    }

    /**
     * Records a click and returns the destination to send the person to.
     *
     * A click is also an open: the reader plainly saw the message, whether or
     * not the pixel ever loaded. Counting it fixes the commonest reporting
     * absurdity — a campaign showing more clicks than opens.
     */
    public function click(CampaignRecipient $recipient, CampaignLink $link, Request $request): void
    {
        $campaign = $recipient->campaign;

        if (! $campaign) {
            return;
        }

        $now = now();

        // A link scanner is not a reader. Mimecast, Proofpoint, Barracuda and
        // Microsoft Safe Links follow every URL in a message to check it, and
        // Slack and WhatsApp fetch them to build a preview — the same agents
        // the open classifier already knows, arriving by a different door.
        $device = $this->classify((string) $request->userAgent());

        // Cheap and near-exact. The window in which two simultaneous clicks on
        // the SAME link by the SAME person could both count as unique is a few
        // milliseconds, and refreshLinkCounts() recomputes the exact figure
        // from email_clicks whenever the report is rebuilt. The alternative —
        // COUNT(DISTINCT) inside the increment — rescans every click on the
        // link on every click, which does not survive a popular link.
        $firstOnThisLink = ! EmailClick::query()
            ->where('campaign_link_id', $link->id)
            ->where('campaign_recipient_id', $recipient->id)
            ->exists();

        // Whether a PERSON has clicked this link before, which is a different
        // question from whether anything has. A security gateway checks every
        // URL the moment the message arrives, so it is almost always first —
        // and if "first click" were the trigger, the scanner would consume it
        // and the reader's real click, minutes later, would start nothing.
        $firstHumanOnThisLink = $device === null && ! EmailClick::query()
            ->where('campaign_link_id', $link->id)
            ->where('campaign_recipient_id', $recipient->id)
            ->whereNull('device')
            ->exists();

        EmailClick::query()->insert([
            'account_id' => $campaign->account_id,
            'campaign_id' => $campaign->id,
            'campaign_recipient_id' => $recipient->id,
            'campaign_link_id' => $link->id,
            'subscriber_id' => $recipient->subscriber_id,
            'ip' => $this->ip($request),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'device' => $device,
            'clicked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $isFirstClick = DB::update(
            'UPDATE campaign_recipients SET first_clicked_at = ?, updated_at = ?
              WHERE id = ? AND first_clicked_at IS NULL',
            [$now, $now, $recipient->id]
        ) === 1;

        DB::update(
            'UPDATE campaign_recipients
                SET click_count = click_count + 1, last_clicked_at = ?, updated_at = ?
              WHERE id = ?',
            [$now, $now, $recipient->id]
        );

        DB::update(
            'UPDATE campaign_links
                SET click_count = click_count + 1,
                    unique_click_count = unique_click_count + ?,
                    updated_at = ?
              WHERE id = ?',
            [$firstOnThisLink ? 1 : 0, $now, $link->id]
        );

        DB::update(
            'UPDATE campaigns
                SET clicked_count = clicked_count + 1,
                    unique_clicks = unique_clicks + ?,
                    updated_at = ?
              WHERE id = ?',
            [$isFirstClick ? 1 : 0, $now, $campaign->id]
        );

        // A machine following every URL in the message to check it is not a
        // person deciding to click one, so it starts nothing.
        if ($device !== null) {
            return;
        }

        if ($firstHumanOnThisLink && $recipient->subscriber_id) {
            $this->automations->linkClicked(
                $campaign->account_id, (int) $recipient->subscriber_id,
                (int) $campaign->id, (int) $link->id
            );
        }

        // Somebody who clicked has read the message. If the pixel never
        // loaded, this is the only evidence of it — and a report showing more
        // clicks than opens is one nobody believes.
        //
        // A machine's click is NOT that evidence. Letting a security scanner
        // manufacture a first open would invent attention that never happened,
        // and it would do it for every recipient behind that gateway — which
        // is why the check above returns before reaching here.

        $impliedOpen = DB::update(
            'UPDATE campaign_recipients SET first_opened_at = ?, last_opened_at = ?, updated_at = ?
              WHERE id = ? AND first_opened_at IS NULL',
            [$now, $now, $now, $recipient->id]
        ) === 1;

        if ($impliedOpen) {
            DB::update(
                'UPDATE campaigns SET unique_opens = unique_opens + 1, updated_at = ? WHERE id = ?',
                [$now, $campaign->id]
            );
        }
    }

    /**
     * Rebuilds a campaign's engagement counters from the event rows.
     *
     * The counters are maintained live; this is the repair path for when they
     * drift — a restored backup, a deleted recipient, a bug fixed later. Each
     * figure is derived, so running it twice changes nothing.
     */
    public function refreshCounts(int $campaignId): void
    {
        DB::statement(
            'UPDATE campaigns c
                LEFT JOIN (
                    SELECT campaign_id, COUNT(*) t, COUNT(DISTINCT campaign_recipient_id) u
                      FROM email_opens WHERE campaign_id = ? GROUP BY campaign_id
                ) o ON o.campaign_id = c.id
                LEFT JOIN (
                    SELECT campaign_id, COUNT(*) t, COUNT(DISTINCT campaign_recipient_id) u
                      FROM email_clicks WHERE campaign_id = ? GROUP BY campaign_id
                ) k ON k.campaign_id = c.id
               SET c.opened_count = COALESCE(o.t, 0),
                   c.unique_opens = GREATEST(COALESCE(o.u, 0), COALESCE(k.u, 0)),
                   c.clicked_count = COALESCE(k.t, 0),
                   c.unique_clicks = COALESCE(k.u, 0),
                   c.updated_at = ?
             WHERE c.id = ?',
            [$campaignId, $campaignId, now(), $campaignId]
        );
    }

    /**
     * A short label for what fetched the pixel, or null for an ordinary
     * client. Substring matching on the user agent: it is a hint, not an
     * identity, and it is presented as one.
     */
    public function classify(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        foreach (self::PROXIES as $needle => $label) {
            if (stripos($userAgent, $needle) !== false) {
                return $label;
            }
        }

        return null;
    }

    /**
     * The requester's address, truncated to what the column holds.
     *
     * Stored because a report that cannot distinguish one reader from a
     * scanner is not much of a report; never logged anywhere else, and never
     * shown outside the account that owns the campaign.
     */
    protected function ip(Request $request): ?string
    {
        $ip = (string) $request->ip();

        return $ip !== '' ? mb_substr($ip, 0, 45) : null;
    }
}
