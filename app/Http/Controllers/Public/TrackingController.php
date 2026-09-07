<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Services\Tracking\TrackingRecorder;
use App\Support\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

/**
 * The two endpoints an email itself reaches: the open pixel and the click
 * redirect.
 *
 * There is no session, no logged-in user and no bound tenant here — the URL
 * signature is the whole of the authorisation, so every row is resolved
 * unscoped and the account is taken from the campaign that owns it.
 *
 * ── Recording must never break the reader's experience ──────────────────────
 * These run while a person is waiting. A pixel that 500s shows a broken image
 * inside their email; a redirect that 500s is a dead link they were told to
 * click. So the recording is wrapped: if it throws, the reader still gets
 * their image or their destination, and the send is not what suffers for a
 * reporting failure.
 */
class TrackingController extends Controller
{
    /**
     * A 1x1 transparent GIF — 43 bytes, and the smallest thing that works in
     * every client. A PNG is larger and Outlook has historically been fussier.
     */
    protected const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        protected TrackingRecorder $recorder,
        protected TenantManager $tenant,
    ) {}

    /**
     * The open pixel.
     *
     * Always 200 with the image, whatever happens. An unknown recipient id
     * gets the same response as a known one: this endpoint must not become a
     * way to test which ids exist.
     */
    public function open(Request $request, int $recipient): Response
    {
        try {
            $row = CampaignRecipient::withoutGlobalScopes()->with('campaign')->find($recipient);

            if ($row?->campaign) {
                $this->tenant->runAs(
                    $row->campaign->account_id,
                    fn () => $this->recorder->open($row, $request)
                );
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $this->pixel();
    }

    /**
     * The click redirect.
     *
     * The destination comes from the stored campaign_links row and is checked
     * again here. It is never read from the query string — that would make
     * this an open redirect on a domain recipients have been told to trust,
     * and a phisher would only need to know the URL shape.
     */
    public function click(Request $request, int $link, int $recipient): RedirectResponse|View
    {
        $row = CampaignRecipient::withoutGlobalScopes()->with('campaign')->find($recipient);
        $target = CampaignLink::query()->find($link);

        // The link has to belong to the campaign this recipient was mailed.
        // Without this, a signed URL from one campaign could be paired with a
        // link id from another and record a click that never happened.
        $matches = $row && $target && (int) $target->campaign_id === (int) $row->campaign_id;

        if (! $matches || ! $this->isSafeDestination((string) $target?->url)) {
            return view('public.link-unavailable');
        }

        try {
            $this->tenant->runAs(
                $row->campaign->account_id,
                fn () => $this->recorder->click($row, $target, $request)
            );
        } catch (Throwable $e) {
            // The person still gets where they were going.
            report($e);
        }

        // 302, not 301: a permanent redirect would be cached by the browser
        // and every later click on that link would never reach us again.
        return redirect()->away($target->url, 302);
    }

    /**
     * http and https only, re-checked at redirect time rather than trusted
     * because it passed the check when the campaign was prepared. A row can
     * outlive the code that wrote it.
     */
    protected function isSafeDestination(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // Collapsed the same way the personalisation engine does it: browsers
        // ignore whitespace and control characters inside a scheme, so
        // "java\tscript:" is javascript: to them.
        $collapsed = preg_replace('/[\s\x00-\x20]+/u', '', $url) ?? $url;

        return (bool) preg_match('#^https?://#i', $collapsed);
    }

    protected function pixel(): Response
    {
        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => '43',
            // Every fetch has to reach us or the count stops after the first.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
