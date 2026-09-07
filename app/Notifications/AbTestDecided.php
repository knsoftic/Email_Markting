<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\CampaignVariant;

/**
 * A split test picked its winner.
 *
 * Sent from AbTestService::decide(), immediately after the guarded UPDATE that
 * claims the decision — the same guard that stops two schedulers both
 * releasing the holdback is what stops this being sent twice.
 *
 * ── The rate quoted is the one the winner was judged on ─────────────────────
 * A campaign set to decide on clicks that reported an open rate would be
 * describing a decision that did not happen. The metric is read off the
 * campaign, and the counters off the variant row — which decide() has just
 * refreshed from the recipient rows, so they cannot disagree with the results
 * table on the campaign screen.
 */
class AbTestDecided extends AccountNotification
{
    public function __construct(
        protected int $campaignId,
        protected string $campaignName,
        protected string $metric,
        protected int $winnerId,
        protected string $winnerLabel,
        protected int $released,
    ) {}

    public function permission(): ?string
    {
        return 'campaigns.view';
    }

    public function payload(): array
    {
        $name = trim($this->campaignName) === '' ? 'An untitled campaign' : trim($this->campaignName);
        $label = trim($this->winnerLabel) === '' ? '?' : trim($this->winnerLabel);
        $onClicks = $this->metric === 'clicks';

        $variant = CampaignVariant::withoutGlobalScopes()
            ->whereKey($this->winnerId)
            ->first(['id', 'sent_count', 'unique_opens', 'unique_clicks']);

        $sent = (int) ($variant->sent_count ?? 0);
        $hits = (int) ($onClicks ? ($variant->unique_clicks ?? 0) : ($variant->unique_opens ?? 0));

        // A rate over nothing is not 0% — it is unmeasurable, and pickWinner()
        // will not crown a variant that delivered nothing anyway. Saying so
        // beats printing a number the data cannot support.
        $body = $sent > 0
            ? sprintf(
                'Version %s won on %s: %s of %s delivered (%s%%).',
                $label,
                $onClicks ? 'clicks' : 'opens',
                number_format($hits),
                number_format($sent),
                rtrim(rtrim(number_format($hits / $sent * 100, 1), '0'), '.'),
            )
            : sprintf('Version %s was chosen, but its sample has no delivered messages to measure.', $label);

        // The holdback is the point of the test, so say what happened to it.
        // "Nobody left" is a real state — a list small enough that everyone was
        // in the sample — and is worth naming rather than dressing up as a
        // send that is about to happen.
        $body .= $this->released > 0
            ? ' '.number_format($this->released).' remaining '
                .($this->released === 1 ? 'recipient is' : 'recipients are').' now being sent that version.'
            : ' Everyone on the list was already in the sample, so there is nobody left to send it to.';

        return [
            'title' => $name.': version '.$label.' won the split test',
            'body' => $body,
            'level' => 'success',
            'icon' => 'split',
            'kind' => 'campaign',
            'target_id' => $this->campaignId,
            'route' => 'campaigns.show',
            'route_param' => 'campaign',
            'gone_note' => 'This campaign has since been deleted, so the split-test results are gone with it.',
        ];
    }

    /**
     * Convenience for the one caller, so the line inside decide() stays a
     * single statement and every read it needs happens in payload().
     */
    public static function for(Campaign $campaign, CampaignVariant $winner, int $released): self
    {
        return new self(
            (int) $campaign->id,
            (string) $campaign->name,
            (string) $campaign->ab_winner_metric,
            (int) $winner->id,
            (string) $winner->label,
            $released,
        );
    }
}
