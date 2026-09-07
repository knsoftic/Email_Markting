<?php

namespace App\Notifications;

use App\Models\Scopes\AccountScope;
use App\Models\Campaign;

/**
 * A campaign has finished sending.
 *
 * Sent from CampaignRunner::tryFinalise(), and only when its conditional
 * UPDATE actually moved the campaign to `completed` — so it fires exactly once
 * even when several workers race to finalise the same campaign, and never on
 * the passes before the last recipient is done.
 *
 * ── It takes an id, not a model ─────────────────────────────────────────────
 * The row is read here, at the moment the payload is built, so the counters
 * quoted are the ones the campaign screen will show when the reader clicks
 * through. It is also read WITHOUT the tenant scope: this runs on a queue
 * worker, where the bound account is whatever the previous job left behind.
 */
class CampaignCompleted extends AccountNotification
{
    public function __construct(protected int $campaignId) {}

    public function permission(): ?string
    {
        return 'campaigns.view';
    }

    public function payload(): array
    {
        // The fallback below exists for exactly this case, and the plural
        // scope stopped it ever running: a soft-deleted campaign was found,
        // so the notification linked to a report that answers 404.
        $campaign = Campaign::withoutGlobalScope(AccountScope::class)->find($this->campaignId);

        if (! $campaign) {
            // Deleted between finishing and this line. The send still happened,
            // so the news is still true; only the detail is gone.
            return [
                'title' => 'A campaign has finished sending',
                'body' => 'It was deleted before its final figures could be recorded.',
                'level' => 'info',
                'icon' => 'campaign',
                'kind' => 'campaign',
                'target_id' => $this->campaignId,
                'route' => 'campaigns.show',
                'route_param' => 'campaign',
                'gone_note' => 'This campaign has since been deleted, so there is no report left to open.',
            ];
        }

        $sent = (int) $campaign->sent_count;
        $failed = (int) $campaign->failed_count;
        $bounced = (int) $campaign->bounced_count;

        $parts = [number_format($sent).' '.($sent === 1 ? 'message' : 'messages').' sent'];

        if ($failed > 0) {
            $parts[] = number_format($failed).' failed';
        }

        if ($bounced > 0) {
            $parts[] = number_format($bounced).' bounced';
        }

        $name = trim((string) $campaign->name);

        return [
            'title' => ($name === '' ? 'An untitled campaign' : $name).' has finished sending',

            // Open and click rates are deliberately absent. Nobody has had time
            // to open anything yet, and printing "0% opens" the second a send
            // ends reports a fact about the clock, not about the campaign.
            'body' => implode(', ', $parts).'. Opens and clicks build up over the next few days.',

            'level' => $failed > 0 || $bounced > 0 ? 'warning' : 'success',
            'icon' => 'campaign',
            'kind' => 'campaign',
            'target_id' => (int) $campaign->id,
            'route' => 'campaigns.show',
            'route_param' => 'campaign',
            'gone_note' => 'This campaign has since been deleted, so there is no report left to open.',
        ];
    }
}
