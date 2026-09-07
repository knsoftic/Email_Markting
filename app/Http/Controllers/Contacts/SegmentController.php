<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\SegmentRequest;
use App\Models\Segment;
use App\Services\Contacts\SegmentCompiler;
use App\Services\Contacts\SegmentFieldRegistry;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SegmentController extends Controller
{
    public function __construct(
        protected SegmentCompiler $compiler,
        protected SegmentFieldRegistry $registry,
    ) {}

    public function index(Request $request): View
    {
        $segments = Segment::query()
            ->when($request->filter('q'),
                fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Descriptions are rendered from the stored rules, so the index never
        // runs every segment's query just to show what it does.
        $segments->getCollection()->transform(function (Segment $segment) {
            $segment->setAttribute(
                'rule_summary',
                $this->compiler->describe($segment->rules ?? [], $segment->match_type)
            );

            return $segment;
        });

        return view('segments.index', [
            'segments' => $segments,
            'filters' => $request->filters(['q']),
            'allowed' => PlanLimits::for($request->user()->account)->allows('allow_segments'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->assertAllowed($request);

        return view('segments.create', [
            'segment' => new Segment(['match_type' => 'all', 'rules' => []]),
            'fields' => $this->registry->forUi(),
        ]);
    }

    public function store(SegmentRequest $request): RedirectResponse
    {
        $this->assertAllowed($request);

        $segment = Segment::create($request->validated());

        $this->compiler->refreshCount($segment);

        ActivityLogger::log('segment.created', "Created segment {$segment->name}", [], $segment);

        return to_route('segments.show', $segment)
            ->with('success', "Segment \"{$segment->name}\" saved — ".number_format($segment->cached_count).' contact(s) match.');
    }

    public function show(Request $request, Segment $segment): View
    {
        $matches = $this->compiler->forSegment($segment)
            ->with('tags:id,name,color')
            ->latest('id')
            ->paginate(25);

        return view('segments.show', [
            'segment' => $segment,
            'matches' => $matches,
            'summary' => $this->compiler->describe($segment->rules ?? [], $segment->match_type),
            'mailableCount' => $this->compiler->mailableForSegment($segment)->count(),
        ]);
    }

    public function edit(Request $request, Segment $segment): View
    {
        $this->assertAllowed($request);

        return view('segments.edit', [
            'segment' => $segment,
            'fields' => $this->registry->forUi(),
        ]);
    }

    public function update(SegmentRequest $request, Segment $segment): RedirectResponse
    {
        $this->assertAllowed($request);

        $segment->update($request->validated());

        $this->compiler->refreshCount($segment);

        ActivityLogger::log('segment.updated', "Updated segment {$segment->name}", [], $segment);

        return to_route('segments.show', $segment)->with('success', 'Segment updated.');
    }

    /**
     * Live count and sample for the rule builder. Returns JSON only.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'match_type' => ['required', 'in:all,any'],
            'rules' => ['array'],
            'rules.*.field' => ['required', 'string', 'max:100'],
            'rules.*.operator' => ['required', 'string', 'max:40'],
            'rules.*.value' => ['nullable'],
        ]);

        $preview = $this->compiler->preview(
            $validated['rules'] ?? [],
            $validated['match_type'],
        );

        return response()->json([
            'count' => $preview['count'],
            'rules_applied' => $preview['rules_applied'],
            'rules_ignored' => $preview['rules_ignored'],
            'sample' => $preview['sample']->map(fn ($s) => [
                'id' => $s->id,
                'email' => $s->email,
                'name' => $s->name,
                'status' => $s->status,
                'country' => $s->country,
            ])->all(),
        ]);
    }

    public function recalculate(Segment $segment): RedirectResponse
    {
        $count = $this->compiler->refreshCount($segment);

        return back()->with('success', 'Recalculated — '.number_format($count).' contact(s) match.');
    }

    public function destroy(Segment $segment): RedirectResponse
    {
        $name = $segment->name;
        $segment->delete();

        ActivityLogger::log('segment.deleted', "Deleted segment {$name}");

        return to_route('segments.index')
            ->with('success', "Segment \"{$name}\" deleted. No contacts were affected.");
    }

    protected function assertAllowed(Request $request): void
    {
        PlanLimits::for($request->user()->account)
            ->ensureFeature('allow_segments', 'saved segments');
    }

}
