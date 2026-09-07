<?php

namespace App\Services\Smtp;

use App\Models\SmtpAccount;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds a Symfony mailer transport from a database SmtpAccount row.
 *
 * ── Why the transport is constructed by hand ────────────────────────────────
 * Laravel's MailManager builds SMTP transports from a DSN string, which means
 * the password ends up inside a Dsn object that can surface in an exception
 * or a dump. Here the credential is passed only through setPassword(), so it
 * lives in one private property on the transport and never in a URL, a config
 * array, or an exception message.
 *
 * ── Encryption ─────────────────────────────────────────────────────────────
 * Symfony's third constructor argument means "implicit TLS", i.e. wrap the
 * socket in SSL before the greeting — that is port 465. STARTTLS is a separate
 * mechanism controlled by setAutoTls(). So:
 *
 *   ssl  -> implicit TLS from connect          (tls: true)
 *   tls  -> plain connect, then STARTTLS       (tls: false, autoTls on,
 *                                               requireTls so it fails loudly
 *                                               rather than sending in clear)
 *   none -> plain, and STARTTLS explicitly off (tls: false, autoTls off)
 */
class MailerFactory
{
    /** Seconds to wait on the socket. Long enough for a slow provider, short
     *  enough that a dead host does not hold a queue worker for minutes. */
    public const SEND_TIMEOUT = 30;

    /** A test runs from a web request, so it must give up sooner. */
    public const TEST_TIMEOUT = 12;

    public function transport(SmtpAccount $account, ?int $timeout = null): EsmtpTransport
    {
        $implicitTls = $account->encryption === 'ssl';

        $transport = new EsmtpTransport(
            (string) $account->host,
            (int) $account->port,
            $implicitTls,
        );

        // STARTTLS is on for 'tls', off for 'none'. For 'ssl' the socket is
        // already encrypted, so it does not apply.
        $transport->setAutoTls($account->encryption === 'tls');

        // Without this a server that silently fails to offer STARTTLS would be
        // talked to in plain text while the operator believes it is encrypted.
        if ($account->encryption === 'tls') {
            $transport->setRequireTls(true);
        }

        if (filled($account->username)) {
            $transport->setUsername((string) $account->username);
        }

        if (filled($account->password)) {
            $transport->setPassword((string) $account->password);
        }

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) ($timeout ?? self::SEND_TIMEOUT));

            if (! $account->verify_peer) {
                // Deliberately opt-in: some cPanel and self-hosted relays ship
                // a self-signed certificate. It is a real downgrade, which is
                // why the form warns about it rather than defaulting to it.
                $stream->setStreamOptions([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ]);
            }
        }

        return $transport;
    }

    /**
     * The narrow contract the sender needs.
     *
     * transport() deliberately returns the concrete EsmtpTransport because the
     * connection test calls start()/stop(), which are not on the interface.
     * Sending only needs send(), so it goes through here — which also makes the
     * factory substitutable in tests without faking a whole SMTP client.
     */
    public function for(SmtpAccount $account, ?int $timeout = null): TransportInterface
    {
        return $this->transport($account, $timeout);
    }

    /**
     * A Mailer wrapping the transport. One transport is reused for a whole
     * batch — Symfony keeps the connection open and pings it, so a 500-message
     * chunk costs one handshake rather than 500.
     */
    public function mailer(SmtpAccount $account, ?int $timeout = null): Mailer
    {
        return new Mailer($this->transport($account, $timeout));
    }
}
