<?php

namespace App\Http\Controllers\Inbox;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Services\Inbox\IncomingHtmlSanitizer;
use App\Services\Inbox\InboxService;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function __construct(
        protected InboxService $inbox,
        protected IncomingHtmlSanitizer $sanitizer,
    ) {}

    public function index(Request $request): View
    {
        return $this->render($request, 'inbox');
    }

    public function sent(Request $request): View
    {
        return $this->render($request, 'sent');
    }

    public function drafts(Request $request): View
    {
        return $this->render($request, 'drafts');
    }

    public function starred(Request $request): View
    {
        return $this->render($request, 'starred');
    }

    public function spam(Request $request): View
    {
        return $this->render($request, 'spam');
    }

    public function trash(Request $request): View
    {
        return $this->render($request, 'trash');
    }

    public function archive(Request $request): View
    {
        return $this->render($request, 'archive');
    }

    /**
     * One message.
     *
     * Remote images stay blocked unless the reader asks for them on this
     * view — the choice is deliberately not remembered, because "show images"
     * is a decision about one sender's message, not a setting.
     */
    public function show(Request $request, Email $email): View
    {
        $showRemote = $request->filter('images') === 'show';

        $email->markRead();

        $prepared = $this->sanitizer->prepare($email, $showRemote);

        return view('inbox.show', [
            'email' => $email->load(['attachments', 'mailbox:id,name,email', 'thread']),
            'body' => $prepared['html'],
            'blockedImages' => $prepared['blocked_images'],
            'hasRemoteImages' => $prepared['has_remote'],
            'showingRemote' => $showRemote,
            'counts' => $this->inbox->counts(),
            'mailboxes' => $this->inbox->mailboxes(),
            'thread' => $email->email_thread_id
                ? Email::query()
                    ->where('email_thread_id', $email->email_thread_id)
                    ->where('id', '!=', $email->id)
                    ->orderByRaw('COALESCE(received_at, sent_at, created_at) ASC')
                    ->get(['id', 'subject', 'from_name', 'from_email', 'received_at', 'sent_at', 'direction', 'is_read'])
                : collect(),
        ]);
    }

    /**
     * The message body, served standalone for the sandboxed frame.
     *
     * Separate from show() on purpose: the email HTML must never share a
     * document with the application. It is delivered with its own CSP, so even
     * a bypass of the sanitiser has nothing to reach.
     */
    public function body(Request $request, Email $email): Response
    {
        $prepared = $this->sanitizer->prepare($email, $request->filter('images') === 'show');

        return response(view('inbox.body', ['body' => $prepared['html']])->render())
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header(
                'Content-Security-Policy',
                // No script anywhere, and remote images only when the reader
                // asked for them. frame-ancestors rather than X-Frame-Options:
                // a sandboxed frame has an opaque origin and SAMEORIGIN can
                // never match it.
                "default-src 'none'; img-src ".($request->filter('images') === 'show' ? "https: data: " : "data: ")
                .$request->getSchemeAndHttpHost()."; style-src 'unsafe-inline'; frame-ancestors 'self'"
            )
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Bulk actions from the list, and single-message actions from the reader —
     * the same code path, so the two can never disagree about what "archive"
     * means.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $action = (string) $request->filter('action');
        $ids = (array) $request->input('ids', []);

        $result = $this->inbox->bulk($action, $ids);

        // 'delete' is the one action that can destroy the page the reader is
        // standing on. "Delete for good" is offered on the reading screen, and
        // back() would send them straight to /inbox/{id} for a row that no
        // longer exists — a 404 as the reward for a successful delete. The
        // Trash is where the message was, so that is where they go.
        if ($action === 'delete' && $result['count'] > 0) {
            return to_route('inbox.trash')->with('success', $result['message']);
        }

        return back()->with($result['count'] > 0 ? 'success' : 'warning', $result['message']);
    }

    public function toggleStar(Email $email): RedirectResponse
    {
        $email->forceFill(['is_starred' => ! $email->is_starred])->save();

        return back();
    }

    public function toggleRead(Email $email): RedirectResponse
    {
        $email->forceFill(['is_read' => ! $email->is_read])->save();

        // Marking a message unread from its own reading screen is otherwise a
        // write the reader never sees: back() returns to show(), and the first
        // thing show() does is mark it read again. Leaving the message is both
        // what the reader meant by it — "I have not dealt with this yet" — and
        // the only way the flag survives.
        if (! $email->is_read && $this->cameFromReading($email)) {
            return to_route($this->folderRoute($email))
                ->with('success', 'Marked as unread.');
        }

        return back();
    }

    public function emptyTrash(): RedirectResponse
    {
        $result = $this->inbox->emptyTrash();

        ActivityLogger::log(
            'inbox.trash_emptied',
            sprintf('Emptied the trash: %d message(s), %d file(s) removed', $result['messages'], $result['files'])
        );

        return back()->with('success', $result['messages'] === 0
            ? 'The trash was already empty.'
            : sprintf(
                '%s and %s removed for good.',
                $result['messages'] === 1 ? '1 message' : number_format($result['messages']).' messages',
                $result['files'] === 1 ? '1 attachment' : number_format($result['files']).' attachments'
            ));
    }

    // ------------------------------------------------------------- helpers

    /**
     * Was this posted from the reading screen of this very message?
     *
     * Paths are compared, not whole URLs: url()->previous() carries the host
     * the browser actually used, route() builds from APP_URL, and the two do
     * not have to agree — behind a proxy, on a port, or in a subdirectory they
     * usually do not. The query string is ignored so "show images" does not
     * change the answer.
     */
    protected function cameFromReading(Email $email): bool
    {
        $previous = rtrim((string) parse_url((string) url()->previous(), PHP_URL_PATH), '/');

        return $previous !== '' && str_ends_with($previous, route('inbox.show', $email, false));
    }

    /**
     * The folder listing a message belongs to. Named routes only — never a
     * built path.
     */
    protected function folderRoute(Email $email): string
    {
        return match ((string) $email->folder_type) {
            'sent' => 'inbox.sent',
            'drafts' => 'inbox.drafts',
            'spam' => 'inbox.spam',
            'trash' => 'inbox.trash',
            'archive' => 'inbox.archive',
            default => 'inbox.index',
        };
    }

    protected function render(Request $request, string $view): View
    {
        $filters = $request->filters(['q', 'unread', 'attachments', 'mailbox']);

        return view('inbox.index', [
            'view' => $view,
            'emails' => $this->inbox->list($view, $filters),
            'counts' => $this->inbox->counts(),
            'mailboxes' => $this->inbox->mailboxes(),
            'filters' => $filters,
        ]);
    }
}
