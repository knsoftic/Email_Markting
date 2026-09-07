<?php

namespace App\Services\Smtp;

/**
 * The result of classifying a failed send.
 *
 * The distinction that matters: an ACCOUNT failure means the SMTP account is
 * unhealthy and should be cooled down; a RECIPIENT failure means this one
 * address is bad and the account is fine. Getting that backwards either takes
 * a working provider out of service because of one typo'd address, or keeps
 * hammering a provider that has locked the credential.
 */
class SendFailure
{
    public const ACCOUNT = 'account';           // credentials, TLS, connection

    public const ACCOUNT_TRANSIENT = 'account_transient'; // rate limit, greylist

    public const RECIPIENT_HARD = 'recipient_hard';       // mailbox does not exist

    public const RECIPIENT_SOFT = 'recipient_soft';       // mailbox full, deferred

    public const MESSAGE = 'message';           // the message itself was rejected

    public function __construct(
        public readonly string $kind,
        public readonly ?int $code,
        /** Safe to store and show — credentials already stripped. */
        public readonly string $message,
        /** A human-facing explanation, not the raw server text. */
        public readonly string $summary,
        public readonly bool $retryable,
        /** True only for a genuine permanent recipient failure. */
        public readonly bool $suppressRecipient,
        /** True when the capacity reserved for this send was never used. */
        public readonly bool $releaseReservation,
    ) {}

    public function isAccountLevel(): bool
    {
        return in_array($this->kind, [self::ACCOUNT, self::ACCOUNT_TRANSIENT], true);
    }

    public function isRecipientLevel(): bool
    {
        return in_array($this->kind, [self::RECIPIENT_HARD, self::RECIPIENT_SOFT], true);
    }

    public function bounceType(): ?string
    {
        return match ($this->kind) {
            self::RECIPIENT_HARD => 'hard',
            self::RECIPIENT_SOFT => 'soft',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'code' => $this->code,
            'summary' => $this->summary,
            'message' => $this->message,
            'retryable' => $this->retryable,
            'suppress_recipient' => $this->suppressRecipient,
        ];
    }
}
