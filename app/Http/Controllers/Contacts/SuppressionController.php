<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\SuppressionRequest;
use App\Models\Suppression;
use App\Services\Contacts\SuppressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SuppressionController extends Controller
{
    public function __construct(protected SuppressionService $service) {}

    public function index(Request $request): View
    {
        return view('suppressions.index', [
            'suppressions' => Suppression::query()
                ->search($request->filter('q'))
                ->when($request->filter('reason'), fn ($q, $r) => $q->where('reason', $r))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
            'filters' => $request->filters(['q', 'reason']),
            'reasons' => Suppression::REASONS,
            'counts' => Suppression::query()
                ->selectRaw('reason, COUNT(*) as aggregate')
                ->groupBy('reason')
                ->pluck('aggregate', 'reason'),
            'total' => Suppression::query()->count(),
        ]);
    }

    public function store(SuppressionRequest $request): RedirectResponse
    {
        ['valid' => $valid, 'invalid' => $invalid] = $request->emails();

        if (empty($valid)) {
            return back()->with('error', 'No valid email addresses were found in that input.');
        }

        $added = $this->service->suppressMany(
            $valid,
            $request->validated()['reason'],
            'manual',
            null,
            $request->validated()['notes'] ?? null,
        );

        $message = "Added {$added} new address(es) to the suppression list.";

        if (count($valid) > $added) {
            $message .= ' '.(count($valid) - $added).' were already suppressed.';
        }

        if ($invalid) {
            $message .= ' Skipped '.count($invalid).' invalid address(es).';
        }

        return back()->with('success', $message);
    }

    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:release'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $removed = $this->service->releaseMany($validated['ids']);

        return back()->with(
            $removed > 0 ? 'success' : 'warning',
            $removed > 0
                ? "Removed {$removed} address(es). Contacts are not re-activated automatically."
                : 'Nothing was removed.'
        );
    }

    public function destroy(Suppression $suppression): RedirectResponse
    {
        $email = $suppression->email;
        $this->service->release($email);

        return back()->with(
            'success',
            "{$email} removed from the suppression list. Their contact record was not re-activated."
        );
    }

    /**
     * Streams the list as CSV without buffering it, so an account with a very
     * large suppression list still exports.
     */
    public function export(Request $request): StreamedResponse
    {
        $filename = 'suppression-list-'.now()->format('Y-m-d').'.csv';

        $query = Suppression::query()
            ->when($request->filter('reason'), fn ($q, $r) => $q->where('reason', $r));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'reason', 'source', 'notes', 'suppressed_at']);

            $query->orderBy('id')->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->email,
                        $row->reason,
                        $row->source,
                        $row->notes,
                        $row->created_at?->toDateTimeString(),
                    ]);
                }
                flush();
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }
}
