<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\TagRequest;
use App\Models\Tag;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TagController extends Controller
{
    public function index(Request $request): View
    {
        return view('tags.index', [
            'tags' => Tag::query()
                ->when($request->filter('q'),
                    fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
                ->orderBy('name')
                ->paginate(30)
                ->withQueryString(),
            'filters' => $request->filters(['q']),
        ]);
    }

    public function store(TagRequest $request): RedirectResponse
    {
        $tag = Tag::create($request->validated());

        ActivityLogger::log('tag.created', "Created tag {$tag->name}", [], $tag);

        return to_route('tags.index')->with('success', "Tag \"{$tag->name}\" created.");
    }

    public function show(Request $request, Tag $tag): View
    {
        $subscribers = $tag->subscribers()
            ->when($request->filter('q'), function ($query, string $term) {
                $like = '%'.$term.'%';
                $query->where(fn ($q) => $q->where('subscribers.email', 'like', $like)
                    ->orWhere('subscribers.name', 'like', $like));
            })
            ->orderByDesc('subscribers.id')
            ->paginate(25)
            ->withQueryString();

        return view('tags.show', [
            'tag' => $tag,
            'subscribers' => $subscribers,
            'filters' => $request->filters(['q']),
        ]);
    }

    public function update(TagRequest $request, Tag $tag): RedirectResponse
    {
        $tag->update($request->validated());

        ActivityLogger::log('tag.updated', "Updated tag {$tag->name}", [], $tag);

        return to_route('tags.index')->with('success', 'Tag updated.');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $name = $tag->name;

        // Detach first so no orphan pivot rows are left; contacts are untouched.
        $tag->subscribers()->detach();
        $tag->delete();

        ActivityLogger::log('tag.deleted', "Deleted tag {$name}");

        return to_route('tags.index')->with('success', "Tag \"{$name}\" deleted.");
    }
}
