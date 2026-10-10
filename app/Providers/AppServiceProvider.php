<?php

namespace App\Providers;

use App\Contracts\MobileSocialIdentityVerifier;
use App\Models\Setting;
use App\Observers\PublicCatalogImageObserver;
use App\Services\Images\PublicCatalogImageSources;
use App\Services\Images\PublicImageVariants;
use App\Services\Mobile\ProviderTokenVerifier;
use App\Services\Payments\HistoricalPaymentSource;
use App\Services\Payments\PaymentReconciliationService;
use App\Support\AdminPermissionRegistry;
use App\Support\RequestSettings;
use App\Support\Seo;
use App\View\Composers\BostaOrderViewComposer;
use App\View\Composers\PublicImageViewComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MobileSocialIdentityVerifier::class, ProviderTokenVerifier::class);
        $this->app->scoped(PublicImageVariants::class);
        $this->app->scoped(HistoricalPaymentSource::class);
        $this->app->scoped(PaymentReconciliationService::class);
    }

    public function boot(): void
    {
        foreach (array_keys(PublicCatalogImageSources::FIELDS) as $model) {
            $model::observe(PublicCatalogImageObserver::class);
        }
        View::composer(['welcome', 'front.homepage', 'front.shop.*', 'front.stories.*', 'front.packages.*', 'front.pages.pricing'], PublicImageViewComposer::class);
        RateLimiter::for('photo-uploads', function (Request $request): Limit {
            $sessionToken = (string) $request->session()->get('photo_upload.token', '');
            $key = $sessionToken !== ''
                ? hash('sha256', $sessionToken)
                : hash('sha256', $request->session()->getId());

            return Limit::perMinute(30)->by('photo-upload:'.$key);
        });

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
            $settings = RequestSettings::all();
            $view->with('settings', $settings);
        });
    }
}
