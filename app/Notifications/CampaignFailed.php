<?php

namespace App\Notifications;

/**
 * A campaign send gave up after the queue job's last retry.
 *
 * Sent from SendCampaignChunk::failed(), and only when that job's guarded
 * UPDATE actually moved the campaign into `failed` — a late failure arriving
 * after the campaign already completed changes nothing, so it says nothing.
 *
 * ── Why the exception message is trimmed, not printed whole ─────────────────
 * A queue failure message can be a full SMTP dialogue or a stack of nested
 * exception text. The campaign screen already stores and shows the same string
 * in `last_error`; the bell's job is to say the send stopped and where to look.
 */
class CampaignFailed extends AccountNotification
{
    public function __construct(
        protected int $campaignId,
        protected string $campaignName,
        protected string $reason,
        protected int $sent = 0,
    ) {}

    public function permission(): ?string
    {
        return 'campaigns.view';
    }

    public function payload(): array
    {
        $name = trim($this->campaignName) === '' ? 'An untitled campaign' : trim($this->campaignName);
        $reason = trim(preg_replace('/\s+/', ' ', $this->reason) ?? '');

        $body = $this->sent > 0
            ? number_format($this->sent).' '.($this->sent === 1 ? 'message' : 'messages')
                .' went out before it stopped. Nothing more will be sent until it is started again.'
            : 'Nothing was sent. It will not retry on its own.';

        if ($reason !== '') {
            $body .= ' Reported: '.mb_substr($reason, 0, 180).(mb_strlen($reason) > 180 ? '…' : '');
        }

        return [
            'title' => $name.' stopped with an error',
            'body' => $body,
            'level' => 'danger',
            'icon' => 'campaign',
            'kind' => 'campaign',
            'target_id' => $this->campaignId,
            'route' => 'campaigns.show',
            'route_param' => 'campaign',
            'gone_note' => 'This campaign has since been deleted. Its sending history is still in the email logs.',
        ];
    }
}
