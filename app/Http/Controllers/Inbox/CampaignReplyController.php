<?php

namespace App\Http\Controllers\Inbox;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Email;
use App\Models\EmailThread;
use App\Services\Inbox\IncomingHtmlSanitizer;
use App\Services\Inbox\ReplyMatcher;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The replies a campaign produced, as a queue rather than a list.
 *
 * ── Why this is not just the inbox with a filter ────────────────────────────
 * A campaign reply has a state the rest of the inbox does not: somebody either
 * has or has not dealt with it. Sorting by "new first" and letting a person
 * mark a conversation closed is the whole difference between a screen you work
 * through and a screen you scroll past. The state lives on the thread, not the
 * message, because a conversation is what gets answered.
 */
class CampaignReplyController extends Controller
{
    /** The states a conversation moves through. */
    public const STATUSES = ['new', 'read', 'replied', 'closed'];

    public function __construct(
        protected ReplyMatcher $matcher,
        protected IncomingHtmlSanitizer $sanitizer,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->filters(['q', 'status', 'campaign']);

        $threads = EmailThread::query()
            ->whereNotNull('campaign_id')
            ->with(['campaign:id,name,subject'])
            ->when($filters['status'] ?? null,
                fn ($q, $status) => $q->where('reply_status', $status))
            ->when($filters['campaign'] ?? null,
                fn ($q, $id) => $q->where('campaign_id', (int) $id))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(function ($inner) use ($term) {
                $inner->where('subject', 'like', '%'.$term.'%')
                    ->orWhereExists(fn ($sub) => $sub->selectRaw(1)
                        ->from('emails')
                        ->whereColumn('emails.email_thread_id', 'email_threads.id')
                        ->where(fn ($w) => $w->where('from_email', 'like', '%'.$term.'%')
                            ->orWhere('preview', 'like', '%'.$term.'%')));
            }))
            // New first, then by recency: this is a queue, and the oldest
            // unanswered reply is the one somebody is waiting on.
            ->orderByRaw("FIELD(reply_status, 'new', 'read', 'replied', 'closed')")
            ->orderByDesc('last_message_at')
            ->paginate(25)
            ->withQueryString();

        return view('campaign-replies.index', [
            'threads' => $threads,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'counts' => $this->statusCounts(),
            'campaigns' => Campaign::query()
                ->whereHas('threads')
                ->orderByDesc('started_at')
                ->limit(50)
                ->get(['id', 'name']),
        ]);
    }

    /** One conversation, oldest message first. */
    public function show(EmailThread $thread): View
    {
        abort_if($thread->campaign_id === null, 404);

        $messages = Email::query()
            ->where('email_thread_id', $thread->id)
            ->with('attachments')
            ->orderByRaw('COALESCE(received_at, sent_at, created_at) ASC')
            ->get();

        // Reading a conversation is what moves it out of "new" — an explicit
        // "mark as read" button for something you are plainly looking at is
        // busywork.
        if ($thread->reply_status === 'new') {
            $thread->forceFill(['reply_status' => 'read'])->save();
        }

        Email::query()
            ->where('email_thread_id', $thread->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'updated_at' => now()]);

        return view('campaign-replies.show', [
            'thread' => $thread->load(['campaign:id,name,subject,status']),
            'messages' => $messages,
            'bodies' => $messages->mapWithKeys(fn (Email $message) => [
                $message->id => $this->sanitizer->prepare($message)['html'],
            ]),
            'statuses' => self::STATUSES,
        ]);
    }

    /** Moves the conversation through its states. */
    public function updateStatus(Request $request, EmailThread $thread): RedirectResponse
    {
        abort_if($thread->campaign_id === null, 404);

        $status = (string) $request->filter('status');

        abort_unless(in_array($status, self::STATUSES, true), 422, 'That is not a status a conversation can be in.');

        $thread->forceFill(['reply_status' => $status])->save();

        return back()->with('success', match ($status) {
            'closed' => 'Conversation closed. It stays searchable and reopens if they write again.',
            'new' => 'Put back in the queue.',
            default => 'Marked as '.$status.'.',
        });
    }

    /**
     * Re-runs the matcher over messages that arrived before the campaign they
     * answer had finished sending, or before the matcher existed.
     */
    public function rematch(Request $request): RedirectResponse
    {
        $result = $this->matcher->backfill($request->user()->account_id);

        ActivityLogger::log(
            'replies.rematched',
            sprintf('Checked %d message(s) for campaign replies, matched %d', $result['checked'], $result['matched'])
        );

        return back()->with($result['matched'] > 0 ? 'success' : 'info', sprintf(
            'Checked %s and matched %s.',
            $result['checked'] === 1 ? '1 message' : number_format($result['checked']).' messages',
            $result['matched'] === 1 ? '1 new reply' : number_format($result['matched']).' new replies'
        ));
    }

    /**
     * Detaches a reply that was matched to the wrong campaign.
     *
     * The address fallback is a judgement, so it will occasionally be wrong,
     * and a screen that cannot correct itself makes the operator distrust
     * every number on it.
     */
    public function detach(Email $email): RedirectResponse
    {
        abort_unless($email->is_campaign_reply, 404);

        $campaignId = $email->campaign_id;
        $recipientId = $email->campaign_recipient_id;

        $email->forceFill([
            'is_campaign_reply' => false,
            'campaign_id' => null,
            'campaign_recipient_id' => null,
        ])->save();

        // Give the count back, but only if this was the message that earned it.
        if ($recipientId) {
            $stillReplied = Email::query()
                ->where('campaign_recipient_id', $recipientId)
                ->where('is_campaign_reply', true)
                ->exists();

            if (! $stillReplied) {
                \App\Models\CampaignRecipient::withoutGlobalScopes()
                    ->whereKey($recipientId)->update(['replied_at' => null]);

                \Illuminate\Support\Facades\DB::update(
                    'UPDATE campaigns SET replied_count = GREATEST(replied_count - 1, 0), updated_at = ?
                      WHERE id = ?',
                    [now(), $campaignId]
                );
            }
        }

        return back()->with('success', 'Unlinked from the campaign. The message stays in the inbox.');
    }

    /**
     * @return array<string, int>
     */
    protected function statusCounts(): array
    {
        $rows = EmailThread::query()
            ->whereNotNull('campaign_id')
            ->selectRaw('reply_status, COUNT(*) AS aggregate')
            ->groupBy('reply_status')
            ->pluck('aggregate', 'reply_status');

        $counts = ['all' => (int) $rows->sum()];

        foreach (self::STATUSES as $status) {
            $counts[$status] = (int) ($rows[$status] ?? 0);
        }

        return $counts;
    }
}
