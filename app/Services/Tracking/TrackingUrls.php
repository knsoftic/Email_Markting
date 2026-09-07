<?php

namespace App\Services\Tracking;

use App\Models\CampaignRecipient;
use Illuminate\Support\Facades\URL;

/**
 * Mints the signed open- and click-tracking URLs for one recipient.
 *
 * Signed, not opaque-token: the ids are visible in the URL, and the signature
 * is an HMAC over the whole URL with APP_KEY. Without it a recipient could
 * edit the recipient id and record opens and clicks against somebody else in
 * the same campaign — not a breach, but it would put words in another person's
 * mouth in the reporting, which is worse than a missing number.
 *
 * Like the unsubscribe links, these do not expire. A tracked link inside an
 * email has to keep working when the email is opened next year; an expired one
 * would be a dead link in the reader's hands, which is a real failure to fix a
 * reporting inconvenience.
 */
class TrackingUrls
{
    public function openUrl(CampaignRecipient $recipient): string
    {
        return URL::signedRoute('track.open', ['recipient' => $recipient->getKey()]);
    }

    public function clickUrl(CampaignRecipient $recipient, int $linkId): string
    {
        return URL::signedRoute('track.click', [
            'link' => $linkId,
            'recipient' => $recipient->getKey(),
        ]);
    }

    /**
     * The full replacement map for one message: every link placeholder the
     * document uses, plus the pixel.
     *
     * @param  array<int, int>  $linkIds
     * @return array<string, string>
     */
    public function replacementsFor(CampaignRecipient $recipient, array $linkIds, bool $withPixel): array
    {
        $map = [];

        foreach ($linkIds as $id) {
            $map['%%KNL'.$id.'%%'] = $this->clickUrl($recipient, $id);
        }

        if ($withPixel) {
            $map[TrackingLinkRewriter::PIXEL_PLACEHOLDER] = $this->openUrl($recipient);
        }

        return $map;
    }

    /**
     * What a message with no real recipient behind it gets — a preview or a
     * test send.
     *
     * The placeholders must not survive into the body: a reader seeing
     * "%%KNL4%%" as a link is worse than an untracked link, and a test send is
     * a real email that someone will look at. Links fall back to their
     * destination and the pixel to a transparent inline image, so the test
     * looks exactly like the real thing without recording anything.
     *
     * @param  array<int, string>  $urlsById
     * @return array<string, string>
     */
    public function previewReplacements(array $urlsById): array
    {
        $map = [TrackingLinkRewriter::PIXEL_PLACEHOLDER => 'data:image/gif;base64,'
            .'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'];

        foreach ($urlsById as $id => $url) {
            $map['%%KNL'.$id.'%%'] = $url;
        }

        return $map;
    }
}
