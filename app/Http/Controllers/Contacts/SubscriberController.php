<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\SubscriberRequest;
use App\Models\CustomField;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Services\Contacts\SubscriberService;
use App\Services\Contacts\SuppressionService;
use App\Support\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriberController extends Controller
{
    public function __construct(
        protected SubscriberService $service,
        protected SuppressionService $suppressions,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->filters([
            'q', 'status', 'country', 'city', 'source', 'consent_status',
            'list_id', 'tag_id', 'created_from', 'created_to',
        ]);

        $subscribers = $this->service->filtered($filters)
            ->with(['tags:id,name,color'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $limits = PlanLimits::for($request->user()->account);

        return view('subscribers.index', [
            'subscribers' => $subscribers,
            'filters' => $filters,
            'statusCounts' => $this->service->statusCounts(),
            'lists' => SubscriberList::orderBy('name')->get(['id', 'name']),
            'tags' => Tag::orderBy('name')->get(['id', 'name', 'color']),
            'countries' => Subscriber::query()->whereNotNull('country')
                ->distinct()->orderBy('country')->limit(300)->pluck('country'),
            'contactLimit' => $limits->limit('max_contacts'),
            'contactsUsed' => $limits->usageFor('max_contacts'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('subscribers.create', $this->formData($request, new Subscriber([
            'status' => 'active',
            'consent_status' => 'unknown',
        ])));
    }

    public function store(SubscriberRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['custom'] = $request->customValues();

        $subscriber = $this->service->create(
            collect($data)->except(['list_ids', 'tag_ids'])->all(),
            $data['list_ids'] ?? [],
            $data['tag_ids'] ?? [],
        );

        return to_route('subscribers.show', $subscriber)
            ->with('success', "{$subscriber->email} added.");
    }

    public function show(Subscriber $subscriber): View
    {
        $subscriber->load(['lists:id,name', 'tags:id,name,color']);

        return view('subscribers.show', [
            'subscriber' => $subscriber,
            'customFields' => CustomField::orderBy('sort_order')->get(),
            'isSuppressed' => $this->suppressions->isSuppressed($subscriber->email),
            'campaignHistory' => $subscriber->campaignRecipients()
                ->with('campaign:id,name,subject')
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function edit(Request $request, Subscriber $subscriber): View
    {
        $subscriber->load(['lists:id,name', 'tags:id,name']);

        return view('subscribers.edit', $this->formData($request, $subscriber));
    }

    public function update(SubscriberRequest $request, Subscriber $subscriber): RedirectResponse
    {
        $data = $request->validated();
        $data['custom'] = $request->customValues();

        $this->service->update(
            $subscriber,
            collect($data)->except(['list_ids', 'tag_ids'])->all(),
            $data['list_ids'] ?? [],
            $data['tag_ids'] ?? [],
        );

        return to_route('subscribers.show', $subscriber)->with('success', 'Contact updated.');
    }

    public function destroy(Subscriber $subscriber): RedirectResponse
    {
        $email = $subscriber->email;
        $this->service->delete($subscriber);

        return to_route('subscribers.index')->with('success', "{$email} deleted.");
    }

    /**
     * The permission each bulk action really needs.
     *
     * The route can only carry one middleware, so gating the whole endpoint on
     * contacts.update would let a staff member who cannot delete a single
     * contact delete every contact on the page. Hiding the option in the UI is
     * not enough — a crafted POST bypasses it — so the check lives here.
     */
    protected const ACTION_PERMISSIONS = [
        'add_tags' => 'contacts.update',
        'remove_tags' => 'contacts.update',
        'add_to_lists' => 'contacts.update',
        'remove_from_lists' => 'contacts.update',
        'change_status' => 'contacts.update',
        'unsubscribe' => 'contacts.update',
        'suppress' => 'contacts.update',
        'delete' => 'contacts.delete',
    ];

    /**
     * One endpoint for every multi-row action. The action name and its options
     * are validated here; the ids are re-resolved through the tenant-scoped
     * model inside the service, so foreign ids simply match nothing.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:'.implode(',', SubscriberService::BULK_ACTIONS)],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer'],
            'list_ids' => ['array'],
            'list_ids.*' => ['integer'],
            'status' => ['nullable', 'in:'.implode(',', Subscriber::STATUSES)],
            'reason' => ['nullable', 'string', 'max:40'],
        ]);

        abort_unless(
            $request->user()->hasPermission(self::ACTION_PERMISSIONS[$validated['action']]),
            403,
            'You do not have permission to perform that bulk action.'
        );

        if (in_array($validated['action'], ['add_tags', 'remove_tags'], true) && empty($validated['tag_ids'])) {
            return back()->with('error', 'Pick at least one tag first.');
        }

        if (in_array($validated['action'], ['add_to_lists', 'remove_from_lists'], true) && empty($validated['list_ids'])) {
            return back()->with('error', 'Pick at least one list first.');
        }

        $result = $this->service->bulk($validated['action'], $validated['ids'], $validated);

        return back()->with($result['affected'] > 0 ? 'success' : 'warning', $result['message']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(Request $request, Subscriber $subscriber): array
    {
        return [
            'subscriber' => $subscriber,
            'lists' => SubscriberList::orderBy('name')->get(['id', 'name']),
            'tags' => Tag::orderBy('name')->get(['id', 'name', 'color']),
            'customFields' => CustomField::orderBy('sort_order')->get(),
            'selectedLists' => $subscriber->exists ? $subscriber->lists->pluck('id')->all() : [],
            'selectedTags' => $subscriber->exists ? $subscriber->tags->pluck('id')->all() : [],
        ];
    }
}
