<?php

namespace App\Providers;

use App\Contracts\MobileSocialIdentityVerifier;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\FaqItem;
use App\Models\OrderPaymentReview;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessMessageAttempt;
use App\Models\OrderProcessRun;
use App\Models\OrderProcessStep;
use App\Models\PricingPackage;
use App\Models\PricingPackageProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\Setting;
use App\Models\Story;
use App\Models\StoryCategory;
use App\Observers\AutomationActivityObserver;
use App\Observers\PublicCatalogImageObserver;
use App\Services\Catalog\RoboDeskFeedCache;
use App\Services\Images\PublicCatalogImageSources;
use App\Services\Images\PublicImageVariants;
use App\Services\Mobile\ProviderTokenVerifier;
use App\Services\Payments\HistoricalPaymentSource;
use App\Services\Payments\PaymentReconciliationService;
use App\Support\AdminPermissionRegistry;
use App\Support\RequestSettings;
use App\Support\Seo;
use App\View\Composers\BostaOrderViewComposer;
use App\View\Composers\OrderMessageHistoryComposer;
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
        foreach ([OrderProcessRun::class, OrderProcessStep::class,
            OrderProcessIdentity::class, OrderProcessMessageAttempt::class,
            RoboDeskIntegrationEvent::class, OrderPaymentReview::class] as $automationModel) {
            $automationModel::observe(AutomationActivityObserver::class);
        }
        foreach ([Story::class, Product::class, ProductVariant::class,
            PricingPackage::class, PricingPackageProduct::class, FaqItem::class,
            StoryCategory::class, ProductCategory::class, Setting::class,
            DeliveryCountry::class, DeliveryGovernorate::class] as $feedModel) {
            $feedModel::saved(fn () => RoboDeskFeedCache::invalidate());
            $feedModel::deleted(fn () => RoboDeskFeedCache::invalidate());
        }
        foreach (array_keys(PublicCatalogImageSources::FIELDS) as $model) {
            $model::observe(PublicCatalogImageObserver::class);
        }
        View::composer(['welcome', 'front.homepage', 'front.shop.*', 'front.stories.*', 'front.packages.*', 'front.pages.pricing'], PublicImageViewComposer::class);
        foreach (['mobile-game-config' => 120, 'mobile-game-events' => 30] as $limiter => $maximum) {
            RateLimiter::for($limiter, function (Request $request) use ($limiter, $maximum) {
                // Abuse control uses a rotating HMAC in the existing cache only.
                $key = hash_hmac('sha256', now()->utc()->toDateString().'|'.$request->ip(), (string) config('app.key'));

                return [Limit::perMinute($maximum)->by($limiter.':'.$key), Limit::perMinute(3000)->by($limiter.':global')];
            });
        }
        RateLimiter::for('photo-uploads', function (Request $request): Limit {
            $sessionToken = (string) $request->session()->get('photo_upload.token', '');
            $key = $sessionToken !== ''
                ? hash('sha256', $sessionToken)
                : hash('sha256', $request->session()->getId());

            return Limit::perMinute(30)->by('photo-upload:'.$key);
        });

        View::composer(['admin.orders.show', 'admin.orders.group-show'], BostaOrderViewComposer::class);
        View::composer(['admin.orders.show', 'admin.orders.group-show'], OrderMessageHistoryComposer::class);

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
