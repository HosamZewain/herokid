<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Share $settings (key => value map) with ALL views.
        // Cached for 60 minutes; cleared whenever admin saves settings.
        View::composer('*', function ($view) {
            $settings = Cache::remember('site_settings', 3600, function () {
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
