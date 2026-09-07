<?php

namespace App\Services\Inbox;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Email;
use App\Models\EmailThread;
use Illuminate\Support\Facades\DB;

/**
 * Works out which campaign, if any, an incoming message is answering.
 *
 * ── Why there are three paths and not one ───────────────────────────────────
 * The clean answer is In-Reply-To: every campaign message goes out with a
 * Message-ID we minted and stored against that one recipient, so a reply
 * carrying it identifies the recipient exactly. That is path one, it is
 * certain, and it needs nothing configured.
 *
 * It is also not enough on its own. Plenty of clients strip References on a
 * "new message to the same person", people reply from a different address than
 * the one that was mailed, and some gateways rewrite headers wholesale. So
 * there are two weaker paths behind it, each ranked by how much it actually
 * knows:
 *
 *   header  — In-Reply-To or References names one of our Message-IDs.  Certain.
 *   token   — the Reply-To carried a per-recipient token that came back.  Certain.
 *   address — the sender is a contact we mailed recently and the subject
 *             matches that campaign.  A judgement, and labelled as one.
 *
 * Every match records HOW it was made, so a screen can show a confident link
 * differently from a probable one. A reply attributed to the wrong campaign is
 * worse than one left unattributed: it puts words in a customer's mouth in
 * somebody's reporting.
 */
class ReplyMatcher
{
    public const CERTAIN = 'certain';

    public const PROBABLE = 'probable';

    /**
     * How far back the address fallback will look. A reply three months after
     * a campaign, with no headers tying it to anything, is not evidence about
     * that campaign.
     */
    public const ADDRESS_WINDOW_DAYS = 30;

