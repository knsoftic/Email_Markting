<?php

namespace App\Services\Smtp;

use App\Models\Account;
use App\Models\SmtpAccount;
use App\Notifications\AccountNotifier;
use App\Notifications\SmtpAccountCooledDown;
use Illuminate\Support\Collection;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends one message through whichever SMTP account has capacity, and keeps the
 * account's health and quota honest afterwards.
 *
 * This is the seam Phase 5's campaign job sits on: it hands over a built
 * message and gets back an outcome it can record against the recipient.
 *
 * Transports are cached per SMTP account for the lifetime of this instance, so
 * a 500-message chunk performs one handshake per account rather than 500.
 * Create a fresh instance (or call reset()) per batch.
 */
class SmtpSender
{
    /** @var array<int, TransportInterface> */
    protected array $transports = [];

    public function __construct(
        protected SmtpSelector $selector,
        protected MailerFactory $factory,
        protected SendFailureClassifier $classifier,
    ) {}

    /**
     * Attempts the send, moving to the next eligible account when one fails at
     * the account level.
     *
     * A recipient-level failure is NOT retried elsewhere: a mailbox that does
     * not exist will not start existing on another provider, and retrying it
     * only damages the sender's reputation.
     */
    public function send(Account $account, Email $message, ?Collection $candidates = null): SendOutcome
    {
        /*
         * A suspended account sends nothing. Checked here rather than in each
         * of the four callers, because this is the one place all of them pass
         * through and the only one that cannot be forgotten by the fifth.
         *
         * Suspension used to stop only the web session: EnsureAccountIsActive
         * logged the users out, and the queue carried on. A campaign already in
         * flight kept sending, the scheduler kept starting new ones, and
         * automations kept mailing — so suspending an account for non-payment
         * or for abuse did not stop the thing an operator suspends it to stop.
         *
         * Reported as no-capacity, not as a failure. That way the work pauses
         * and resumes if the account is reactivated, instead of burning every
         * recipient as failed and losing the campaign.
         */
        if (! $account->isActive()) {
            return SendOutcome::noCapacity(
                'This account is suspended, so nothing is being sent for it. Sending resumes if it is reactivated.'
            );
        }

        $candidates ??= $this->selector->candidatesFor($account);

        if ($candidates->isEmpty()) {
            return SendOutcome::noRoute(
                'No SMTP account is available. Add one, or ask KN Softic to assign a shared account to this plan.'
            );
        }

        $lastFailure = null;
        $attempted = [];

        foreach ($candidates as $candidate) {
            // Reserving is what enforces the limit; a candidate with no room
            // left simply loses the race and we move on.
            if (! $this->selector->reserveOn($candidate)) {
                continue;
            }

            $attempted[] = $candidate->id;

            try {
                $this->transportFor($candidate)->send($message);

                $this->selector->recordSuccess($candidate, $account->id);

                return SendOutcome::sent($candidate, $message->getHeaders()->get('Message-ID')?->getBodyAsString());
            } catch (Throwable $e) {
                $failure = $this->classifier->classify($e, $candidate);
                $lastFailure = $failure;

                if ($failure->releaseReservation) {
                    $this->selector->release($candidate);
                }

                if ($failure->isRecipientLevel()) {
                    // The account is fine. Stop here and report the bounce.
                    $this->selector->bufferUsage($candidate->id, $account->id, failed: 1);

                    return SendOutcome::failed($candidate, $failure);
                }

                if ($failure->kind === SendFailure::MESSAGE) {
                    // The provider refused this message or this sender.
                    // Another account would very likely refuse it too, and it
                    // is not the account's fault, so no failure is recorded.
                    $this->selector->bufferUsage($candidate->id, $account->id, failed: 1);

                    return SendOutcome::failed($candidate, $failure);
                }

                // Account-level: mark it and let the loop try the next one.
                // recordFailure() reports true only on the failure that trips
                // the threshold, so the customer is told once per cooldown
                // rather than once per rejected message. The notification
                // reads the scrubbed error off the row rather than taking
                // $failure->message — see SmtpAccountCooledDown.
                if ($this->selector->recordFailure($candidate, $failure->message, $account->id)) {
                    AccountNotifier::send($account->id, new SmtpAccountCooledDown(
                        (int) $candidate->id,
                        max(1, (int) config('knsoftic.smtp_cooldown_minutes', 30)),
                    ));
                }

                // A dropped connection can leave the transport unusable.
                unset($this->transports[$candidate->id]);
            }
        }

        if ($lastFailure) {
            return SendOutcome::failed(null, $lastFailure, $attempted);
        }

        return SendOutcome::noCapacity(
            'Every SMTP account has reached its sending limit for now. Sending resumes when a limit window resets.'
        );
    }

    /** Writes the buffered per-day usage. Call once at the end of a batch. */
    public function flush(): void
    {
        $this->selector->flushUsage();
    }

    /** Drops cached transports, closing their connections. */
    public function reset(): void
    {
        foreach ($this->transports as $transport) {
            if (method_exists($transport, 'stop')) {
                try {
                    $transport->stop();
                } catch (Throwable) {
                    // A transport that is already gone needs no closing.
                }
            }
        }

        $this->transports = [];
    }

    protected function transportFor(SmtpAccount $account): TransportInterface
    {
        return $this->transports[$account->id] ??= $this->factory->for($account);
    }
}
