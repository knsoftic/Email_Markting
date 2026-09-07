<?php

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\CampaignRequest;
use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\Segment;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Services\Campaigns\BlockCatalogue;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignRunner;
use App\Services\Campaigns\EmailCompiler;
use App\Services\Campaigns\PersonalizationEngine;
use App\Services\Campaigns\RecipientGenerator;
use App\Services\Smtp\SmtpSelector;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function __construct(
        protected CampaignDispatcher $dispatcher,
        protected CampaignRunner $runner,
        protected RecipientGenerator $generator,
        protected EmailCompiler $compiler,
        protected BlockCatalogue $catalogue,
        protected PersonalizationEngine $personalization,
        protected SmtpSelector $selector,
    ) {}

    public function index(Request $request): View
    {
        // The sanitised values are what the view gets too — $request->only()
        // would hand it the raw input, so ?q[]=x would still reach the markup
        // as an array and blow up on the first interpolation.
        $filters = ['q' => $request->filter('q'), 'status' => $request->filter('status')];

        $campaigns = Campaign::query()
            ->when($filters['q'], fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('campaigns.index', [
            'campaigns' => $campaigns,
            'filters' => $filters,
            'statusCounts' => Campaign::query()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')->pluck('aggregate', 'status'),
        ]);
    }

    public function scheduled(): View
    {
        return view('campaigns.scheduled', [
            'campaigns' => Campaign::query()
                ->where('status', 'scheduled')
                ->orderBy('scheduled_at')
                ->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        return view('campaigns.edit', $this->formData($request, new Campaign([
            'from_name' => $request->user()->account?->name,
            'timezone' => $request->user()->account?->timezone ?? 'UTC',
            'track_opens' => true,
            'track_clicks' => true,
            'status' => 'draft',
        ])));
    }

    public function store(CampaignRequest $request): RedirectResponse
    {
        $campaign = Campaign::create($this->compiled($request));

        ActivityLogger::log('campaign.created', "Created campaign {$campaign->name}", [], $campaign);

        return to_route('campaigns.edit', $campaign)->with('success', 'Campaign saved as a draft.');
    }

    public function show(Campaign $campaign): View
    {
        return view('campaigns.show', [
            'campaign' => $campaign,
            'audience' => $this->generator->summarise($campaign),
            'blockers' => $this->dispatcher->blockers($campaign),
            'counts' => $this->runner->statusCounts($campaign),
            'outstanding' => $this->runner->outstanding($campaign),
            'smtp' => $campaign->smtpAccount,
        ]);
    }

    public function edit(Request $request, Campaign $campaign): View
    {
        // A campaign that is sending must not have its content changed under
        // the recipients who have not received it yet — half the list would
        // get one email and half another, with no way to tell them apart.
        abort_unless($campaign->isEditable(), 403,
            'This campaign is sending or already sent. Duplicate it to make changes.');

        return view('campaigns.edit', $this->formData($request, $campaign));
    }

    public function update(CampaignRequest $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->isEditable(), 403,
            'This campaign is sending or already sent. Duplicate it to make changes.');

        $campaign->update($this->compiled($request));

        ActivityLogger::log('campaign.updated', "Updated campaign {$campaign->name}", [], $campaign);

        return back()->with('success', 'Campaign updated.');
    }

    /** The pre-send confirmation: exactly who this reaches, and through what. */
    public function confirm(Campaign $campaign): View
    {
        return view('campaigns.confirm', [
            'campaign' => $campaign,
            'audience' => $this->generator->summarise($campaign),
            'blockers' => $this->dispatcher->blockers($campaign),
            // Things worth saying that are not reasons to refuse the send.
            'warnings' => $this->dispatcher->warnings($campaign),
            'smtp' => $this->selector->candidatesFor($campaign->account),
            'monthlyRemaining' => PlanLimits::for($campaign->account)->remaining('max_emails_per_month'),
        ]);
    }

    public function send(Request $request, Campaign $campaign): RedirectResponse
    {
        $total = $this->dispatcher->sendNow($campaign);

        return to_route('campaigns.show', $campaign)
            ->with('success', 'Sending to '.number_format($total).' contact(s). Progress updates below.');
    }

    public function schedule(Request $request, Campaign $campaign): RedirectResponse
    {
        $validated = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'timezone' => ['required', 'timezone'],
        ]);

        // Read in the zone the operator chose, never the app's. The screen's
        // browser-side minimum is computed when the page renders, so a page
        // left open past its own pre-filled time still posts a moment that is
        // now in the past — and the dispatcher answers that with abort(422),
        // which is a raw error page rather than a message on the field.
        if (Carbon::parse($validated['scheduled_at'], $validated['timezone'])->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'That time has already passed. Pick a time in the future.',
            ]);
        }

        $this->dispatcher->schedule($campaign, $validated['scheduled_at'], $validated['timezone']);

        return to_route('campaigns.show', $campaign)->with('success', 'Campaign scheduled.');
    }

    public function unschedule(Campaign $campaign): RedirectResponse
    {
        $this->dispatcher->unschedule($campaign);

        return back()->with('success', 'Schedule removed. The campaign is a draft again.');
    }

    public function pause(Campaign $campaign): RedirectResponse
    {
        $this->dispatcher->pause($campaign);

        return back()->with('warning', 'Paused. Messages already sent cannot be recalled.');
    }

    public function resume(Campaign $campaign): RedirectResponse
    {
        $this->dispatcher->resume($campaign);

        return back()->with('success', 'Resumed from where it stopped.');
    }

    public function cancel(Campaign $campaign): RedirectResponse
    {
        $dropped = $this->dispatcher->cancel($campaign);

        return back()->with('warning',
            'Cancelled. '.number_format($dropped).' unsent message(s) were dropped; those already sent cannot be recalled.');
    }

    public function test(Request $request, Campaign $campaign): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:191'],
        ]);

        $result = $this->dispatcher->sendTest($campaign, $validated['email']);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Polled by the progress panel while a campaign is sending. */
    public function progress(Campaign $campaign): JsonResponse
    {
        $counts = $this->runner->statusCounts($campaign);

        return response()->json([
            'status' => $campaign->status,
            'total' => (int) $campaign->total_recipients,
            'sent' => (int) $campaign->sent_count,
            'failed' => (int) $campaign->failed_count,
            'bounced' => (int) $campaign->bounced_count,
            'skipped' => (int) ($counts['skipped'] ?? 0),
            'remaining' => $this->runner->outstanding($campaign),
            'percent' => $campaign->progressPercent(),
            'finished' => in_array($campaign->status, ['completed', 'failed', 'cancelled'], true),
            'paused' => $campaign->status === 'paused',
            'last_error' => $campaign->last_error,
        ]);
    }

    public function preview(Campaign $campaign): Response
    {
        $sample = Subscriber::query()->mailable()->first();

        return response($this->personalization->render((string) $campaign->html, $sample, $campaign))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            // frame-ancestors rather than X-Frame-Options, for the same reason
            // as the template preview: this is embedded in a sandboxed frame,
            // whose origin is opaque and therefore never "same".
            ->header(
                'Content-Security-Policy',
                "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; frame-ancestors 'self'"
            )
            // The remote images in a preview are fetched by the browser, and
            // without this each request tells the image's host which page of
            // this application was open. The inbox body has always said so.
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function duplicate(Request $request, Campaign $campaign): RedirectResponse
    {
        $copy = Campaign::create([
            'user_id' => $request->user()->id,
            'name' => $campaign->name.' (copy)',
            'subject' => $campaign->subject,
            'preview_text' => $campaign->preview_text,
            'from_name' => $campaign->from_name,
            'from_email' => $campaign->from_email,
            'reply_to' => $campaign->reply_to,
            'email_template_id' => $campaign->email_template_id,
            'blocks' => $campaign->blocks,
            'html' => $campaign->html,
            'plain_text' => $campaign->plain_text,
            'smtp_account_id' => $campaign->smtp_account_id,
            'audience' => $campaign->audience,
            'timezone' => $campaign->timezone,
            'track_opens' => $campaign->track_opens,
            'track_clicks' => $campaign->track_clicks,
            'status' => 'draft',
        ]);

        ActivityLogger::log('campaign.duplicated', "Duplicated {$campaign->name}", [], $copy);

        return to_route('campaigns.edit', $copy)->with('success', 'Campaign duplicated as a new draft.');
    }

    public function destroy(Campaign $campaign): RedirectResponse
    {
        abort_if(in_array($campaign->status, ['queued', 'sending'], true), 422,
            'Pause or cancel the campaign before deleting it.');

        $name = $campaign->name;
        $campaign->delete();

        ActivityLogger::log('campaign.deleted', "Deleted campaign {$name}");

        return to_route('campaigns.index')
            ->with('success', "\"{$name}\" deleted. Its sending history is kept in the logs.");
    }

    // ----------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    protected function formData(Request $request, Campaign $campaign): array
    {
        return [
            'campaign' => $campaign,
            'document' => $this->catalogue->normalise($campaign->blocks),
            'blockTypes' => $this->catalogue->forUi(),
            'tokens' => $this->personalization->catalogue(),
            'templates' => EmailTemplate::active()->orderByDesc('is_system')->orderBy('name')->get(['id', 'name', 'is_system']),
            'lists' => SubscriberList::orderBy('name')->get(['id', 'name', 'active_count']),
            'tags' => Tag::orderBy('name')->get(['id', 'name', 'subscribers_count']),
            'segments' => Segment::orderBy('name')->get(['id', 'name', 'cached_count']),
            'smtpAccounts' => $this->selector->candidatesFor($request->user()->account),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function compiled(CampaignRequest $request): array
    {
        $data = $request->validated();
        $document = ['settings' => $data['settings'] ?? [], 'blocks' => $data['blocks'] ?? []];

        return array_merge(
            collect($data)->except(['settings', 'blocks'])->all(),
            [
                'user_id' => $request->user()->id,
                // Written out in full rather than taken from validated().
                // Unchecked boxes post nothing, so an audience the user has
                // just cleared arrives as a missing key — and validated()
                // drops an empty `audience` anyway, because the key carries an
                // array rule with sub-rules under it. Either way update() would
                // never see the change and would keep mailing the very lists
                // the user removed. The editor promises that selecting nothing
                // means nobody is mailed; this is what makes that true.
                'audience' => [
                    'lists' => $this->audienceIds($data, 'lists'),
                    'tags' => $this->audienceIds($data, 'tags'),
                    'segments' => $this->audienceIds($data, 'segments'),
                ],
                'blocks' => $this->catalogue->normalise($document),
                'html' => $this->compiler->compile($document),
                'plain_text' => $this->compiler->compileText($document),
            ]
        );
    }

    /**
     * One audience group, as a plain list of ints. The browser posts ids as
     * strings, so without this the same selection stores differently
     * depending on the client.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    protected function audienceIds(array $data, string $group): array
    {
        return array_values(array_filter(array_map(
            'intval',
            (array) data_get($data, 'audience.'.$group, [])
        )));
    }
}
