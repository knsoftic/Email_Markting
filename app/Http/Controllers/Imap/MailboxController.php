<?php

namespace App\Http\Controllers\Imap;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imap\MailboxRequest;
use App\Jobs\Imap\SyncMailboxJob;
use App\Models\Mailbox;
use App\Models\SmtpAccount;
use App\Services\Imap\MailboxTester;
use App\Support\ActivityLogger;
use App\Support\ImapProviders;
use App\Support\PlanLimits;
use App\Support\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MailboxController extends Controller
{
    public function __construct(
        protected MailboxTester $tester,
        protected TenantManager $tenant,
    ) {}

    public function index(Request $request): View
    {
        $limits = PlanLimits::for($request->user()->account);

        return view('mailboxes.index', [
            'mailboxes' => Mailbox::query()
                ->withCount('folders')
                ->orderBy('name')
                ->get(),
            'imapAllowed' => $limits->allows('allow_imap'),
            'mailboxLimit' => $limits->limit('max_mailboxes'),
            'mailboxesUsed' => $limits->usageFor('max_mailboxes'),
            'storageLimit' => $limits->limit('max_storage_mb'),
            'storageUsed' => $limits->usageFor('max_storage_mb'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->assertMayAdd($request);

        return view('mailboxes.create', $this->formData($request, new Mailbox([
            'provider' => 'custom',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_validate_cert' => true,
            'sync_enabled' => true,
            'sync_interval_minutes' => (int) config('knsoftic.mailbox_sync_minutes', 5),
            'sync_limit' => (int) config('knsoftic.mailbox_sync_limit', 100),
            'is_active' => true,
        ])));
    }

    public function store(MailboxRequest $request): RedirectResponse
    {
        $this->assertMayAdd($request);

        $mailbox = Mailbox::create(array_merge($request->persistable(), [
            'user_id' => $request->user()->id,
            'status' => 'pending',
        ]));

        ActivityLogger::log('mailbox.created', "Connected mailbox {$mailbox->email}", [], $mailbox);

        return to_route('mailboxes.show', $mailbox)
            ->with('success', 'Mailbox saved. Test the connection before the first sync.');
    }

    public function show(Mailbox $mailbox): View
    {
        return view('mailboxes.show', [
            'mailbox' => $mailbox,
            'folders' => $mailbox->folders()->orderByRaw(
                "FIELD(type,'inbox','sent','drafts','archive','spam','trash','custom'), path"
            )->get(),
            'provider' => ImapProviders::get((string) $mailbox->provider),
            'recent' => $mailbox->emails()
                ->latest('received_at')
                ->limit(10)
                ->get(['id', 'subject', 'from_email', 'from_name', 'received_at', 'is_read', 'folder_type']),
        ]);
    }

    public function edit(Request $request, Mailbox $mailbox): View
    {
        return view('mailboxes.edit', $this->formData($request, $mailbox));
    }

    public function update(MailboxRequest $request, Mailbox $mailbox): RedirectResponse
    {
        $before = $this->connectionFingerprint($mailbox);

        $mailbox->update($request->persistable());

        // Changing where or who we connect as invalidates what we knew about
        // the connection. Saying "connected" against settings that have never
        // been tried is the kind of small lie that costs an hour later.
        if ($this->connectionFingerprint($mailbox->fresh()) !== $before) {
            $mailbox->forceFill([
                'status' => 'pending',
                'last_tested_at' => null,
                'consecutive_failures' => 0,
                'last_error' => null,
                'last_error_at' => null,
            ])->save();
        }

        ActivityLogger::log('mailbox.updated', "Updated mailbox {$mailbox->email}", [], $mailbox);

        return back()->with('success', 'Mailbox updated.');
    }

    /**
     * A real connection attempt, answered as JSON so the form can show the
     * result without losing what the operator has typed.
     */
    public function test(Mailbox $mailbox): JsonResponse
    {
        $result = $this->tester->test($mailbox);

        ActivityLogger::log(
            'mailbox.tested',
            "Tested {$mailbox->email}: ".($result['ok'] ? 'connected' : 'failed'),
            [], $mailbox
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Queues a sync now, rather than waiting for the interval.
     *
     * Queued rather than run inline: an IMAP fetch can take a minute, and a
     * web request that holds a socket open that long is one the browser will
     * abandon halfway through.
     */
    public function sync(Mailbox $mailbox): RedirectResponse
    {
        abort_unless($mailbox->is_active, 422, 'This mailbox is switched off.');

        SyncMailboxJob::dispatch($mailbox->id);

        return back()->with('success', 'Sync queued. New messages appear as the worker gets through them.');
    }

    /**
     * Turns automatic syncing back on after it was switched off by repeated
     * failures — and clears the streak, so one more failure does not
     * immediately switch it off again.
     */
    public function resume(Mailbox $mailbox): RedirectResponse
    {
        $mailbox->forceFill([
            'sync_enabled' => true,
            'consecutive_failures' => 0,
            'last_error' => null,
            'last_error_at' => null,
            'status' => 'pending',
        ])->save();

        ActivityLogger::log('mailbox.sync_resumed', "Re-enabled sync for {$mailbox->email}", [], $mailbox);

        return back()->with('success', 'Automatic syncing is on again. Test the connection if it was a password problem.');
    }

    public function toggleStatus(Mailbox $mailbox): RedirectResponse
    {
        $active = ! $mailbox->is_active;

        // 'disconnected' was in the enum from Phase 1 and nothing ever wrote
        // it, so the UI carried a branch that could not happen. Switching a
        // mailbox off is exactly what it means; switching it back on returns
        // it to 'pending', because nothing has been tried since.
        $mailbox->forceFill([
            'is_active' => $active,
            'status' => $active
                ? ($mailbox->status === 'disconnected' ? 'pending' : $mailbox->status)
                : 'disconnected',
        ])->save();

        return back()->with('success', $mailbox->is_active
            ? 'Mailbox switched on.'
            : 'Mailbox switched off. Nothing will sync until it is switched back on.');
    }

    public function destroy(Mailbox $mailbox): RedirectResponse
    {
        $email = $mailbox->email;
        $messages = (int) $mailbox->messages_count;

        $mailbox->delete();

        ActivityLogger::log('mailbox.deleted', "Disconnected mailbox {$email}");

        return to_route('mailboxes.index')->with('success', sprintf(
            'Disconnected %s. The %s already synced stay in the inbox; nothing was removed from the mail server.',
            $email,
            $messages === 1 ? '1 message' : number_format($messages).' messages'
        ));
    }

    // ----------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    protected function formData(Request $request, Mailbox $mailbox): array
    {
        return [
            'mailbox' => $mailbox,
            'providers' => ImapProviders::all(),
            'smtpAccounts' => SmtpAccount::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'host', 'from_email']),
        ];
    }

    /**
     * What identifies this connection. Used to decide whether a saved change
     * invalidates a previous successful test.
     */
    protected function connectionFingerprint(Mailbox $mailbox): string
    {
        return implode('|', [
            $mailbox->imap_host,
            $mailbox->imap_port,
            $mailbox->imap_encryption,
            $mailbox->imap_username,
            (string) $mailbox->imap_validate_cert,
            // The password itself, so changing only the password still counts
            // as a change worth retesting. It never leaves this method.
            hash('sha256', (string) $mailbox->imap_password),
        ]);
    }

    protected function assertMayAdd(Request $request): void
    {
        $limits = PlanLimits::for($request->user()->account);

        $limits->ensureFeature('allow_imap', 'mailbox syncing');
        $limits->ensure('max_mailboxes');
    }
}
