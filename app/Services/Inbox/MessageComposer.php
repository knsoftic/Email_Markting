<?php

namespace App\Services\Inbox;

use App\Models\Email as StoredEmail;
use App\Models\EmailAttachment;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Services\Smtp\SmtpSender;
use App\Support\PlanLimits;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * Builds and sends a message from the inbox, and keeps the copy.
 *
 * ── Threading is a header, not a guess ──────────────────────────────────────
 * A reply carries In-Reply-To and References. Both matter, and for two
 * different people: the recipient's mail client uses them to file our reply
 * under their original, and our own sync uses them to file THEIR next reply
 * under the same conversation when it comes back. Leaving them out produces a
 * mailbox where every message is its own thread, and no amount of subject
 * matching fixes that afterwards.
 *
 * ── Why the Sent copy is written here, not read back over IMAP ──────────────
 * We send over SMTP; most providers do not put that message in the IMAP Sent
 * folder for us. Waiting for a sync to discover our own message would mean the
 * user pressing Send and seeing nothing for five minutes. So the copy is
 * written locally at send time. If the provider does also file it, the sync
 * dedupes on Message-ID and nothing doubles.
 */
class MessageComposer
{
    public function __construct(
        protected SmtpSender $sender,
        protected IncomingHtmlSanitizer $sanitizer,
    ) {}

    /**
     * The prefilled fields for a reply, reply-all or forward.
     *
     * @return array{to: array<int, string>, cc: array<int, string>, subject: string, body_html: string, in_reply_to: ?string, references: ?string, thread_id: ?int}
     */
    public function draftFrom(StoredEmail $source, string $mode, ?Mailbox $mailbox = null): array
    {
        $ourAddresses = $this->ourAddresses($source, $mailbox);

        $to = match ($mode) {
            'reply', 'reply_all' => array_values(array_filter([
                // Reply-To wins over From: that is what the header is for, and
                // ignoring it sends the reply to an unmonitored address.
                $source->reply_to ?: $source->from_email,
            ])),
            default => [],
        };

        $cc = [];

        if ($mode === 'reply_all') {
            $cc = collect(array_merge((array) $source->to, (array) $source->cc))
                ->map(fn ($item) => mb_strtolower((string) (is_array($item) ? ($item['email'] ?? '') : $item)))
                ->filter()
                // Never mail ourselves. Without this, reply-all on a message
                // addressed to this mailbox puts the mailbox in its own Cc and
                // every reply loops back into the inbox.
                ->reject(fn ($email) => in_array($email, $ourAddresses, true))
                ->reject(fn ($email) => in_array($email, array_map('mb_strtolower', $to), true))
                ->unique()
                ->values()
                ->all();
        }

        return [
            'to' => $to,
            'cc' => $cc,
            'subject' => $this->subjectFor($source, $mode),
            'body_html' => $this->quote($source, $mode),
            // A forward starts a new branch: it is not an answer to the
            // original, and threading it as one puts somebody else's
            // conversation into the recipient's copy of ours.
            'in_reply_to' => $mode === 'forward' ? null : $source->message_id,
            'references' => $mode === 'forward' ? null : $this->referencesFor($source),
            'thread_id' => $mode === 'forward' ? null : $source->email_thread_id,
        ];
    }

    /**
     * Sends a stored draft.
     *
     * @return array{ok: bool, message: string}
     */
    public function send(StoredEmail $draft): array
    {
        $mailbox = $draft->mailbox;
        $account = $draft->account;

        if ($account === null) {
            return ['ok' => false, 'message' => 'This draft is not attached to an account.'];
        }

        $recipients = $this->addressList($draft->to);

        if ($recipients === []) {
            return ['ok' => false, 'message' => 'Add at least one recipient before sending.'];
        }

        $limits = PlanLimits::for($account);

        if (! $limits->hasRoomFor('max_emails_per_month')) {
            return ['ok' => false, 'message' => 'This account has used its email allowance for the month.'];
        }

        $message = $this->build($draft, $mailbox);

        $outcome = $this->sender->send($account, $message);
        $this->sender->flush();

        if (! $outcome->sent) {
            $draft->forceFill([
                'send_status' => 'failed',
                'error' => mb_substr((string) $outcome->reason, 0, 1000),
            ])->save();

            return ['ok' => false, 'message' => (string) ($outcome->reason ?: 'The message could not be sent.')];
        }

        $limits->increment('emails_sent');

        $draft->forceFill([
            'direction' => 'outgoing',
            'folder_type' => 'sent',
            'is_draft' => false,
            'is_read' => true,
            'send_status' => 'sent',
            'sent_at' => now(),
            'error' => null,
            'message_id' => $this->messageIdOf($message) ?: $draft->message_id,
            'smtp_account_id' => $outcome->smtpAccount?->id,
        ])->save();

        $this->attachToThread($draft);

        return ['ok' => true, 'message' => 'Sent to '.implode(', ', $recipients).'.'];
    }

