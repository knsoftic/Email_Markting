<?php

namespace App\Http\Controllers\Inbox;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\Mailbox;
use App\Services\Inbox\InboxService;
use App\Services\Inbox\MessageComposer;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Writing and sending.
 *
 * ── Everything is a draft first ─────────────────────────────────────────────
 * Compose, reply and forward all create a real `emails` row with
 * `send_status = 'draft'` before the composer is shown. It costs one insert and
 * it means an attachment has somewhere to belong, a half-written message
 * survives a closed tab, and Send is a state change on a row that already
 * exists rather than a build-and-send that has nowhere to fail safely.
 */
class ComposeController extends Controller
{
    public function __construct(
        protected MessageComposer $composer,
        protected InboxService $inbox,
    ) {}

    /** A blank message. */
    public function create(Request $request): RedirectResponse
    {
        $mailbox = $this->defaultMailbox($request);

        abort_if($mailbox === null, 422, 'Connect a mailbox before writing a message.');

        $draft = $this->newDraft($request, $mailbox);

        return to_route('inbox.compose.edit', $draft);
    }

    /** A reply, reply-all or forward of an existing message. */
    public function respond(Request $request, Email $email, string $mode): RedirectResponse
    {
        abort_unless(in_array($mode, ['reply', 'reply_all', 'forward'], true), 404);

        $mailbox = $email->mailbox ?? $this->defaultMailbox($request);

        abort_if($mailbox === null, 422, 'Connect a mailbox before replying.');

        $prefill = $this->composer->draftFrom($email, $mode, $mailbox);

        $draft = $this->newDraft($request, $mailbox, [
            'to' => $this->asAddressList($prefill['to']),
            'cc' => $this->asAddressList($prefill['cc']),
            'subject' => $prefill['subject'],
            'body_html' => $prefill['body_html'],
            'in_reply_to' => $prefill['in_reply_to'],
            'references' => $prefill['references'],
            'email_thread_id' => $prefill['thread_id'],
            // A forward carries the original's attachments; a reply does not,
            // because the person being replied to already has them.
            'campaign_id' => $email->campaign_id,
            'campaign_recipient_id' => $email->campaign_recipient_id,
            'subscriber_id' => $email->subscriber_id,
        ]);

        if ($mode === 'forward') {
            $this->copyAttachments($email, $draft);
        }

        return to_route('inbox.compose.edit', $draft);
    }

    public function edit(Request $request, Email $draft): View
    {
        abort_unless($draft->is_draft, 404);

        return view('inbox.compose', [
            'draft' => $draft->load('attachments'),
            'mailboxes' => $this->inbox->mailboxes(),
            'counts' => $this->inbox->counts(),
        ]);
    }

