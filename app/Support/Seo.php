<?php

namespace App\Support;

use Illuminate\Http\Request;

class Seo
{
    public const DEFAULT_CANONICAL_URL = 'https://hero-kid.com';

    public static function canonicalBase(): string
    {
        $configured = rtrim((string) config('app.url', self::DEFAULT_CANONICAL_URL), '/') ?: self::DEFAULT_CANONICAL_URL;
        $parts = parse_url($configured) ?: [];
        $host = strtolower((string) ($parts['host'] ?? 'hero-kid.com'));

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return 'https://' . $host . $port;
    }

    public static function url(?string $path = '/'): string
    {
        $path = trim((string) ($path ?: '/'));

        if (str_starts_with($path, '//')) {
            $path = 'https:' . $path;
        }

        if (preg_match('#^https?://#i', $path)) {
            $parts = parse_url($path) ?: [];
            $relativePath = $parts['path'] ?? '/';
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

            return self::canonicalBase() . self::normalizePath($relativePath) . $query . $fragment;
        }

        if (! str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return self::canonicalBase() . self::normalizePath($path);
    }

    public static function canonicalForRequest(Request $request): string
    {
        return self::url($request->getPathInfo() ?: '/');
    }

    public static function imageUrl(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return self::url('/images/logo-192.png');
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        if (preg_match('#^https?://#i', $url)) {
            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

            if (in_array($host, ['hero-kid.com', 'www.hero-kid.com', 'localhost', '127.0.0.1'], true)) {
                return self::url($url);
            }

            return $url;
        }

        return self::url($url);
    }

    public static function jsonFlags(): int
    {
        return JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT;
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . ltrim($path, '/');
    }
}
