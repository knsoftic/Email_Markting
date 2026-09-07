<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Models\CampaignVariant;
use App\Models\Subscriber;
use App\Services\Tracking\TrackingLinkRewriter;
use App\Services\Tracking\TrackingUrls;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Builds the actual message for one recipient.
 *
 * The campaign's HTML and text are compiled ONCE per chunk into a blueprint;
 * only the per-recipient token pass runs for each message. At 100k recipients
 * the difference between compiling once and compiling per message is the
 * difference between a send that finishes and one that does not.
 */
class MessageBuilder
{
    public function __construct(
        protected PersonalizationEngine $personalization,
        protected LinkSigner $links,
        protected TrackingLinkRewriter $rewriter,
        protected TrackingUrls $tracking,
    ) {}

    /**
     * The parts that are identical for every recipient.
     *
     * Tracking is prepared HERE, once per chunk, not per message: the links
     * are extracted and recorded and the document is rewritten into
     * placeholders. Only the placeholder substitution happens per recipient,
     * which is one strtr() over a map of a few entries rather than a regex
     * pass over the whole document 100,000 times.
     *
     * @return array{html: string, text: string, subject: string, from_name: string, from_email: string, reply_to: ?string, link_ids: array<int, int>, link_urls: array<int, string>, pixel: bool}
     */
    public function blueprint(Campaign $campaign, ?CampaignVariant $variant = null): array
    {
        // A variant overrides only what it actually sets. A subject-line test
        // leaves `html` null, and reading that null as "an empty email" would
        // send half the list a blank message — so each field falls back to the
        // campaign rather than the variant being all-or-nothing.
        $html = (string) ($variant?->html ?: $campaign->html);

        $prepared = $this->rewriter->prepare($campaign, $html);

        $html = $this->rewriter->withPixel($campaign, $prepared['html']);

        return [
            'html' => $html,
            'text' => (string) ($campaign->plain_text ?: ''),
            'subject' => (string) ($variant?->subject ?: $campaign->subject),
            'from_name' => (string) ($variant?->from_name ?: $campaign->from_name),
            'from_email' => (string) ($variant?->from_email ?: $campaign->from_email),
            'reply_to' => $campaign->reply_to ?: null,
            'link_ids' => $prepared['links'],
            // Kept so a preview or test send can put the real destinations
            // back rather than shipping a visible %%KNL4%% to a human.
            'link_urls' => $this->destinationsFor($campaign, $prepared['links']),
            'pixel' => (bool) $campaign->track_opens,
        ];
    }

    /**
     * @param  array<int, int>  $linkIds
     * @return array<int, string>
     */
    protected function destinationsFor(Campaign $campaign, array $linkIds): array
    {
        if ($linkIds === []) {
            return [];
        }

        return CampaignLink::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('id', $linkIds)
            ->pluck('url', 'id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $blueprint
     */
    public function forRecipient(
        array $blueprint,
        Campaign $campaign,
        Subscriber $subscriber,
        ?CampaignRecipient $recipient = null,
    ): Email {
        $html = $this->personalization->render($blueprint['html'], $subscriber, $campaign, $recipient);
        $text = $blueprint['text'] !== ''
            ? $this->personalization->renderText($blueprint['text'], $subscriber, $campaign, $recipient)
            : null;

        // The subject is plain text in the header, so it is rendered without
        // HTML escaping — otherwise "Smith & Sons" arrives as "Smith &amp; Sons".
        $subject = $this->personalization->renderText($blueprint['subject'], $subscriber, $campaign, $recipient);

        // Tracking last: the personalisation pass runs on {{tokens}} and the
        // placeholders are %%...%%, so they survive it untouched. A recipient
        // that does not exist yet — a test send or a preview — gets the real
        // destinations back instead, because a visible %%KNL4%% in somebody's
        // inbox is worse than an untracked link.
        $replacements = $recipient?->exists
            ? $this->tracking->replacementsFor($recipient, $blueprint['link_ids'] ?? [], (bool) ($blueprint['pixel'] ?? false))
            : $this->tracking->previewReplacements($blueprint['link_urls'] ?? []);

        if ($replacements !== []) {
            $html = strtr($html, $replacements);
        }

        $message = (new Email)
            ->from($this->address($blueprint['from_email'], $blueprint['from_name']))
            ->to($subscriber->email)
            ->subject($subject)
            ->html($html);

        if ($text !== null) {
            $message->text($text);
        }

        if ($blueprint['reply_to']) {
            $message->replyTo($blueprint['reply_to']);
        }

        $headers = $message->getHeaders();

        // A stable, unique id we control, so a reply carrying In-Reply-To can
        // be matched back to this exact recipient in Phase 9.
        $headers->addIdHeader('Message-ID', [$this->mintMessageId($campaign)]);

        // Native "Unsubscribe" in Gmail and Outlook. Offering it is the single
        // most useful thing a sender can do for their own reputation: it is
        // the alternative people reach for instead of "report spam".
        foreach ($this->links->listUnsubscribeHeaders($subscriber, $campaign) as $name => $value) {
            $headers->addTextHeader($name, $value);
        }

        // Marks the mail as bulk so autoresponders do not reply to it.
        $headers->addTextHeader('Precedence', 'bulk');
        $headers->addTextHeader('Auto-Submitted', 'auto-generated');

        return $message;
    }

    public function messageIdOf(Email $message): ?string
    {
        $header = $message->getHeaders()->get('Message-ID');

        return $header ? trim($header->getBodyAsString(), '<> ') : null;
    }

    protected function mintMessageId(Campaign $campaign): string
    {
        $domain = Str::after((string) $campaign->from_email, '@') ?: 'localhost';

        return Str::uuid().'.'.$campaign->id.'@'.$domain;
    }

    protected function address(string $email, string $name): Address
    {
        return new Address($email, $name);
    }
}
