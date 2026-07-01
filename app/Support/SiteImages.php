<?php

namespace App\Support;

class SiteImages
{
    public const SETTINGS = [
        'img_hero_main' => '/images/site/settings/hero-main.svg',
        'img_hero_mini1' => '/images/site/settings/hero-mini-adventure.svg',
        'img_hero_mini2' => '/images/site/settings/hero-mini-magic.svg',
        'img_home_step1' => '/images/site/settings/home-step-choose.svg',
        'img_home_step2' => '/images/site/settings/home-step-customize.svg',
        'img_home_step3' => '/images/site/settings/home-step-deliver.svg',
        'img_stat_books' => '/images/site/settings/stat-books.svg',
        'img_stat_rating' => '/images/site/settings/stat-rating.svg',
        'img_stat_family' => '/images/site/settings/stat-family.svg',
        'img_stat_delivery' => '/images/site/settings/stat-delivery.svg',
        'img_hiw_step1' => '/images/site/settings/hiw-step-library.svg',
        'img_hiw_step2' => '/images/site/settings/hiw-step-photos.svg',
        'img_hiw_step3' => '/images/site/settings/hiw-step-art.svg',
        'img_hiw_step4' => '/images/site/settings/hiw-step-review.svg',
        'img_hiw_step5' => '/images/site/settings/hiw-step-delivery.svg',
    ];

    public static function path(string $key): string
    {
        return self::SETTINGS[$key] ?? '/images/logo-192.png';
    }

    public static function url(string $key): string
    {
        return Seo::url(self::path($key));
    }

    public static function settingsDefaults(): array
    {
        return self::SETTINGS;
    }
}