    /**
     * Turns the stored draft into the message that actually goes out.
     */
    public function build(StoredEmail $draft, ?Mailbox $mailbox): MimeEmail
    {
        $fromEmail = (string) ($draft->from_email ?: $mailbox?->email);
        $fromName = (string) ($draft->from_name ?: $mailbox?->name ?: '');

        $message = (new MimeEmail)
            ->from(new \Symfony\Component\Mime\Address($fromEmail, $fromName))
            ->subject((string) ($draft->subject ?: '(no subject)'));

        foreach ($this->addressList($draft->to) as $address) {
            $message->addTo($address);
        }

        foreach ($this->addressList($draft->cc) as $address) {
            $message->addCc($address);
        }

        foreach ($this->addressList($draft->bcc) as $address) {
            $message->addBcc($address);
        }

        if ($draft->reply_to) {
            $message->replyTo((string) $draft->reply_to);
        }

        $html = (string) $draft->body_html;

        if (trim($html) !== '') {
            $message->html($html);
        }

        // Always send a text alternative. A message with no text part is more
        // likely to be filtered, and some readers genuinely prefer it.
        $message->text((string) ($draft->body_text ?: $this->textFrom($html)));

        $headers = $message->getHeaders();

        $headers->addIdHeader('Message-ID', [$this->mintMessageId($fromEmail)]);

        // The two headers that make this a reply rather than a new message.
        if ($draft->in_reply_to) {
            $headers->addTextHeader('In-Reply-To', '<'.trim((string) $draft->in_reply_to, '<> ').'>');
        }

        if ($draft->references) {
            $headers->addTextHeader('References', (string) $draft->references);
        }

        foreach ($draft->attachments as $attachment) {
            if (Storage::disk($attachment->disk)->exists($attachment->path)) {
                $message->attach(
                    Storage::disk($attachment->disk)->get($attachment->path),
                    (string) $attachment->name,
                    (string) ($attachment->mime_type ?: 'application/octet-stream')
                );
            }
        }

        return $message;
    }

    // ------------------------------------------------------------- helpers

    /**
     * The addresses that are "us" for this message, so a reply-all never
     * includes them.
     *
     * @return array<int, string>
     */
    protected function ourAddresses(StoredEmail $source, ?Mailbox $mailbox): array
    {
        return array_values(array_filter(array_map('mb_strtolower', [
            (string) ($mailbox?->email ?? ''),
            (string) ($source->mailbox?->email ?? ''),
        ])));
    }

    protected function subjectFor(StoredEmail $source, string $mode): string
    {
        $subject = trim((string) $source->subject);
        $prefix = $mode === 'forward' ? 'Fwd: ' : 'Re: ';

        // Not "Re: Re: Re:". Matched case-insensitively and against the
        // localised prefixes people actually receive.
        if (preg_match('/^\s*(re|aw|sv|fwd|fw|vs)\s*:\s*/iu', $subject)) {
            $subject = (string) preg_replace('/^\s*((re|aw|sv|fwd|fw|vs)\s*:\s*)+/iu', '', $subject);
        }

        return mb_substr($prefix.($subject !== '' ? $subject : '(no subject)'), 0, 255);
    }

    /**
     * References for a reply: the original chain plus the message we answer.
     * That is what keeps a long conversation together in every client.
     */
    protected function referencesFor(StoredEmail $source): ?string
    {
        $chain = trim((string) $source->references);
        $parent = trim((string) $source->message_id);

        if ($parent === '') {
            return $chain !== '' ? $chain : null;
        }

        $parent = '<'.trim($parent, '<> ').'>';

        return trim($chain === '' ? $parent : $chain.' '.$parent);
    }

