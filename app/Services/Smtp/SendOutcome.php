<?php

namespace App\Services\Smtp;

use App\Models\SmtpAccount;

/**
 * What happened to one message. The campaign job turns this into a recipient
 * status, a log line and — for a hard bounce — a suppression entry.
 */
class SendOutcome
{
    /**
     * @param  array<int, int>  $attemptedAccountIds
     */
    protected function __construct(
        public readonly bool $sent,
        public readonly ?SmtpAccount $smtpAccount,
        public readonly ?SendFailure $failure,
        public readonly ?string $messageId,
        /** True when nothing was wrong, there was simply no quota left. */
        public readonly bool $deferred,
        public readonly ?string $reason,
        public readonly array $attemptedAccountIds = [],
    ) {}

    public static function sent(SmtpAccount $account, ?string $messageId = null): self
    {
        return new self(true, $account, null, $messageId, false, null);
    }

    /**
     * @param  array<int, int>  $attempted
     */
    public static function failed(?SmtpAccount $account, SendFailure $failure, array $attempted = []): self
    {
        return new self(false, $account, $failure, null, false, $failure->summary, $attempted);
    }

    /** Nothing has capacity right now — retry later, do not mark as failed. */
    public static function noCapacity(string $reason): self
    {
        return new self(false, null, null, null, true, $reason);
    }

    /** The account has no usable SMTP at all — a configuration problem. */
    public static function noRoute(string $reason): self
    {
        return new self(false, null, null, null, false, $reason);
    }

    public function shouldSuppressRecipient(): bool
    {
        return (bool) $this->failure?->suppressRecipient;
    }

    public function bounceType(): ?string
    {
        return $this->failure?->bounceType();
    }

    public function isRetryable(): bool
    {
        return $this->deferred || (bool) $this->failure?->retryable;
    }

    /** The status to store on the campaign recipient row. */
    public function recipientStatus(): string
    {
        return match (true) {
            $this->sent => 'sent',
            $this->deferred => 'pending',
            $this->bounceType() !== null => 'bounced',
            default => 'failed',
        };
    }
}
