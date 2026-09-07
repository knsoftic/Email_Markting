<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\SubscriberListRequest;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Services\Contacts\ListService;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriberListController extends Controller
{
    public function __construct(protected ListService $lists) {}

    public function index(Request $request): View
    {
        $limits = PlanLimits::for($request->user()->account);

        return view('lists.index', [
            'lists' => SubscriberList::query()
                ->when($request->filter('q'),
                    fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $request->filters(['q']),
            'listLimit' => $limits->limit('max_lists'),
            'listsUsed' => $limits->usageFor('max_lists'),
        ]);
    }

    public function create(): View
    {
        return view('lists.create', ['list' => new SubscriberList]);
    }

    public function store(SubscriberListRequest $request): RedirectResponse
    {
        PlanLimits::for($request->user()->account)->ensure('max_lists');

        $list = SubscriberList::create($request->validated());

        ActivityLogger::log('list.created', "Created list {$list->name}", [], $list);

        return to_route('lists.show', $list)->with('success', "List \"{$list->name}\" created.");
    }

    public function show(Request $request, SubscriberList $list): View
    {
        $members = $list->subscribers()
            ->with('tags:id,name,color')
            ->when($request->filter('q'), function ($query, string $term) {
                $like = '%'.$term.'%';
                $query->where(fn ($q) => $q->where('subscribers.email', 'like', $like)
                    ->orWhere('subscribers.name', 'like', $like));
            })
            ->when($request->filter('status'),
                fn ($q, $status) => $q->where('subscribers.status', $status))
            ->orderByDesc('subscribers.id')
            ->paginate(25)
            ->withQueryString();

        return view('lists.show', [
            'list' => $list,
            'members' => $members,
            'filters' => $request->filters(['q', 'status']),
        ]);
    }

    public function edit(SubscriberList $list): View
    {
        return view('lists.edit', ['list' => $list]);
    }

    public function update(SubscriberListRequest $request, SubscriberList $list): RedirectResponse
    {
        $list->update($request->validated());

        ActivityLogger::log('list.updated', "Updated list {$list->name}", [], $list);

        return to_route('lists.show', $list)->with('success', 'List updated.');
    }

    /** Removes one contact from this list. The contact itself is untouched. */
    public function detach(SubscriberList $list, Subscriber $subscriber): RedirectResponse
    {
        $this->lists->detach([$subscriber->id], [$list->id], $list->account_id);

        return back()->with('success', "{$subscriber->email} removed from {$list->name}.");
    }

    public function destroy(SubscriberList $list): RedirectResponse
    {
        $name = $list->name;

        // The list is soft-deleted and its membership rows go with it, but the
        // contacts themselves stay in the account.
        $list->subscribers()->detach();
        $list->delete();

        ActivityLogger::log('list.deleted', "Deleted list {$name}");

        return to_route('lists.index')
            ->with('success', "List \"{$name}\" deleted. Its contacts were kept.");
    }
}
