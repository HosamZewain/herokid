<?php

namespace App\Support;

use DateTimeInterface;

final class StoryCover
{
    public const FALLBACK_PATH = '/images/site/featured_generic_herokid_v2.png';

    public static function fallbackUrl(): string
    {
        return Seo::imageUrl(self::FALLBACK_PATH);
    }

    public static function versionedUrl(string $url, DateTimeInterface|int|string|null $updatedAt): string
    {
        $version = self::versionValue($updatedAt);

        if ($version === null) {
            return $url;
        }

        return self::withQueryParameter($url, 'v', (string) $version);
    }

    public static function withQueryParameter(string $url, string $key, string $value): string
    {
        $fragment = '';
        $fragmentPosition = strpos($url, '#');

        if ($fragmentPosition !== false) {
            $fragment = substr($url, $fragmentPosition);
            $url = substr($url, 0, $fragmentPosition);
        }

        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
        $parameters = [];
        parse_str($query, $parameters);
        $parameters[$key] = $value;
        $encodedQuery = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        return $base.($encodedQuery !== '' ? '?'.$encodedQuery : '').$fragment;
    }

    private static function versionValue(DateTimeInterface|int|string|null $updatedAt): ?int
    {
        if ($updatedAt instanceof DateTimeInterface) {
            return $updatedAt->getTimestamp();
        }

        if (is_int($updatedAt) || (is_string($updatedAt) && ctype_digit($updatedAt))) {
            $version = (int) $updatedAt;

            return $version > 0 ? $version : null;
        }

        return null;
    }
}
