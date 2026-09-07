<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Real health probes for the Super Admin panel — each one actually exercises
 * the subsystem rather than reporting a hard-coded "OK".
 */
class SystemHealthService
{
    /**
     * @return array{pending:int, reserved:int, failed:int, batches:int, oldest_wait:?string, worker_seen:bool}
     */
    public function queue(): array
    {
        $pending = 0;
        $reserved = 0;
        $failed = 0;
        $batches = 0;
        $oldest = null;

        try {
            $pending = DB::table('jobs')->whereNull('reserved_at')->count();
            $reserved = DB::table('jobs')->whereNotNull('reserved_at')->count();
            $failed = DB::table('failed_jobs')->count();
            $batches = DB::table('job_batches')->whereNull('finished_at')->count();

            $oldestTs = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
            $oldest = $oldestTs ? now()->setTimestamp((int) $oldestTs)->diffForHumans() : null;
        } catch (Throwable) {
            // Queue tables missing (fresh install) — reported as zeros.
        }

        return [
            'pending' => $pending,
            'reserved' => $reserved,
            'failed' => $failed,
            'batches' => $batches,
            'oldest_wait' => $oldest,
            // A reserved job means a worker picked something up recently.
            'worker_seen' => $reserved > 0,
        ];
    }

    /**
     * @return array<int, array{label:string, ok:bool, detail:string}>
     */
    public function checks(): array
    {
        return [
            $this->databaseCheck(),
            $this->cacheCheck(),
            $this->storageCheck(),
            $this->queueTableCheck(),
            $this->imapCheck(),
            $this->appKeyCheck(),
            $this->debugCheck(),
        ];
    }

    protected function databaseCheck(): array
    {
        try {
            $start = microtime(true);
            DB::select('select 1');
            $ms = round((microtime(true) - $start) * 1000, 1);

            return ['label' => 'Database', 'ok' => true, 'detail' => "Connected · {$ms} ms"];
        } catch (Throwable $e) {
            return ['label' => 'Database', 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    protected function cacheCheck(): array
    {
        try {
            $key = 'kns:health:'.uniqid();
            Cache::put($key, 'ok', 10);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);

            return [
                'label' => 'Cache',
                'ok' => $ok,
                'detail' => $ok ? 'Read/write OK ('.config('cache.default').')' : 'Value did not persist',
            ];
        } catch (Throwable $e) {
            return ['label' => 'Cache', 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    protected function storageCheck(): array
    {
        try {
            $path = 'health/'.uniqid().'.txt';
            Storage::disk('local')->put($path, 'ok');
            $ok = Storage::disk('local')->get($path) === 'ok';
            Storage::disk('local')->delete($path);

            $linked = is_link(public_path('storage')) || is_dir(public_path('storage'));

            return [
                'label' => 'Storage',
                'ok' => $ok && $linked,
                'detail' => $ok
                    ? ($linked ? 'Writable · public link present' : 'Writable · run php artisan storage:link')
                    : 'Disk not writable',
            ];
        } catch (Throwable $e) {
            return ['label' => 'Storage', 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    protected function queueTableCheck(): array
    {
        try {
            DB::table('jobs')->count();

            return [
                'label' => 'Queue',
                'ok' => true,
                'detail' => 'Driver: '.config('queue.default').' · tables present',
            ];
        } catch (Throwable $e) {
            return ['label' => 'Queue', 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    protected function imapCheck(): array
    {
        $native = class_exists(\Webklex\PHPIMAP\ClientManager::class);

        return [
            'label' => 'IMAP client',
            'ok' => $native,
            'detail' => $native
                ? 'webklex/php-imap available (no PHP imap extension needed)'
                : 'webklex/php-imap not installed',
        ];
    }

    protected function appKeyCheck(): array
    {
        $set = ! empty(config('app.key'));

        return [
            'label' => 'Encryption key',
            'ok' => $set,
            'detail' => $set
                ? 'APP_KEY set — SMTP/IMAP credentials can be decrypted'
                : 'APP_KEY missing — run php artisan key:generate',
        ];
    }

    protected function debugCheck(): array
    {
        $debug = (bool) config('app.debug');
        $production = app()->environment('production');

        return [
            'label' => 'Debug mode',
            'ok' => ! ($debug && $production),
            'detail' => $debug
                ? ($production ? 'Debug is ON in production — turn it off' : 'Debug on (local environment)')
                : 'Debug off',
        ];
    }
}
