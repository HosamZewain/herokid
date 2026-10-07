<?php

namespace App\Services\Images;

use Illuminate\Support\Facades\Storage;

class PublicImageSource
{
    public static function key(string $disk, string $path): string
    {
        return hash('sha256', $disk.'|'.$path);
    }

    public static function allowed(string $disk, string $path): bool
    {
        if ($disk !== (string) config('media.public_disk', 'public') || $path === ''
            || str_contains($path, '..') || str_contains($path, '\\')
            || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return false;
        }

        foreach (config('public_images.catalog_prefixes', []) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Resolve configured public-disk URLs only. Never fetch arbitrary external URLs. */
    public static function fromUrl(?string $url): ?array
    {
        if (! $url) {
            return null;
        }

        $disk = (string) config('media.public_disk', 'public');
        $base = Storage::disk($disk)->url('');
        $source = parse_url($url);
        $root = parse_url($base);
        if ($source === false || $root === false) {
            return null;
        }
        if (isset($source['host']) && isset($root['host']) && strtolower($source['host']) !== strtolower($root['host'])) {
            return null;
        }
        if (isset($source['host']) && ! isset($root['host']) && strtolower($source['host']) !== strtolower((string) parse_url(url('/'), PHP_URL_HOST))) {
            return null;
        }
        if (isset($source['port']) && ($source['port'] !== ($root['port'] ?? parse_url(url('/'), PHP_URL_PORT)))) {
            return null;
        }
        $prefix = rtrim($root['path'] ?? '', '/').'/';
        $path = $source['path'] ?? '';
        if (! str_starts_with($path, $prefix)) {
            return null;
        }
        $path = rawurldecode(substr($path, strlen($prefix)));

        return self::allowed($disk, $path) ? ['disk' => $disk, 'path' => $path] : null;
    }
}
