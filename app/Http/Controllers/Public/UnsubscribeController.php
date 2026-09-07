<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Subscriber;
use App\Models\Unsubscribe;
use App\Services\Campaigns\LinkSigner;
use App\Services\Contacts\ListService;
use App\Services\Contacts\SuppressionService;
use App\Services\SettingsService;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The public opt-out pages.
 *
 * These are reached from a link inside an email, so there is no session, no
 * logged-in user and no bound tenant. The signature on the URL is the only
 * authorisation, and every lookup therefore runs without the account scope and
 * binds the tenant explicitly from the row it found.
 */
class UnsubscribeController extends Controller
{
    public function __construct(
        protected SuppressionService $suppressions,
        protected SettingsService $settings,
        protected TenantManager $tenant,
        protected ListService $lists,
        protected LinkSigner $links,
    ) {}

    /** The confirmation page. Nothing is changed by a GET. */
    public function show(Request $request, int $subscriber, ?int $campaign = null): View
    {
        [$contact, $campaignModel] = $this->resolve($subscriber, $campaign);

        return view('public.unsubscribe.show', [
            'subscriber' => $contact,
            'campaign' => $campaignModel,
            'account' => $contact->account,
            'alreadyGone' => $this->suppressions->isSuppressed($contact->email, $contact->account_id),
            'confirmUrl' => $request->fullUrl(),
        ]);
    }

    /**
     * The deliberate confirmation. A GET must never unsubscribe: mail clients
     * and security scanners pre-fetch links, and people would be opted out by
     * a scanner rather than by choice.
     */
    public function confirm(Request $request, int $subscriber, ?int $campaign = null): View
    {
        [$contact, $campaignModel] = $this->resolve($subscriber, $campaign);

        $this->recordOptOut($contact, $campaignModel, $request, 'link');

        return view('public.unsubscribe.done', [
            'subscriber' => $contact,
            'account' => $contact->account,
            'resubscribeUrl' => null,
        ]);
    }

    /**
     * RFC 8058 one-click. The mail client POSTs this with no browser session,
     * so it answers with a bare 200 and must never redirect or render a form.
     */
    public function oneClick(Request $request, int $subscriber, ?int $campaign = null): Response
    {
        [$contact, $campaignModel] = $this->resolve($subscriber, $campaign);

        $this->recordOptOut($contact, $campaignModel, $request, 'one_click');

        return response('Unsubscribed', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * The preferences page.
     *
     * Until now this route rendered the unsubscribe confirmation, which meant
     * the two links in every footer did exactly the same thing — one of them
     * lying about what it was for. This is the other option a reader should
     * have: leave one list, not the sender entirely.
     *
     * Only the lists the contact is actually on are shown. Listing every list
     * the account owns would tell a recipient the names of segments they were
     * never part of.
     */
    public function preferences(Request $request, int $subscriber, ?int $campaign = null): View
    {
        [$contact, $campaignModel] = $this->resolve($subscriber, $campaign);

        return view('public.unsubscribe.preferences', [
            'subscriber' => $contact,
            'campaign' => $campaignModel,
            'account' => $contact->account,
            'lists' => $contact->lists()->orderBy('name')->get(['subscriber_lists.id', 'subscriber_lists.name', 'subscriber_lists.description']),
            'suppressed' => $this->suppressions->isSuppressed($contact->email, $contact->account_id),
            'actionUrl' => $request->fullUrl(),
            'unsubscribeUrl' => $this->links->unsubscribeUrl($contact, $campaignModel),
        ]);
    }

    /**
     * Applies the choice.
     *
     * Unticking everything is treated as a full opt-out, and the page says so
     * before it is submitted. Leaving somebody on no lists but off the
     * suppression list is a trap: the next campaign that targets "all
     * contacts" rather than a list would reach them anyway, and they would
     * rightly call that ignoring their choice.
     */
    public function updatePreferences(Request $request, int $subscriber, ?int $campaign = null): View
    {
        [$contact, $campaignModel] = $this->resolve($subscriber, $campaign);

        $current = $contact->lists()->pluck('subscriber_lists.id')->all();

        $keep = collect($request->input('lists', []))
            ->filter(fn ($id) => is_scalar($id))
            ->map(fn ($id) => (int) $id)
            // Only ids the contact already had: a posted id for a list they
            // were never on would otherwise subscribe them to it.
            ->filter(fn ($id) => in_array($id, $current, true))
            ->values()
            ->all();

        $left = array_values(array_diff($current, $keep));

        if ($left !== []) {
            $contact->lists()->detach($left);

            $this->lists->refreshCounts($left, $contact->account_id);
        }

        // Nothing left to receive: record it as the opt-out it is.
        $optedOutEntirely = $keep === [];

        if ($optedOutEntirely) {
            $this->recordOptOut($contact, $campaignModel, $request, 'preferences');
        }

        return view('public.unsubscribe.preferences-saved', [
            'subscriber' => $contact,
            'account' => $contact->account,
            'kept' => count($keep),
            'left' => count($left),
            'optedOut' => $optedOutEntirely,
        ]);
    }

    /** Shown when a preview or test-send link is opened. */
    public function preview(): View
    {
        return view('public.unsubscribe.preview');
    }

    // ------------------------------------------------------------- internals

    /**
     * @return array{0: Subscriber, 1: ?Campaign}
     */
    protected function resolve(int $subscriberId, ?int $campaignId): array
    {
        // No tenant is bound on a public request, so the scope is dropped
        // explicitly rather than relying on it being absent.
        $contact = Subscriber::withoutGlobalScopes()->find($subscriberId);

        abort_if($contact === null, 404);

        $this->tenant->set($contact->account_id);

        $campaign = $campaignId
            ? Campaign::withoutGlobalScopes()
                ->where('account_id', $contact->account_id)
                ->find($campaignId)
            : null;

        return [$contact, $campaign];
    }

    protected function recordOptOut(Subscriber $contact, ?Campaign $campaign, Request $request, string $method): void
    {
        if ($this->suppressions->isSuppressed($contact->email, $contact->account_id)) {
            return; // Opting out twice is not an error, and not a second row.
        }

        Unsubscribe::withoutGlobalScopes()->create([
            'account_id' => $contact->account_id,
            'subscriber_id' => $contact->id,
            'campaign_id' => $campaign?->id,
            'email' => $contact->email,
            'method' => $method,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'unsubscribed_at' => now(),
        ]);

        // The suppression service is what actually stops future sending, and
        // it also flips the contact's own status so the two cannot disagree.
        $this->suppressions->suppress(
            $contact->email,
            'unsubscribed',
            $campaign?->id,
            null,
            $method,
            $contact->account_id,
            log: false,
        );

        if ($campaign) {
            Campaign::withoutGlobalScopes()->whereKey($campaign->id)->increment('unsubscribed_count');

            \App\Models\CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('subscriber_id', $contact->id)
                ->update(['unsubscribed_at' => now()]);
        }
    }
}
