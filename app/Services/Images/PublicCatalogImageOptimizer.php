<?php

namespace App\Services\Images;

use App\Models\PublicImageVariant;
use App\Services\Storage\MediaStorage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PublicCatalogImageOptimizer
{
    public function __construct(private MediaStorage $media, private PublicImageEncoder $encoder) {}

    public function generate(string $disk, string $path, bool $force = false): bool
    {
        if (! PublicImageSource::allowed($disk, $path)) {
            throw new RuntimeException('Only configured public catalog images may be optimized.');
        }
        $key = PublicImageSource::key($disk, $path);
        $result = Cache::lock('public-image:'.$key, 180)->get(fn () => $this->generateLocked($disk, $path, $key, $force));
        if ($result === null) {
            throw new RuntimeException('This public image is already being optimized; retry later.');
        }

        return $result;
    }

    private function generateLocked(string $disk, string $path, string $key, bool $force): bool
    {

        return $this->media->withLocalCopy($disk, $path, function (string $local) use ($disk, $path, $key, $force): bool {
            $hash = hash_file('sha256', $local);
            $record = PublicImageVariant::where('source_key', $key)->first();
            if (! $force && $record?->source_hash === $hash) {
                return false;
            }
            $fingerprint = hash('sha256', 'v1|'.config('public_images.quality').'|'.$key.'|'.$hash);
            $storage = Storage::disk($disk);
            $created = [];
            try {
                $variants = $this->encoder->encode($local, function (int $width, int $height, string $bytes) use ($storage, $fingerprint, &$created): array {
                    $target = 'display-images/v1/'.$fingerprint.'/'.$width.'.webp';
                    $exists = $storage->exists($target);
                    if (! $exists) {
                        $created[] = $target;
                        // Source/output bytes are bounded; originals are copied to local processing by streaming.
                        if (! $storage->put($target, $bytes)) {
                            throw new RuntimeException('Unable to persist a public image variant.');
                        }
                    }

                    return ['path' => $target, 'width' => $width, 'height' => $height, 'bytes' => strlen($bytes)];
                });
                // Empty variants mark an already-small source as processed; rendering still uses the original.
                PublicImageVariant::updateOrCreate(['source_key' => $key], [
                    'source_disk' => $disk, 'source_path' => $path,
                    'source_hash' => $hash, 'variants' => $variants,
                ]);

                return true;
            } catch (Throwable $error) {
                foreach ($created as $target) {
                    $storage->delete($target);
                }
                throw $error;
            }
        });
    }
}
