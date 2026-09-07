<?php

namespace App\Services\Imap;

use App\Models\Mailbox;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * Builds a configured IMAP client from a Mailbox row.
 *
 * The sibling of MailerFactory, for the same reasons: the credential is put
 * into exactly one place, the connection settings come from the row rather
 * than from config, and `for()` is a seam a test can replace with a fake so
 * the sync logic can be exercised without a live server.
 *
 * ── Why the native protocol ────────────────────────────────────────────────
 * This machine has no `imap` PHP extension and recompiling XAMPP is not a
 * deployment step anyone should need. webklex/php-imap speaks IMAP over a
 * plain socket, so the app works on a stock PHP install. Decision locked in
 * Phase 0.
 */
class ImapClientFactory
{
    /** A sync runs in a queue worker, so it can afford to wait. */
    public const SYNC_TIMEOUT = 60;

    /** A connection test runs inside a web request and must give up sooner. */
    public const TEST_TIMEOUT = 15;

    public function for(Mailbox $mailbox, ?int $timeout = null): Client
    {
        return (new ClientManager)->make($this->config($mailbox, $timeout));
    }

    /**
     * @return array<string, mixed>
     */
    public function config(Mailbox $mailbox, ?int $timeout = null): array
    {
        return [
            'host' => (string) $mailbox->imap_host,
            'port' => (int) $mailbox->imap_port,
            'protocol' => 'imap',

            // 'ssl'  = implicit TLS from connect (993)
            // 'tls'  = plain connect then STARTTLS (143)
            // 'none' = no encryption at all
            // webklex wants false rather than the string for the last one.
            'encryption' => $mailbox->imap_encryption === 'none' ? false : $mailbox->imap_encryption,

            // Off is a real choice for a self-signed internal server, but it is
            // the row's choice, not a default we make quietly.
            'validate_cert' => (bool) $mailbox->imap_validate_cert,

            'username' => (string) $mailbox->imap_username,
            'password' => (string) $mailbox->imap_password,
            'authentication' => null,
            'timeout' => $timeout ?? self::SYNC_TIMEOUT,
        ];
    }

    /**
     * The same settings with the credential removed — for logging, error
     * reporting and anything that might end up on a screen.
     *
     * @return array<string, mixed>
     */
    public function safeConfig(Mailbox $mailbox): array
    {
        $config = $this->config($mailbox);

        $config['password'] = '[redacted]';
        $config['username'] = $this->maskUsername($config['username']);

        return $config;
    }

    protected function maskUsername(string $username): string
    {
        if (! str_contains($username, '@')) {
            return mb_substr($username, 0, 2).str_repeat('*', max(0, mb_strlen($username) - 2));
        }

        [$local, $domain] = explode('@', $username, 2);

        return mb_substr($local, 0, 2).str_repeat('*', max(0, mb_strlen($local) - 2)).'@'.$domain;
    }
}
