<?php

namespace App\Support;

class SiteBranding
{
    public const DEFAULT_PATH = 'images/branding/herokid-20261018-768.png';

    public static function presentation(): array
    {
        return self::display(self::variants());
    }

    public static function source(int $width): array
    {
        $variants = self::variants();
        usort($variants, fn ($a, $b) => $a['width'] <=> $b['width']);
        foreach ($variants as $variant) {
            if ($variant['width'] >= $width) {
                return $variant;
            }
        }

        return $variants[array_key_last($variants)];
    }

    private static function variants(): array
    {
        $logo = json_decode((string) setting('site_logo', ''), true);
        if (is_array($logo) && isset($logo['disk'], $logo['variants']) && is_array($logo['variants'])
            && in_array($logo['disk'], ['public', 's3_public', config('media.public_disk', 'public')], true)) {
            $variants = [];
            foreach ($logo['variants'] as $variant) {
                if (! is_array($variant) || ! is_string($variant['path'] ?? null)
                    || ! preg_match('~^branding/logos/[a-f0-9-]{36}/logo-[0-9]+\.png$~D', $variant['path'])
                    || ! is_int($variant['width'] ?? null) || ! is_int($variant['height'] ?? null)
                    || $variant['width'] < 1 || $variant['width'] > 768 || $variant['height'] < 1 || $variant['height'] > 4096) {
                    return self::defaults();
                }
                $variants[] = ['disk' => $logo['disk'], 'path' => $variant['path'], 'width' => $variant['width'], 'height' => $variant['height']];
            }
            if ($variants !== []) {
                return $variants;
            }
        }

        return self::defaults();
    }

    public static function url(): string
    {
        return self::presentation()['src'];
    }

    private static function defaults(): array
    {
        return array_map(fn (int $width) => [
            'disk' => null,
            'path' => 'images/branding/herokid-20261018-'.$width.'.png',
            'width' => $width,
            'height' => (int) round($width * 783 / 2009),
        ], [192, 384, 768]);
    }

    private static function display(array $variants): array
    {
        usort($variants, fn ($a, $b) => $a['width'] <=> $b['width']);
        $largest = $variants[array_key_last($variants)];
        $selected = $largest;
        foreach ($variants as $variant) {
            if ($variant['width'] >= 384) {
                $selected = $variant;
                break;
            }
        }

        return [
            'src' => route('branding.logo', ['width' => $selected['width']]),
            'srcset' => implode(', ', array_map(fn ($variant) => route('branding.logo', ['width' => $variant['width']]).' '.$variant['width'].'w', $variants)),
            'width' => $selected['width'], 'height' => $selected['height'],
        ];
    }
}
