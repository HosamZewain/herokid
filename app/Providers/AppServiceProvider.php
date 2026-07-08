<?php

namespace App\Providers;

use App\Models\Setting;
use App\Models\Product;
use App\Support\AdminPermissionRegistry;
use App\Support\Seo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
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
            try {
                $shopAvailable = ($settings['shop_enabled'] ?? '1') === '1'
                    && Product::query()->publiclyVisible()->exists();
            } catch (\Exception) {
                $shopAvailable = false;
            }

            $view->with('settings', $settings);
            $view->with('shopAvailable', $shopAvailable);
        });
    }
}
