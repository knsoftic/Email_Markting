<?php

namespace App\Services\Smtp;

use App\Models\SmtpAccount;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Turns a thrown send error into a decision.
 *
 * The classification is driven by two real signals that Symfony actually
 * exposes, not by guesswork:
 *
 *  1. The SMTP reply code, which appears at the start of the response text in
 *     the exception message.
 *  2. The command that was in flight, recoverable from the exception's debug
 *     dialogue — Symfony records every line, prefixing what it sent with ">".
 *     A 550 answering "RCPT TO:" is a bad mailbox; the same 550 answering
 *     "MAIL FROM:" or DATA is the sender or the message being refused, which
 *     is an account-level problem.
 *
 * Nothing here ever stores the credential: the message is scrubbed against the
 * account's own secrets before it is returned.
 */
class SendFailureClassifier
{
    /** Reply codes that mean the credential or the session is the problem. */
    protected const AUTH_CODES = [530, 534, 535, 538];

    public function classify(Throwable $e, SmtpAccount $account): SendFailure
    {
        $raw = $e->getMessage();
        $debug = $this->debugFrom($e);
        $code = $this->replyCode($raw);
        $stage = $this->lastCommand($debug);

        $message = $this->scrub($raw.($debug ? "\n".$debug : ''), $account);

        // --- authentication -------------------------------------------------
        if (($code !== null && in_array($code, self::AUTH_CODES, true))
            || $this->looksLikeAuthFailure($raw)) {
            return new SendFailure(
                SendFailure::ACCOUNT, $code, $message,
                $this->authSummary($account),
                retryable: false, suppressRecipient: false, releaseReservation: true,
            );
        }

        // --- encryption ------------------------------------------------------
        // Checked BEFORE the connection case on purpose: Symfony words a
        // STARTTLS failure as "Unable to connect with STARTTLS", which would
        // otherwise be reported as a host/port problem and send the operator
        // looking in the wrong place.
        if ($this->isTlsFailure($raw)) {
            return new SendFailure(
                SendFailure::ACCOUNT, $code, $message,
                'The TLS handshake failed. Port 465 needs SSL, port 587 needs TLS/STARTTLS — check that the encryption setting matches the port.',
                retryable: false, suppressRecipient: false, releaseReservation: true,
            );
        }

        // --- nothing ever reached the server --------------------------------
        if ($this->isConnectionLevel($e, $raw)) {
            return new SendFailure(
                SendFailure::ACCOUNT, $code, $message,
                'Could not reach '.$account->host.' on port '.$account->port
                    .'. Check the host and port, and that outbound SMTP is not blocked.',
                retryable: true, suppressRecipient: false, releaseReservation: true,
            );
        }

        // --- a reply the server gave us -------------------------------------
        if ($code !== null) {
            $atRecipient = $stage === 'RCPT';

            // 4xx is always temporary, whoever it is about.
            if ($code >= 400 && $code < 500) {
                return $atRecipient
                    ? new SendFailure(
                        SendFailure::RECIPIENT_SOFT, $code, $message,
                        'The receiving server deferred this address (soft bounce). It will be retried.',
                        retryable: true, suppressRecipient: false, releaseReservation: true,
                    )
                    : new SendFailure(
                        SendFailure::ACCOUNT_TRANSIENT, $code, $message,
                        $code === 421
                            ? 'The provider is rate-limiting or closing connections. Sending will pause on this account and resume later.'
                            : 'The provider temporarily refused the message. It will be retried.',
                        retryable: true, suppressRecipient: false, releaseReservation: true,
                    );
            }

            if ($code >= 500) {
                if ($atRecipient) {
                    return new SendFailure(
                        SendFailure::RECIPIENT_HARD, $code, $message,
                        'The address does not exist or is refusing mail permanently (hard bounce).',
                        retryable: false, suppressRecipient: true, releaseReservation: true,
                    );
                }

                // A permanent 5xx that is NOT about the recipient is about us:
                // an unverified sender, a blocked domain, a message rejected as
                // spam. Retrying the same message will not help.
                return new SendFailure(
                    SendFailure::MESSAGE, $code, $message,
                    $stage === 'MAIL'
                        ? 'The provider refused the sender address. It usually has to be verified with the provider first.'
                        : 'The provider rejected the message itself. Retrying will not help until the content or sender is changed.',
                    retryable: false, suppressRecipient: false, releaseReservation: true,
                );
            }
        }

        // --- anything else --------------------------------------------------
        return new SendFailure(
            SendFailure::ACCOUNT_TRANSIENT, $code, $message,
            'The send failed for an unrecognised reason. It will be retried.',
            retryable: true, suppressRecipient: false, releaseReservation: true,
        );
    }

