<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Subscriber;
use Illuminate\Support\Facades\URL;

/**
 * Builds the tamper-proof public links that go inside a marketing email.
 *
 * Every URL is a Laravel signed route: the subscriber and campaign ids are
 * visible, but the signature is an HMAC over the whole URL with APP_KEY, so a
 * recipient cannot edit the id in the address bar and unsubscribe somebody
 * else. That is the difference between a real unsubscribe link and one that
 * doubles as a way to sabotage another contact.
 *
 * The links are deliberately NOT time-limited. An unsubscribe link has to keep
 * working when someone finds the email two years later — an expired opt-out is
 * a compliance failure, not a security win.
 */
class LinkSigner
{
    public function unsubscribeUrl(
        ?Subscriber $subscriber,
        ?Campaign $campaign = null,
        ?CampaignRecipient $recipient = null,
    ): string {
        if (! $subscriber?->exists) {
            // A preview or a test send has no real contact behind it. The link
            // must still be present and obviously inert.
            return URL::to('/unsubscribe/preview');
        }

        return URL::signedRoute('unsubscribe.show', array_filter([
            'subscriber' => $subscriber->getKey(),
            'campaign' => $campaign?->getKey(),
        ]));
    }

    public function preferencesUrl(
        ?Subscriber $subscriber,
        ?Campaign $campaign = null,
        ?CampaignRecipient $recipient = null,
    ): string {
        if (! $subscriber?->exists) {
            return URL::to('/preferences/preview');
        }

        return URL::signedRoute('preferences.show', array_filter([
            'subscriber' => $subscriber->getKey(),
            'campaign' => $campaign?->getKey(),
        ]));
    }

    /**
     * The RFC 8058 one-click target. Mail clients POST to this directly, with
     * no browser session and no CSRF token, so it has its own route outside
     * the web middleware group.
     */
    public function oneClickUrl(Subscriber $subscriber, ?Campaign $campaign = null): string
    {
        return URL::signedRoute('unsubscribe.one-click', array_filter([
            'subscriber' => $subscriber->getKey(),
            'campaign' => $campaign?->getKey(),
        ]));
    }

    /**
     * The List-Unsubscribe headers. Gmail and Outlook surface a native
     * "Unsubscribe" button when these are present, which keeps people from
     * reaching for "report spam" instead — the single most valuable thing a
     * sender can do for their own deliverability.
     *
     * @return array<string, string>
     */
    public function listUnsubscribeHeaders(Subscriber $subscriber, ?Campaign $campaign = null): array
    {
        return [
            'List-Unsubscribe' => '<'.$this->oneClickUrl($subscriber, $campaign).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }
}
