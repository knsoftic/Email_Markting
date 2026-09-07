<?php

namespace App\Services\Imap;

use App\Models\Mailbox;
use Throwable;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;

/**
 * Turns a thrown IMAP error into something an operator can act on.
 *
 * The sibling of SendFailureClassifier, and it exists for the same reason:
 * "Connection failed" on a screen tells somebody nothing about whether to fix
 * the port, the password, or their firewall. Every branch here ends in an
 * instruction, and provider-specific advice where the provider is known —
 * Gmail and Outlook both refuse ordinary passwords now, and that is the single
 * most common reason a mailbox will not connect.
 *
 * Nothing here ever returns the credential: the message is scrubbed against
 * the mailbox's own secrets first.
 */
class ImapFailureClassifier
{
    public const AUTH = 'auth';

    public const CONNECTION = 'connection';

    public const TLS = 'tls';

    public const FOLDER = 'folder';

    public const SERVER = 'server';

    public const UNKNOWN = 'unknown';

    /**
     * @return array{kind: string, summary: string, message: string}
     */
    public function classify(Throwable $e, Mailbox $mailbox): array
    {
        $raw = $e->getMessage();
        $message = $this->scrub($raw, $mailbox);

        // Order matters. A TLS failure is reported by the socket layer as a
        // connection failure, so it is checked first — otherwise the operator
        // is sent to look at their firewall when the real problem is that port
        // 143 was configured with implicit SSL.
        if ($this->isTlsFailure($raw)) {
            return $this->result(self::TLS, $this->tlsAdvice($mailbox), $message);
        }

        if ($e instanceof AuthFailedException || $this->looksLikeAuthFailure($raw)) {
            return $this->result(self::AUTH, $this->authAdvice($mailbox), $message);
        }

        if ($e instanceof ConnectionFailedException || $this->looksLikeConnectionFailure($raw)) {
            return $this->result(self::CONNECTION, $this->connectionAdvice($mailbox), $message);
        }

        if ($e instanceof FolderFetchingException) {
            return $this->result(
                self::FOLDER,
                'Connected, but the folder list could not be read. The account may have no permission to list folders, '
                .'or the server may use a namespace prefix this mailbox is not configured for.',
                $message
            );
        }

        if ($e instanceof ImapServerErrorException) {
            return $this->result(
                self::SERVER,
                'The mail server rejected the request. This is usually temporary — the next sync will try again.',
                $message
            );
        }

        return $this->result(
            self::UNKNOWN,
            'The mailbox could not be reached. The server message is below; if it means nothing to you, your mail '
            .'provider will recognise it.',
            $message
        );
    }

    // ------------------------------------------------------------- detection

    protected function isTlsFailure(string $raw): bool
    {
        foreach ([
            'ssl operation failed', 'certificate verify failed', 'self signed certificate',
            'unable to get local issuer', 'wrong version number', 'sslv3', 'tls handshake',
            'peer certificate', 'unable to enable crypto', 'stream_socket_enable_crypto',
        ] as $needle) {
            if (stripos($raw, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeAuthFailure(string $raw): bool
    {
        foreach ([
            'authentication failed', 'invalid credentials', 'login failed', 'auth failed',
            'authenticationfailed', '[authenticationfailed]', 'invalid login', 'bad username',
            'application-specific password required', 'web login required', 'lookup failed',
        ] as $needle) {
            if (stripos($raw, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeConnectionFailure(string $raw): bool
    {
        foreach ([
            'connection refused', 'connection timed out', 'could not resolve', 'name or service not known',
            'no such host', 'network is unreachable', 'connection failed', 'failed to connect',
            'operation timed out', 'timed out',
        ] as $needle) {
            if (stripos($raw, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- advice

    /**
     * Provider-specific where we can be: "check your password" is useless
     * advice for a Gmail account, because the password is not the problem —
     * Google stopped accepting it.
     */
    protected function authAdvice(Mailbox $mailbox): string
    {
        $host = mb_strtolower((string) $mailbox->imap_host);

        return match (true) {
            str_contains($host, 'gmail') || str_contains($host, 'google') => 'Google rejected the sign-in. A normal '
                .'account password will not work: turn on 2-Step Verification and use a 16-character App Password, '
                .'and make sure IMAP is enabled in Gmail settings.',

            str_contains($host, 'outlook') || str_contains($host, 'office365') || str_contains($host, 'hotmail') =>
                'Microsoft rejected the sign-in. Personal accounts need an App Password with 2-step verification on; '
                .'work accounts often have IMAP disabled by the administrator entirely.',

            str_contains($host, 'yahoo') => 'Yahoo rejected the sign-in. Yahoo requires an App Password generated in '
                .'Account Security — the normal password is never accepted.',

            str_contains($host, 'zoho') => 'Zoho rejected the sign-in. If two-factor authentication is on, this needs '
                .'an application-specific password, and IMAP access must be enabled in Zoho Mail settings.',

            default => 'The username or password was rejected. Check both, and check whether the provider requires '
                .'an app-specific password or has IMAP switched off for this account.',
        };
    }

    protected function connectionAdvice(Mailbox $mailbox): string
    {
        return sprintf(
            'Could not reach %s on port %d. Check the host and port are right (993 for SSL, 143 for TLS/STARTTLS), '
            .'and that the server is reachable from this machine — an outbound firewall blocking IMAP looks exactly '
            .'like this.',
            $mailbox->imap_host,
            $mailbox->imap_port
        );
    }

    protected function tlsAdvice(Mailbox $mailbox): string
    {
        $port = (int) $mailbox->imap_port;
        $encryption = (string) $mailbox->imap_encryption;

        if ($port === 993 && $encryption !== 'ssl') {
            return 'The TLS handshake failed. Port 993 expects SSL, but this mailbox is set to '
                .$encryption.' — that mismatch is almost certainly the cause.';
        }

        if ($port === 143 && $encryption === 'ssl') {
            return 'The TLS handshake failed. Port 143 expects TLS/STARTTLS, not SSL — SSL is for port 993.';
        }

        return 'The TLS handshake failed. If this is an internal server with a self-signed certificate, turn off '
            .'certificate validation for this mailbox; otherwise the certificate or the encryption setting is wrong.';
    }

    // -------------------------------------------------------------- scrubbing

    /**
     * Removes anything secret before the text is stored or shown. A server
     * happily echoes the whole LOGIN command back in an error, credential
     * included.
     */
    public function scrub(string $message, Mailbox $mailbox): string
    {
        $secrets = array_filter([
            (string) $mailbox->imap_password,
            (string) $mailbox->imap_username,
        ], fn ($value) => $value !== '' && mb_strlen($value) > 2);

        foreach ($secrets as $secret) {
            $message = str_ireplace($secret, '[redacted]', $message);
        }

        // A LOGIN line the server echoed back, whatever it contains.
        $message = (string) preg_replace('/\b(LOGIN|AUTHENTICATE)\s+\S+\s+\S+/i', '$1 [redacted]', $message);

        return mb_substr(trim($message), 0, 1000);
    }

    /**
     * @return array{kind: string, summary: string, message: string}
     */
    protected function result(string $kind, string $summary, string $message): array
    {
        return ['kind' => $kind, 'summary' => $summary, 'message' => $message];
    }
}
