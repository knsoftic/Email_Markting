<?php

namespace App\Services\Imap;

use App\Models\Mailbox;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Connects to the mailbox for real and reads its folder list.
 *
 * That depth is deliberate. Connecting and authenticating proves the
 * credentials; listing folders proves the account can actually be used, which
 * is a different thing — a Microsoft work account frequently authenticates
 * and then refuses to list anything because the administrator disabled IMAP.
 * A test that stopped at LOGIN would call that mailbox healthy and leave the
 * operator to discover the truth from an empty inbox.
 */
class MailboxTester
{
    public function __construct(
        protected ImapClientFactory $factory,
        protected ImapFailureClassifier $classifier,
        protected FolderMapper $mapper,
    ) {}

    /**
     * @param  bool  $persist  whether the result is written back to the row
     * @return array{ok: bool, summary: string, detail: ?string, kind: ?string, folders: array<int, array<string, string>>, ms: int, tested_at: string}
     */
    public function test(Mailbox $mailbox, bool $persist = true): array
    {
        $started = microtime(true);
        $client = null;

        try {
            $client = $this->factory->for($mailbox, ImapClientFactory::TEST_TIMEOUT);
            $client->connect();

            $folders = $this->describeFolders($client->getFolders(false));

            $ms = $this->elapsed($started);

            if ($persist) {
                $mailbox->forceFill([
                    'status' => 'connected',
                    'last_tested_at' => now(),
                    'last_error' => null,
                    'last_error_at' => null,
                    // A working connection clears the failure streak that put
                    // this mailbox into an error state.
                    'consecutive_failures' => 0,
                ])->save();
            }

            return [
                'ok' => true,
                'summary' => 'Connected and signed in successfully in '.$ms.' ms. '
                    .count($folders).' '.\Illuminate\Support\Str::plural('folder', count($folders)).' found.',
                'detail' => $this->describeConnection($mailbox),
                'kind' => null,
                'folders' => $folders,
                'ms' => $ms,
                'tested_at' => now()->toDateTimeString(),
            ];
        } catch (Throwable $e) {
            $failure = $this->classifier->classify($e, $mailbox);
            $ms = $this->elapsed($started);

            if ($persist) {
                $mailbox->forceFill([
                    'status' => 'error',
                    'last_tested_at' => now(),
                    'last_error' => $failure['summary'].' — '.$failure['message'],
                    'last_error_at' => now(),
                ])->save();
            }

            return [
                'ok' => false,
                'summary' => $failure['summary'],
                'detail' => $failure['message'],
                'kind' => $failure['kind'],
                'folders' => [],
                'ms' => $ms,
                'tested_at' => now()->toDateTimeString(),
            ];
        } finally {
            // The socket is closed whichever way this went. A test that leaks
            // a connection eats one of the provider's per-account slots, and
            // most providers allow very few.
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // Already gone; nothing useful to do or say.
            }
        }
    }

    /**
     * The folder list as the UI wants it: path, name and what we think it is.
     *
     * @param  iterable<mixed>  $folders
     * @return array<int, array<string, string>>
     */
    protected function describeFolders(iterable $folders): array
    {
        return Collection::make($folders)
            ->map(fn ($folder) => [
                'path' => (string) $folder->path,
                'name' => (string) ($folder->name ?: $folder->path),
                'type' => $this->mapper->typeFor([
                    'path' => (string) $folder->path,
                    'name' => (string) $folder->name,
                    'attributes' => (array) ($folder->attributes ?? []),
                ]),
            ])
            ->sortBy('path')
            ->values()
            ->all();
    }

    protected function describeConnection(Mailbox $mailbox): string
    {
        return sprintf(
            '%s:%d over %s, certificate validation %s.',
            $mailbox->imap_host,
            $mailbox->imap_port,
            match ($mailbox->imap_encryption) {
                'ssl' => 'implicit SSL',
                'tls' => 'STARTTLS',
                default => 'no encryption',
            },
            $mailbox->imap_validate_cert ? 'on' : 'off'
        );
    }

    protected function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