    /**
     * Attempts to attribute one message.
     *
     * @return array{recipient: CampaignRecipient, method: string, confidence: string}|null
     */
    public function match(Email $email): ?array
    {
        // Only incoming mail is a reply. Our own sent copy carries the same
        // References and would otherwise match itself.
        if ($email->direction !== 'incoming') {
            return null;
        }

        foreach (['byHeaders', 'byToken', 'byAddress'] as $strategy) {
            $found = $this->{$strategy}($email);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Attributes the message and records it everywhere it matters.
     *
     * Idempotent: a message already linked is left alone, and the campaign's
     * reply counter is only moved the first time a given recipient answers.
     */
    public function apply(Email $email): ?array
    {
        if ($email->is_campaign_reply && $email->campaign_recipient_id !== null) {
            return null;
        }

        $found = $this->match($email);

        if ($found === null) {
            return null;
        }

        $recipient = $found['recipient'];

        // Whether the matcher is the one putting this message into a thread.
        // The syncer counts a message when IT files one; if the matcher files
        // it instead, nobody has counted it yet and the queue would show a
        // conversation with "0 messages" that plainly has one.
        $threadWasAssignedHere = $email->email_thread_id === null;

        $email->forceFill([
            'is_campaign_reply' => true,
            'campaign_id' => $recipient->campaign_id,
            'campaign_recipient_id' => $recipient->id,
            'subscriber_id' => $recipient->subscriber_id,
            'email_thread_id' => $email->email_thread_id ?? $this->threadFor($email, $recipient)?->id,
        ])->save();

        if ($threadWasAssignedHere && $email->email_thread_id !== null) {
            DB::update(
                'UPDATE email_threads
                    SET messages_count = messages_count + 1,
                        unread_count = unread_count + ?,
                        updated_at = ?
                  WHERE id = ?',
                [$email->is_read ? 0 : 1, now(), $email->email_thread_id]
            );
        }

        // The conditional UPDATE is the guard: two replies from the same person
        // arriving at once must not both count as "this recipient replied".
        $firstReply = DB::update(
            'UPDATE campaign_recipients SET replied_at = ?, updated_at = ?
              WHERE id = ? AND replied_at IS NULL',
            [$email->received_at ?? now(), now(), $recipient->id]
        ) === 1;

        if ($firstReply) {
            DB::update(
                'UPDATE campaigns SET replied_count = replied_count + 1, updated_at = ? WHERE id = ?',
                [now(), $recipient->campaign_id]
            );
        }

        $this->markThreadNew($email);

        return $found;
    }

    // ------------------------------------------------------------- strategies

    /**
     * In-Reply-To, then References, against the Message-IDs we minted.
     *
     * References is read right-to-left: the last entry is the message being
     * answered, and the earlier ones are its ancestors. Taking the first would
     * attribute a long thread to whatever started it, which on a forwarded
     * chain is the wrong campaign entirely.
     *
     * @return array{recipient: CampaignRecipient, method: string, confidence: string}|null
     */
    protected function byHeaders(Email $email): ?array
    {
        $candidates = [];

        if ($inReplyTo = trim((string) $email->in_reply_to, '<> ')) {
            $candidates[] = $inReplyTo;
        }

        if (preg_match_all('/<([^>]+)>/', (string) $email->references, $matches)) {
            foreach (array_reverse($matches[1]) as $id) {
                $candidates[] = trim($id);
            }
        }

        foreach (array_unique(array_filter($candidates)) as $messageId) {
            $recipient = CampaignRecipient::withoutGlobalScopes()
                ->whereHas('campaign', fn ($q) => $q->where('account_id', $email->account_id))
                ->where('message_id', $messageId)
                ->first();

            if ($recipient) {
                return ['recipient' => $recipient, 'method' => 'header', 'confidence' => self::CERTAIN];
            }
        }

        return null;
    }

    /**
     * A per-recipient token that came back in the address the reply was sent to.
     *
     * Only useful where the sending domain routes those addresses to a mailbox
     * this app syncs; the token is generated for every recipient regardless, so
     * this path costs nothing when it is not configured and works the moment it
     * is.
     *
     * @return array{recipient: CampaignRecipient, method: string, confidence: string}|null
     */
    protected function byToken(Email $email): ?array
    {
        $haystack = implode(' ', array_filter([
            (string) $email->reply_to,
            collect((array) $email->to)->map(fn ($t) => is_array($t) ? ($t['email'] ?? '') : $t)->implode(' '),
            collect((array) $email->cc)->map(fn ($t) => is_array($t) ? ($t['email'] ?? '') : $t)->implode(' '),
        ]));

        // The shape a VERP-style address takes: something+TOKEN@domain.
        if (! preg_match_all('/\+([A-Za-z0-9]{16,64})@/', $haystack, $matches)) {
            return null;
        }

        foreach (array_unique($matches[1]) as $token) {
            $recipient = CampaignRecipient::withoutGlobalScopes()
                ->whereHas('campaign', fn ($q) => $q->where('account_id', $email->account_id))
                ->where('reply_token', $token)
                ->first();

            if ($recipient) {
                return ['recipient' => $recipient, 'method' => 'token', 'confidence' => self::CERTAIN];
            }
        }

        return null;
    }

    /**
     * The fallback: this address was mailed recently, and the subject matches.
     *
     * Deliberately narrow. Address alone is not evidence — a customer who
     * emails support about something unrelated would be filed under whatever
     * campaign they last received. Requiring the subject to match the campaign
     * subject (with any Re:/Fwd: prefixes removed) is what makes it a
     * reasonable guess rather than a coin toss, and it is still returned as
     * PROBABLE so a screen can say so.
     *
     * @return array{recipient: CampaignRecipient, method: string, confidence: string}|null
     */
    protected function byAddress(Email $email): ?array
    {
        $from = mb_strtolower(trim((string) $email->from_email));
        $subject = $this->normaliseSubject((string) $email->subject);

        if ($from === '' || $subject === '') {
            return null;
        }

        $since = ($email->received_at ?? now())->copy()->subDays(self::ADDRESS_WINDOW_DAYS);

        $recipients = CampaignRecipient::withoutGlobalScopes()
            ->whereHas('campaign', fn ($q) => $q->where('account_id', $email->account_id))
            ->where('email', $from)
            ->where('status', 'sent')
            ->where('sent_at', '>=', $since)
            ->with('campaign:id,subject,account_id')
            ->orderByDesc('sent_at')
            ->limit(20)
            ->get();

        foreach ($recipients as $recipient) {
            if ($this->normaliseSubject((string) $recipient->campaign?->subject) === $subject) {
                return ['recipient' => $recipient, 'method' => 'address', 'confidence' => self::PROBABLE];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A subject stripped of reply and forward prefixes, for comparison only.
     *
     * The campaign subject may still contain placeholders — the recipient got
     * "Hello Ayesha" where the campaign says "Hello {{first_name}}" — so a
     * subject carrying a token can never match and is not treated as evidence.
     */
    public function normaliseSubject(string $subject): string
    {
        if (str_contains($subject, '{{')) {
            return '';
        }

        $subject = (string) preg_replace('/^\s*((re|aw|sv|fwd|fw|vs)\s*:\s*)+/iu', '', trim($subject));

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $subject)));
    }

    /**
     * Files the reply under the campaign's conversation, creating one the first
     * time this recipient answers.
     */
    protected function threadFor(Email $email, CampaignRecipient $recipient): ?EmailThread
    {
        $key = 'campaign-'.$recipient->campaign_id.'-recipient-'.$recipient->id;

        $thread = EmailThread::withoutGlobalScopes()->firstOrNew([
            'account_id' => $email->account_id,
            'thread_key' => $key,
        ]);

        if (! $thread->exists) {
            $thread->fill([
                'mailbox_id' => $email->mailbox_id,
                'campaign_id' => $recipient->campaign_id,
                'subscriber_id' => $recipient->subscriber_id,
                'subject' => $email->subject,
                'reply_status' => 'new',
                'messages_count' => 0,
                'last_message_at' => $email->received_at ?? now(),
            ]);

            $thread->save();
        }

        return $thread;
    }

    /**
     * A campaign reply that nobody has answered is "new" — that is what the
     * replies screen sorts by, and what makes it a queue rather than a list.
     */
    protected function markThreadNew(Email $email): void
    {
        if ($email->email_thread_id === null) {
            return;
        }

        DB::update(
            "UPDATE email_threads
                SET campaign_id = COALESCE(campaign_id, ?),
                    subscriber_id = COALESCE(subscriber_id, ?),
                    reply_status = CASE WHEN reply_status IN ('replied', 'closed') THEN 'new' ELSE COALESCE(reply_status, 'new') END,
                    last_message_at = GREATEST(COALESCE(last_message_at, ?), ?),
                    updated_at = ?
              WHERE id = ?",
            [
                $email->campaign_id, $email->subscriber_id,
                $email->received_at ?? now(), $email->received_at ?? now(),
                now(), $email->email_thread_id,
            ]
        );
    }

    /**
     * Runs the matcher over messages that arrived before it existed, or before
     * the campaign they answer had finished sending.
     *
     * @return array{checked: int, matched: int}
     */
    public function backfill(int $accountId, int $limit = 500): array
    {
        $emails = Email::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('direction', 'incoming')
            ->where('is_campaign_reply', false)
            ->latest('received_at')
            ->limit($limit)
            ->get();

        $matched = 0;

        foreach ($emails as $email) {
            if ($this->apply($email) !== null) {
                $matched++;
            }
        }

        return ['checked' => $emails->count(), 'matched' => $matched];
    }

    /**
     * The campaigns a reply could plausibly belong to, for a person deciding
     * by hand when the matcher would not commit.
     *
     * @return \Illuminate\Support\Collection<int, Campaign>
     */
    public function suggestionsFor(Email $email, int $limit = 5)
    {
        $from = mb_strtolower(trim((string) $email->from_email));

        if ($from === '') {
            return collect();
        }

        return Campaign::query()
            ->whereHas('recipients', fn ($q) => $q->where('email', $from)->where('status', 'sent'))
            ->latest('started_at')
            ->limit($limit)
            ->get(['id', 'name', 'subject', 'started_at']);
    }
}
