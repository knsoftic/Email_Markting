<?php

namespace App\Providers;

use App\Services\SettingsService;
use App\Support\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request / per queued job.
        $this->app->singleton(TenantManager::class);
        $this->app->singleton(SettingsService::class);

        // The sync logic talks to an interface so it can be exercised without
        // a live mail server; this binds the real webklex implementation.
        $this->app->bind(
            \App\Services\Imap\ImapGateway::class,
            \App\Services\Imap\WebklexImapGateway::class
        );
    }

    public function boot(): void
    {
        // MariaDB 10.4 caps index keys at 767 bytes; utf8mb4 makes that 191
        // characters. Every indexed string column is declared at 191 or less,
        // and this keeps any future default-length column in line too.
        Schema::defaultStringLength(191);

        // Catch lazy-loaded relations during development instead of shipping
        // N+1 queries into a 100k-row campaign screen.
        Model::preventLazyLoading($this->app->isLocal());

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->registerBladeDirectives();
        $this->registerRequestMacros();
        $this->shareBranding();
    }

    /**
     * $request->filter('q') — a search or filter value, as a trimmed string or
     * null.
     *
     * Every list screen used $request->string('q')->trim()->value(), which is
     * fine until someone visits ?q[]=x. Str::of() then casts an array, PHP
     * raises "Array to string conversion", and Laravel promotes that warning
     * to a 500 — on a plain URL, with no crafted request and no permissions
     * beyond viewing the page. This treats anything that is not a scalar as
     * "no filter given", which is the only sensible reading of it.
     */
    protected function registerRequestMacros(): void
    {
        Request::macro('filter', function (string $key): ?string {
            /** @var Request $this */
            $value = $this->input($key);

            if (! is_scalar($value)) {
                return null;
            }

            $value = trim((string) $value);

            return $value === '' ? null : $value;
        });

        /**
         * The same, for a whole filter set — the replacement for
         * $request->only([...]) when the result is handed to a view. only()
         * returns the raw input, so an array value survives all the way to the
         * markup and blows up on the first interpolation.
         */
        Request::macro('filters', function (array $keys): array {
            /** @var Request $this */
            $out = [];

            foreach ($keys as $key) {
                $out[$key] = $this->filter($key);
            }

            return $out;
        });
    }

    /**
     * Every view gets $brand, so the logo, colours, company name and footer
     * come from the admin-editable settings rather than being hard-coded.
     * Wrapped defensively because views also render before the settings
     * table exists (installer, migrate:fresh).
     */
    protected function shareBranding(): void
    {
        View::composer('*', function ($view) {
            static $brand = null;

            if ($brand === null) {
                try {
                    $settings = app(SettingsService::class);
                    $brand = array_merge(
                        SettingsService::DEFAULTS['branding'],
                        $settings->group('branding')
                    );
                } catch (Throwable) {
                    $brand = SettingsService::DEFAULTS['branding'];
                }
            }

            $view->with('brand', $brand);
        });
    }

    /**
     * Permission-aware Blade directives used across the sidebar and screens,
     * so a control is never rendered for a user who cannot use it.
     */
    protected function registerBladeDirectives(): void
    {
        Blade::if('permission', function (string ...$permissions) {
            $user = auth()->user();

            if (! $user) {
                return false;
            }

            foreach ($permissions as $permission) {
                if ($user->hasPermission($permission)) {
                    return true;
                }
            }

            return false;
        });

        Blade::if('superadmin', fn () => (bool) auth()->user()?->isSuperAdmin());

        Blade::if('accountowner', fn () => (bool) auth()->user()?->isAccountOwner());
    }
}
