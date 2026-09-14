<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/** Shared cache across requests; exactly one lookup within a request. */
class RequestSettings
{
    public static function all(): array
    {
        $attributes = request()->attributes;
        if (! $attributes->has(self::class)) {
            $attributes->set(self::class, Cache::rememberForever('site_settings', fn () => Setting::query()->pluck('value', 'key')->all()));
        }

        return $attributes->get(self::class);
    }

    public static function forget(): void
    {
        request()->attributes->remove(self::class);
        Cache::forget('site_settings');
    }
}