    // ---------------------------------------------------------------- signals

    /** The SMTP reply code at the start of the server's response, if present. */
    public function replyCode(string $message): ?int
    {
        // Symfony formats these as: Expected response code "250" but got code
        // "550", with message "550 5.1.1 User unknown".
        if (preg_match('/but got code "(\d{3})"/', $message, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/\b([45]\d{2})\b[ -]/', $message, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Which command was in flight — 'RCPT', 'MAIL', 'DATA', 'AUTH' or null.
     *
     * Symfony's debug dialogue prefixes what it sent with '>'.
     */
    public function lastCommand(?string $debug): ?string
    {
        if (! $debug) {
            return null;
        }

        $sent = [];

        foreach (preg_split('/\r?\n/', $debug) ?: [] as $line) {
            $line = ltrim($line);

            if (str_starts_with($line, '>')) {
                $sent[] = strtoupper(trim(substr($line, 1)));
            }
        }

        for ($i = count($sent) - 1; $i >= 0; $i--) {
            foreach (['RCPT TO' => 'RCPT', 'MAIL FROM' => 'MAIL', 'DATA' => 'DATA', 'AUTH' => 'AUTH'] as $needle => $stage) {
                if (str_starts_with($sent[$i], $needle)) {
                    return $stage;
                }
            }
        }

        return null;
    }

    protected function debugFrom(Throwable $e): ?string
    {
        if ($e instanceof TransportExceptionInterface) {
            $debug = $e->getDebug();

            return $debug !== '' ? $debug : null;
        }

        return null;
    }

    protected function looksLikeAuthFailure(string $message): bool
    {
        $needles = [
            'authentication failed',
            'authentication unsuccessful',
            'username and password not accepted',
            'invalid login',
            'invalid credentials',
            'bad credentials',
            'application-specific password required',
        ];

        $lower = mb_strtolower($message);

        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function isConnectionLevel(Throwable $e, string $message): bool
    {
        $lower = mb_strtolower($message);

        foreach ([
            'connection could not be established',
            'connection refused',
            'connection timed out',
            'network is unreachable',
            'name or service not known',
            'temporary failure in name resolution',
            'no such host',
            'getaddrinfo',
            'unable to connect',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function isTlsFailure(string $message): bool
    {
        $lower = mb_strtolower($message);

        foreach ([
            'starttls',
            'ssl operation failed',
            'certificate verify failed',
            'tls required',
            'unable to enable crypto',
            'wrong version number',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Provider-aware advice for the most common credential mistakes. */
    protected function authSummary(SmtpAccount $account): string
    {
        return match ($account->provider) {
            'gmail' => 'Gmail rejected the credentials. Gmail needs a 16-character App Password with 2-Step Verification enabled — a normal account password will always be refused.',
            'sendgrid' => 'SendGrid rejected the credentials. The username must be the literal word "apikey" and the password your API key.',
            'ses' => 'Amazon SES rejected the credentials. SES SMTP credentials are generated in the SES console and are not your AWS access keys.',
            'brevo' => 'Brevo rejected the credentials. Use an SMTP key from SMTP & API → SMTP, not your account password.',
            'microsoft' => 'Microsoft rejected the credentials. SMTP AUTH is disabled by default on Microsoft 365 and has to be enabled for this mailbox.',
            'zoho' => 'Zoho rejected the credentials. Check you are using the host for your region (.com, .eu, .in, .com.au) and an application-specific password.',
            'mailgun' => 'Mailgun rejected the credentials. Use the SMTP credentials from the sending domain, not your Mailgun account login.',
            default => 'The server rejected the username or password.',
        };
    }

    /**
     * Removes anything that could be a credential before the text is stored or
     * shown. Servers echo the AUTH line back often enough that this matters.
     */
    public function scrub(string $message, SmtpAccount $account): string
    {
        $secrets = array_filter([
            $account->password,
            $account->username,
            $account->password ? base64_encode((string) $account->password) : null,
            $account->username ? base64_encode((string) $account->username) : null,
        ]);

        $clean = $secrets ? str_replace($secrets, '[redacted]', $message) : $message;

        return (string) preg_replace('/\b(AUTH\s+(?:PLAIN|LOGIN|XOAUTH2)\s+)\S+/i', '$1[redacted]', $clean);
    }
}
