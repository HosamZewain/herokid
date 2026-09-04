<?php

namespace App\Providers;

use App\Contracts\MobileSocialIdentityVerifier;
use App\Models\Setting;
use App\Services\Mobile\ProviderTokenVerifier;
use App\Support\AdminPermissionRegistry;
use App\Support\Seo;
use App\View\Composers\BostaOrderViewComposer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MobileSocialIdentityVerifier::class, ProviderTokenVerifier::class);
    }

    public function boot(): void
    {
        View::composer(['admin.orders.show', 'admin.orders.group-show'], BostaOrderViewComposer::class);

        Gate::before(function ($user, string $ability): ?bool {
            if (AdminPermissionRegistry::has($ability)) {
                return $user->hasPermission($ability);
            }

            return null;
        });

        // Force HTTPS in production so asset() / Storage::url() always return https:// URLs.
        // Without this, APP_URL=http:// causes mixed-content errors and images won't load.
        if ($this->app->environment('production')) {
            if (Seo::canonicalBase() !== Seo::DEFAULT_CANONICAL_URL) {
                throw new \RuntimeException('Production APP_URL must be https://hero-kid.com.');
            }

            URL::forceRootUrl(Seo::canonicalBase());
            URL::forceScheme('https');
        }

        // Share $settings (key => value map) with ALL views.
        // Cached until a Setting model write clears the cache.
        View::composer('*', function ($view) {
            $settings = Cache::rememberForever('site_settings', function () {
                try {
                    return Setting::all()->pluck('value', 'key')->toArray();
                } catch (\Exception $e) {
                    return [];
                }
            });
            $view->with('settings', $settings);
        });
    }
}
