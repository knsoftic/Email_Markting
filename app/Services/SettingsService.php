<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\TenantManager;
use Illuminate\Support\Facades\Cache;

/**
 * Reads and writes the settings table.
 *
 * Platform settings (account_id = null) hold the KN Softic branding that
 * drives the login page, sidebar, footer and system emails. Everything is
 * cached per scope and the cache is dropped on write, so the branding
 * lookups on every page render cost nothing.
 */
class SettingsService
{
    protected const CACHE_TTL = 86400;

    /** Defaults used before an admin has saved anything. */
    public const DEFAULTS = [
        'branding' => [
            'company_name' => 'KN Softic',
            'tagline' => 'Email Marketing & Inbox Platform',
            'logo_path' => '',
            'favicon_path' => '',
            'website' => 'https://knsoftic.com',
            'support_email' => 'support@knsoftic.com',
            'phone' => '',
            'address' => '',
            'primary_color' => '#1d4ed8',
            'accent_color' => '#0ea5e9',
            'footer_text' => '© KN Softic. All rights reserved.',
            'email_footer' => 'Sent with KN Softic',
        ],
        'system' => [
            'allow_registration' => '1',
            'require_email_verification' => '1',
            'default_plan_slug' => 'starter',
            'trial_days' => '14',
        ],
        'payment' => [
            'currency' => 'USD',
            'currency_symbol' => '$',
            'bank_details' => '',
            'instructions' => '',
        ],
    ];

    public function get(string $group, string $key, mixed $default = null, ?int $accountId = null): mixed
    {
        $values = $this->group($group, $accountId);

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        return $default ?? (self::DEFAULTS[$group][$key] ?? null);
    }

    /** Convenience accessor for the platform branding group. */
    public function brand(string $key, mixed $default = null): mixed
    {
        return $this->get('branding', $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function group(string $group, ?int $accountId = null): array
    {
        $all = $this->all($accountId);

        return $all[$group] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(?int $accountId = null): array
    {
        return Cache::remember($this->cacheKey($accountId), self::CACHE_TTL, function () use ($accountId) {
            $rows = Setting::query()
                ->withoutGlobalScopes()
                ->where('account_id', $accountId)
                ->get();

            $values = [];

            foreach ($rows as $row) {
                $values[$row->group][$row->key] = $row->typedValue();
            }

            return $values;
        });
    }

    public function set(string $group, string $key, mixed $value, string $type = 'string', ?int $accountId = null): void
    {
        $this->write($accountId, function () use ($accountId, $group, $key, $value, $type) {
            Setting::query()->withoutGlobalScopes()->updateOrCreate(
                ['account_id' => $accountId, 'group' => $group, 'key' => $key],
                ['value' => is_array($value) ? json_encode($value) : $value, 'type' => $type],
            );
        });

        $this->flush($accountId);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(string $group, array $values, ?int $accountId = null): void
    {
        $this->write($accountId, function () use ($accountId, $group, $values) {
            foreach ($values as $key => $value) {
                Setting::query()->withoutGlobalScopes()->updateOrCreate(
                    ['account_id' => $accountId, 'group' => $group, 'key' => $key],
                    [
                        'value' => is_array($value) ? json_encode($value) : $value,
                        'type' => is_array($value) ? 'json' : (is_bool($value) ? 'boolean' : 'string'),
                    ],
                );
            }
        });

        $this->flush($accountId);
    }

    public function flush(?int $accountId = null): void
    {
        Cache::forget($this->cacheKey($accountId));
    }

    /**
     * Runs a settings write with the tenant pinned to the row's own account.
     *
     * Without this, BelongsToAccount's creating hook would stamp a platform
     * setting (account_id = null) with whatever tenant happened to be active,
     * quietly turning a global setting into one account's private override.
     */
    protected function write(?int $accountId, callable $callback): void
    {
        app(TenantManager::class)->runAs($accountId, $callback);
    }

    protected function cacheKey(?int $accountId): string
    {
        return $accountId === null ? 'settings.platform' : "settings.account.{$accountId}";
    }
}
