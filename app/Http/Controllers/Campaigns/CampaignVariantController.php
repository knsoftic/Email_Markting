<?php

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\CampaignVariantRequest;
use App\Models\Campaign;
use App\Models\CampaignVariant;
use App\Services\Campaigns\AbTestService;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The split-test screens (spec 10.4).
 *
 * The service does the work — assigning the sample, computing the rates and
 * picking the winner. This controller only writes the configuration down and
 * offers the one manual override that is genuinely useful: ending a test early
 * on a version the sender has decided to back.
 */
class CampaignVariantController extends Controller
{
    public function __construct(protected AbTestService $ab) {}

    /**
     * Saves the split-test settings and the whole set of versions in one go.
     *
     * Versions that were saved before and are not in this post are deleted —
     * that is how "remove" works — but CampaignVariantRequest refuses to let
     * one go once recipients have been assigned to it.
     */
    public function update(CampaignVariantRequest $request, Campaign $campaign): RedirectResponse
    {
        $data = $request->validated();
        $rows = is_array($data['variants'] ?? null) ? $data['variants'] : [];

        DB::transaction(function () use ($campaign, $data, $rows) {
            $kept = [];

            foreach ($rows as $row) {
                $values = [
                    'label' => (string) $row['label'],
                    'subject' => $this->nullIfBlank($row['subject'] ?? null),
                    'from_name' => $this->nullIfBlank($row['from_name'] ?? null),
                    'from_email' => $this->nullIfBlank($row['from_email'] ?? null),
                    'html' => $this->nullIfBlank($row['html'] ?? null),
                    'share_percent' => max(0, min(100, (int) ($row['share_percent'] ?? 0))),
                ];

                $id = (int) ($row['id'] ?? 0);

                // Ownership was checked in the request, so the where() here is
                // belt and braces rather than the only guard.
                $variant = $id > 0
                    ? CampaignVariant::query()->where('campaign_id', $campaign->id)->find($id)
                    : null;

                if ($variant) {
                    $variant->update($values);
                } else {
                    $variant = CampaignVariant::create($values + ['campaign_id' => $campaign->id]);
                }

                $kept[] = $variant->id;
            }

            CampaignVariant::query()
                ->where('campaign_id', $campaign->id)
                ->when($kept !== [], fn ($q) => $q->whereNotIn('id', $kept))
                ->delete();

            $campaign->forceFill([
                'is_ab_test' => (bool) ($data['is_ab_test'] ?? false),
                'ab_test_type' => ($data['ab_test_type'] ?? null) ?: null,
                'ab_sample_percent' => (int) $data['ab_sample_percent'],
                'ab_winner_metric' => (string) $data['ab_winner_metric'],
                'ab_decide_after_minutes' => (int) $data['ab_decide_after_minutes'],
            ])->save();
        });

        ActivityLogger::log('campaign.ab_updated', "Updated the split test on {$campaign->name}", [], $campaign);

        return back()->with('success', $campaign->fresh()->is_ab_test
            ? 'Split test saved. Nothing is sent until you review and send the campaign.'
            : 'Split test switched off. The versions are kept, but this campaign now sends one email to everybody.');
    }

    /**
     * Ends a running test on a version the sender chooses, instead of waiting
     * for the window to close.
     *
     * Refused unless a decision is genuinely still open, because the redirect
     * would otherwise land on a screen that already shows a different winner
     * and the click would look like it had done nothing.
     */
    public function decide(Request $request, Campaign $campaign): RedirectResponse
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'integer'],
        ]);

        abort_unless($campaign->is_ab_test, 422, 'This campaign is not a split test.');

        abort_if($campaign->ab_decided_at !== null, 422,
            'This split test has already been decided. A winner cannot be changed once the holdback has gone out.');

        abort_if($this->sampleStillSending($campaign), 422,
            'The sample has not finished going out yet. Deciding now would compare a finished version against half of another.');

        $variant = CampaignVariant::query()
            ->where('campaign_id', $campaign->id)
            ->findOrFail($validated['variant_id']);

        $held = $this->holdbackCount($campaign);

        $winner = $this->ab->decide($campaign, $variant);

        if ($winner === null) {
            return back()->with('warning',
                'Nothing was changed — the test was decided a moment ago by the scheduler. Reload to see the winner.');
        }

        ActivityLogger::log(
            'campaign.ab_decided',
            "Chose version {$winner->label} on {$campaign->name}",
            ['variant' => $winner->label, 'forced' => true],
            $campaign
        );

        return back()->with('success', $held > 0
            ? "Version {$winner->label} chosen. The ".number_format($held)
                .' held-back contact(s) are being sent that version now.'
            : "Version {$winner->label} recorded as the winner. There was no holdback left to send it to.");
    }

    protected function nullIfBlank(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /** Recipients still waiting for a winner. */
    protected function holdbackCount(Campaign $campaign): int
    {
        return DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereNull('campaign_variant_id')
            ->where('status', 'pending')
            ->count();
    }

    /**
     * Delegated so the screen, the scheduled sweep and the console command
     * cannot drift apart on what "the sample has finished" means. They had
     * three copies of this rule and the command was missing it entirely.
     */
    protected function sampleStillSending(Campaign $campaign): bool
    {
        return $this->ab->sampleStillSending($campaign);
    }
}
