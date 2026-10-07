<?php

namespace App\Services\Images;

use App\Models\PublicImageVariant;
use Illuminate\Support\Facades\Storage;

class PublicImageVariants
{
    private array $records = [];

    private ?array $staticManifest = null;

    /** Batch metadata queries per view, never stat/read S3 objects during page rendering. */
    public function prime(array $urls): void
    {
        $keys = [];
        foreach ($urls as $url) {
            $source = PublicImageSource::fromUrl($url);
            if ($source) {
                $key = PublicImageSource::key($source['disk'], $source['path']);
                if (! array_key_exists($key, $this->records)) {
                    $keys[$key] = $key;
                    $this->records[$key] = null;
                }
            }
        }
        if ($keys !== []) {
            foreach (PublicImageVariant::query()->whereIn('source_key', array_values($keys))->get() as $record) {
                $this->records[$record->source_key] = $record;
            }
        }
    }

    public function presentation(?string $url, int $preferredWidth = 640): array
    {
        $original = (string) $url;
        $variants = $this->staticVariants($original);
        if ($variants === []) {
            $source = PublicImageSource::fromUrl($original);
            if ($source) {
                $this->prime([$original]);
                $record = $this->records[PublicImageSource::key($source['disk'], $source['path'])] ?? null;
                foreach ($record?->variants ?? [] as $variant) {
                    $variants[] = ['url' => Storage::disk($record->source_disk)->url($variant['path']), 'width' => (int) $variant['width']];
                }
            }
        }
        if ($variants === []) {
            return ['src' => $original, 'srcset' => '', 'original' => $original, 'thumbnail' => $original];
        }
        usort($variants, fn ($a, $b) => $a['width'] <=> $b['width']);
        $selected = $variants[array_key_last($variants)];
        foreach ($variants as $variant) {
            if ($variant['width'] >= $preferredWidth) {
                $selected = $variant;
                break;
            }
        }

        return [
            'src' => $selected['url'],
            'srcset' => implode(', ', array_map(fn ($v) => $v['url'].' '.$v['width'].'w', $variants)),
            'original' => $original,
            'thumbnail' => $variants[0]['url'],
        ];
    }

    private function staticVariants(string $url): array
    {
        $base = parse_url(asset('images/'));
        $source = parse_url($url);
        if (! $source || ! $base || (isset($source['host']) && $source['host'] !== ($base['host'] ?? null))) {
            return [];
        }
        $path = ltrim($source['path'] ?? '', '/');
        if (! str_starts_with($path, 'images/')) {
            return [];
        }
        if ($this->staticManifest === null) {
            $file = public_path('images/optimized/manifest.json');
            $this->staticManifest = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        }

        return array_map(fn ($v) => ['url' => asset($v['path']), 'width' => $v['width']], $this->staticManifest[$path] ?? []);
    }
}
