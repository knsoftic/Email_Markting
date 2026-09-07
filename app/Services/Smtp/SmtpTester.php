<?php

namespace App\Services\Smtp;

use App\Models\SmtpAccount;
use Throwable;

/**
 * Performs a real handshake against the provider.
 *
 * start() connects, sends EHLO, negotiates STARTTLS where configured and
 * authenticates — everything a send does except handing over a message. That
 * is the right depth for a test: it proves the credentials work without
 * putting mail in anyone's inbox.
 */
class SmtpTester
{
    public function __construct(
        protected MailerFactory $factory,
        protected SendFailureClassifier $classifier,
    ) {}

    /**
     * @param  bool  $persist  Whether the result is written back to the row.
     *
     * A tenant testing a SHARED platform account must pass false: the health
     * fields (test_passed, consecutive_failures, cooldown_until) are global
     * state, and letting one customer clear a cooldown would lift it for every
     * other customer using the same credentials.
     *
     * @return array{ok: bool, summary: string, detail: string|null, code: int|null, ms: int, tested_at: string}
     */
    public function test(SmtpAccount $account, bool $persist = true): array
    {
        $started = microtime(true);

        try {
            $transport = $this->factory->transport($account, MailerFactory::TEST_TIMEOUT);

            $transport->start();
            $transport->stop();

            $ms = (int) round((microtime(true) - $started) * 1000);

            if ($persist) {
                $account->forceFill([
                    'last_tested_at' => now(),
                    'test_passed' => true,
                    'last_success_at' => now(),
                    'last_error' => null,
                    // A successful handshake clears a cooldown that was caused
                    // by a credential the operator has now fixed.
                    'consecutive_failures' => 0,
                    'cooldown_until' => null,
                ])->save();
            }

            return [
                'ok' => true,
                'summary' => 'Connected and authenticated successfully in '.$ms.' ms.',
                'detail' => $this->describeConnection($account),
                'code' => null,
                'ms' => $ms,
                'tested_at' => now()->toDateTimeString(),
            ];
        } catch (Throwable $e) {
            $failure = $this->classifier->classify($e, $account);
            $ms = (int) round((microtime(true) - $started) * 1000);

            if ($persist) {
                $account->forceFill([
                    'last_tested_at' => now(),
                    'test_passed' => false,
                    'last_error' => mb_substr($failure->message, 0, 1000),
                    'last_error_at' => now(),
                ])->save();
            }

            return [
                'ok' => false,
                'summary' => $failure->summary,
                // The raw dialogue helps a technical user; it is already
                // scrubbed of the credential by the classifier.
                'detail' => mb_substr($failure->message, 0, 600),
                'code' => $failure->code,
                'ms' => $ms,
                'tested_at' => now()->toDateTimeString(),
            ];
        }
    }

    protected function describeConnection(SmtpAccount $account): string
    {
        $encryption = match ($account->encryption) {
            'ssl' => 'implicit SSL',
            'tls' => 'STARTTLS',
            default => 'no encryption',
        };

        return sprintf(
            '%s:%d using %s%s.',
            $account->host,
            $account->port,
            $encryption,
            $account->verify_peer ? '' : ' (certificate verification off)'
        );
    }
}
