<?php

namespace App\Services\Ai;

use App\Models\ProductionProject;
use App\Models\ProductionProjectAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class GenerationInputAssetResolver
{
    public function resolve(ProductionProject $project, array $indices = [], ?ProductionProjectAsset $characterSheet = null): array
    {
        $assets = [];

        if ($characterSheet?->file_path) {
            $assets[] = $this->dataUriFromPath($characterSheet->file_path);
        }

        $photos = $project->order?->uploaded_photos ?? [];

        foreach (array_slice(array_unique(array_map('intval', $indices)), 0, 4) as $index) {
            if (! isset($photos[$index]) || ! is_string($photos[$index]) || str_contains($photos[$index], '..')) {
                throw new RuntimeException('Selected child reference photo is not available.');
            }

            $assets[] = $this->dataUriFromPath($photos[$index]);
        }

        return $assets;
    }

    private function dataUriFromPath(string $path): string
    {
        if (str_contains($path, '..')) {
            throw new RuntimeException('Invalid input asset path.');
        }

        $contents = null;
        $mime = 'image/png';

        foreach (['local', 'public'] as $diskName) {
            $disk = Storage::disk($diskName);

            if ($disk->exists($path)) {
                $contents = $disk->get($path);
                $mime = $disk->mimeType($path) ?: $mime;
                break;
            }
        }

        if ($contents === null) {
            $legacyPath = storage_path('app/'.ltrim($path, '/'));

            if (file_exists($legacyPath) && is_file($legacyPath)) {
                $contents = file_get_contents($legacyPath);
                $mime = mime_content_type($legacyPath) ?: $mime;
            }
        }

        if ($contents === null) {
            throw new RuntimeException('Input asset file was not found.');
        }

        if (! Str::startsWith($mime, 'image/')) {
            throw new RuntimeException('Input asset must be an image.');
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