    /**
     * Saves without sending. Called by the Save-draft button and by the
     * composer's autosave, which is why it answers JSON for an XHR request:
     * an autosave that navigates the page away would be a bug, not a feature.
     */
    public function update(Request $request, Email $draft): RedirectResponse|JsonResponse
    {
        abort_unless($draft->is_draft, 404);

        $draft->forceFill($this->fieldsFrom($request, $draft))->save();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'saved_at' => now()->toDateTimeString()]);
        }

        return back()->with('success', 'Draft saved.');
    }

    public function send(Request $request, Email $draft): RedirectResponse
    {
        abort_unless($draft->is_draft, 404);

        $draft->forceFill($this->fieldsFrom($request, $draft))->save();

        $result = $this->composer->send($draft->fresh());

        if (! $result['ok']) {
            return back()->with('error', $result['message'])->withInput();
        }

        ActivityLogger::log('inbox.sent', 'Sent "'.$draft->subject.'"', [], $draft);

        return to_route('inbox.show', $draft)->with('success', $result['message']);
    }

    /**
     * Sends later. The scheduler picks it up; nothing is built now, so the
     * message reflects the account's state at the moment it actually goes.
     */
    public function schedule(Request $request, Email $draft): RedirectResponse
    {
        abort_unless($draft->is_draft, 404);

        $validated = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'timezone' => ['required', 'timezone'],
        ]);

        $when = \Illuminate\Support\Carbon::parse($validated['scheduled_at'], $validated['timezone'])->utc();

        abort_if($when->isPast(), 422, 'That time has already passed.');

        $draft->forceFill(array_merge($this->fieldsFrom($request, $draft), [
            'send_status' => 'scheduled',
            'scheduled_at' => $when,
        ]))->save();

        return to_route('inbox.drafts')->with('success', 'Scheduled for '.$when->timezone($validated['timezone'])->toDayDateTimeString().'.');
    }

    public function unschedule(Email $draft): RedirectResponse
    {
        abort_unless($draft->is_draft, 404);

        $draft->forceFill(['send_status' => 'draft', 'scheduled_at' => null])->save();

        return back()->with('success', 'Schedule removed. It is a draft again.');
    }

    /** Throws the draft away. */
    public function destroy(Email $draft): RedirectResponse
    {
        abort_unless($draft->is_draft, 404);

        foreach ($draft->attachments as $attachment) {
            \Illuminate\Support\Facades\Storage::disk($attachment->disk)->delete($attachment->path);
        }

        $draft->attachments()->delete();
        $draft->forceDelete();

        return to_route('inbox.drafts')->with('success', 'Draft discarded.');
    }

    /** Adds a file to the draft. */
    public function attach(Request $request, Email $draft): RedirectResponse
    {
        abort_unless($draft->is_draft, 404);

        $request->validate(['file' => ['required', 'file']]);

        $result = $this->composer->attachUpload($draft, $request->file('file'));

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function detach(Email $draft, \App\Models\EmailAttachment $attachment): RedirectResponse
    {
        abort_unless($draft->is_draft, 404);
        abort_unless((int) $attachment->email_id === (int) $draft->id, 404);

        \Illuminate\Support\Facades\Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        $draft->forceFill([
            'attachments_count' => $draft->attachments()->count(),
            'has_attachments' => $draft->attachments()->exists(),
        ])->save();

        return back()->with('success', 'Attachment removed.');
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function newDraft(Request $request, Mailbox $mailbox, array $extra = []): Email
    {
        return Email::create(array_merge([
            'mailbox_id' => $mailbox->id,
            'user_id' => $request->user()->id,
            'direction' => 'outgoing',
            'folder_type' => 'drafts',
            'is_draft' => true,
            'is_read' => true,
            'send_status' => 'draft',
            'from_name' => $mailbox->name,
            'from_email' => $mailbox->email,
            'to' => [],
            'cc' => [],
            'bcc' => [],
            'subject' => '',
            'body_html' => '',
        ], $extra));
    }

    /**
     * @return array<string, mixed>
     */
    protected function fieldsFrom(Request $request, Email $draft): array
    {
        $mailboxId = (int) ($request->filter('mailbox_id') ?: $draft->mailbox_id);

        // Re-resolved through the tenant scope, so a posted id from another
        // account matches nothing and the draft keeps the mailbox it had.
        $mailbox = Mailbox::query()->find($mailboxId) ?? $draft->mailbox;

        return [
            'mailbox_id' => $mailbox?->id,
            'from_name' => $mailbox?->name,
            'from_email' => $mailbox?->email,
            'to' => $this->parseAddresses($request->input('to')),
            'cc' => $this->parseAddresses($request->input('cc')),
            'bcc' => $this->parseAddresses($request->input('bcc')),
            'subject' => mb_substr((string) ($request->filter('subject') ?? ''), 0, 255),
            'body_html' => (string) $request->input('body_html', ''),
            'body_text' => null,
            'preview' => mb_substr(trim((string) preg_replace('/\s+/u', ' ',
                strip_tags((string) $request->input('body_html', '')))), 0, 255) ?: null,
        ];
    }

    /**
     * Accepts what people actually type: commas, semicolons, newlines, and
     * "Name <a@b.com>" pasted from another client.
     *
     * @return array<int, array{name: ?string, email: string}>
     */
    protected function parseAddresses(mixed $value): array
    {
        if (is_array($value)) {
            return $this->asAddressList(
                collect($value)->map(fn ($v) => is_array($v) ? ($v['email'] ?? '') : $v)->all()
            );
        }

        $parts = preg_split('/[,;\r\n]+/', (string) $value) ?: [];

        $emails = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (preg_match('/<([^>]+)>/', $part, $m)) {
                $part = $m[1];
            }

            $emails[] = $part;
        }

        return $this->asAddressList($emails);
    }

    /**
     * @param  array<int, string>  $emails
     * @return array<int, array{name: ?string, email: string}>
     */
    protected function asAddressList(array $emails): array
    {
        return collect($emails)
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter(fn ($email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->map(fn ($email) => ['name' => null, 'email' => $email])
            ->values()
            ->all();
    }

    protected function copyAttachments(Email $source, Email $draft): void
    {
        foreach ($source->attachments as $attachment) {
            if (! \Illuminate\Support\Facades\Storage::disk($attachment->disk)->exists($attachment->path)) {
                continue;
            }

            $path = sprintf(
                'attachments/%d/%d/%s',
                $draft->account_id, $draft->id,
                \Illuminate\Support\Str::uuid().'.'.(pathinfo($attachment->path, PATHINFO_EXTENSION) ?: 'bin')
            );

            // Copied rather than shared: deleting the forward later must not
            // remove the file from the message it came from.
            \Illuminate\Support\Facades\Storage::disk('local')->put(
                $path,
                \Illuminate\Support\Facades\Storage::disk($attachment->disk)->get($attachment->path)
            );

            \App\Models\EmailAttachment::withoutGlobalScopes()->create([
                'email_id' => $draft->id,
                'account_id' => $draft->account_id,
                'name' => $attachment->name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'disk' => 'local',
                'path' => $path,
                'is_inline' => false,
            ]);
        }

        $draft->forceFill([
            'has_attachments' => $draft->attachments()->exists(),
            'attachments_count' => $draft->attachments()->count(),
        ])->save();
    }

    protected function defaultMailbox(Request $request): ?Mailbox
    {
        $requested = (int) ($request->filter('mailbox') ?? 0);

        return ($requested > 0 ? Mailbox::query()->find($requested) : null)
            ?? Mailbox::query()->where('is_active', true)->orderBy('id')->first();
    }
}