    /**
     * The quoted original, as a blockquote.
     *
     * The source is run through the sanitiser again on the way in: it is being
     * embedded in something we will later send, and a draft is stored and
     * re-rendered in the composer before that.
     */
    protected function quote(StoredEmail $source, string $mode): string
    {
        $when = $source->received_at?->toDayDateTimeString() ?? '';
        $who = e($source->fromDisplay());
        $address = e((string) $source->from_email);

        $body = $source->body_html
            ? $this->sanitizer->prepare($source, showRemoteImages: false)['html']
            : $this->sanitizer->fromPlainText((string) $source->body_text);

        $intro = $mode === 'forward'
            ? '---------- Forwarded message ----------<br>From: '.$who.' &lt;'.$address.'&gt;'
                .($when !== '' ? '<br>Date: '.e($when) : '')
                .'<br>Subject: '.e((string) $source->subject)
            : 'On '.e($when).', '.$who.' &lt;'.$address.'&gt; wrote:';

        // <blockquote>, not a styled <div>.
        //
        // Two reasons, and the second is the one that matters. Quill normalises
        // whatever it is given into its own formats: a <div class="kn-quote">
        // is flattened away, and the quote arrives at the recipient looking
        // like new text. blockquote is a format Quill knows and keeps.
        //
        // And it is what receiving clients look for to COLLAPSE quoted history.
        // Without it, every reply on a long thread arrives with the entire
        // chain expanded — the difference between a two-line email and a wall
        // of text.
        return '<p><br></p><blockquote style="border-left:3px solid #cbd5e1;margin:16px 0;padding-left:12px;color:#475569;">'
            .'<p style="margin:0 0 8px;font-size:13px;">'.$intro.'</p>'
            .$body
            .'</blockquote>';
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    public function addressList($value): array
    {
        return collect((array) $value)
            ->map(function ($item) {
                $email = is_array($item) ? ($item['email'] ?? '') : $item;

                return mb_strtolower(trim((string) $email));
            })
            ->filter(fn ($email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    protected function textFrom(string $html): string
    {
        $text = html_entity_decode(strip_tags(
            (string) preg_replace('#<br\s*/?>|</p>|</div>|</tr>#i', "\n", $html)
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    protected function mintMessageId(string $fromEmail): string
    {
        $domain = Str::after($fromEmail, '@') ?: 'localhost';

        return Str::uuid().'@'.$domain;
    }

    protected function messageIdOf(MimeEmail $message): ?string
    {
        $header = $message->getHeaders()->get('Message-ID');

        return $header ? trim($header->getBodyAsString(), '<> ') : null;
    }

    /**
     * Files the sent message under the conversation it belongs to, creating
     * one when this is the start of a new exchange.
     */
    protected function attachToThread(StoredEmail $email): void
    {
        if ($email->email_thread_id !== null) {
            EmailThread::withoutGlobalScopes()->whereKey($email->email_thread_id)->update([
                'messages_count' => \Illuminate\Support\Facades\DB::raw('messages_count + 1'),
                'last_message_at' => now(),
                'reply_status' => 'replied',
                'updated_at' => now(),
            ]);

            return;
        }

        $thread = EmailThread::withoutGlobalScopes()->create([
            'account_id' => $email->account_id,
            'mailbox_id' => $email->mailbox_id,
            'thread_key' => (string) $email->message_id,
            'subject' => $email->subject,
            'messages_count' => 1,
            'reply_status' => 'replied',
            'last_message_at' => now(),
        ]);

        $email->forceFill(['email_thread_id' => $thread->id])->save();
    }

    /**
     * Copies an uploaded file into the draft, within the plan's storage
     * allowance and the install's per-file ceiling.
     *
     * @return array{ok: bool, message: string, attachment: ?EmailAttachment}
     */
    public function attachUpload(StoredEmail $draft, \Illuminate\Http\UploadedFile $file): array
    {
        $maxBytes = max(1, (int) config('knsoftic.max_attachment_kb', 10240)) * 1024;

        if ($file->getSize() > $maxBytes) {
            return [
                'ok' => false,
                'attachment' => null,
                'message' => 'That file is larger than the '.round($maxBytes / 1048576, 1).' MB limit for this install.',
            ];
        }

        $limits = PlanLimits::for($draft->account);

        if (! $limits->hasRoomFor('max_storage_mb', (int) ceil($file->getSize() / 1048576))) {
            return [
                'ok' => false,
                'attachment' => null,
                'message' => 'This account has no storage left. Remove some attachments or upgrade the plan.',
            ];
        }

        $path = sprintf('attachments/%d/%d/%s', $draft->account_id, $draft->id, Str::uuid().'.'.$this->extensionFor($file));

        Storage::disk('local')->put($path, $file->get());

        $attachment = EmailAttachment::withoutGlobalScopes()->create([
            'email_id' => $draft->id,
            'account_id' => $draft->account_id,
            'name' => mb_substr($file->getClientOriginalName(), 0, 191),
            'mime_type' => mb_substr((string) $file->getMimeType(), 0, 127) ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'disk' => 'local',
            'path' => $path,
            'is_inline' => false,
        ]);

        $draft->forceFill([
            'has_attachments' => true,
            'attachments_count' => $draft->attachments()->count(),
        ])->save();

        return ['ok' => true, 'attachment' => $attachment, 'message' => 'Attached '.$attachment->name.'.'];
    }

    /**
     * The extension from the uploaded name only — never from the claimed MIME
     * type. The file is stored under a UUID on a private disk, so this is
     * descriptive, not load bearing.
     */
    protected function extensionFor(\Illuminate\Http\UploadedFile $file): string
    {
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) ? $extension : 'bin';
    }
}
