<?php

namespace App\Services\Orders;

use App\Services\Storage\MediaStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PrivateOrderThumbnail
{
    public function __construct(private readonly MediaStorage $mediaStorage) {}

    public function forget(string $disk, string $source): void
    {
        if (Storage::disk($disk)->exists($source)) {
            Storage::disk($this->mediaStorage->processingDiskName())->delete($this->path($disk, $source));
        }
    }

    private function path(string $disk, string $source): string
    {
        $storage = Storage::disk($disk);
        $fingerprint = hash('sha256', $disk.'|'.$source.'|'.$storage->lastModified($source).'|'.$storage->size($source));

        return 'order-thumbnails/'.substr($fingerprint, 0, 2).'/'.$fingerprint.'.jpg';
    }

    public function response(string $diskName, string $source): Response
    {
        $sourceDisk = Storage::disk($diskName);
        abort_unless($sourceDisk->exists($source), 404);

        $fingerprint = hash('sha256', $diskName.'|'.$source.'|'.$sourceDisk->lastModified($source).'|'.$sourceDisk->size($source));
        $cacheDisk = $this->mediaStorage->processingDisk();
        $path = $this->path($diskName, $source);
        if (! $cacheDisk->exists($path)) {
            try {
                $this->mediaStorage->withLocalCopy($diskName, $source, function (string $localSource) use ($cacheDisk, $path): void {
                    $image = new \Imagick;
                    $image->pingImage($localSource);
                    if ($image->getImageWidth() * $image->getImageHeight() > 40000000) {
                        throw new \RuntimeException('Image is too large to thumbnail safely.');
                    }
                    $image->clear();
                    $image->readImage($localSource.'[0]');
                    $image->setIteratorIndex(0);
                    $image->autoOrient();
                    $image->thumbnailImage(400, 400, true, true);
                    $image->setImageBackgroundColor('white');
                    $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                    $image->stripImage();
                    $image->setImageFormat('jpeg');
                    $image->setImageCompressionQuality(78);
                    if (! $cacheDisk->put($path, $image->getImageBlob(), ['visibility' => 'private'])) {
                        throw new \RuntimeException('Thumbnail storage unavailable.');
                    }
                    $image->clear();
                });
            } catch (\Throwable) {
                // Unsupported hosting decoder: never prevent access to the original.
                return $sourceDisk->response($source, null, ['Cache-Control' => 'private, no-cache']);
            }
        }
        $response = response()->file($cacheDisk->path($path), ['Cache-Control' => 'private, no-cache', 'Content-Type' => 'image/jpeg']);
        $response->setPrivate();
        $response->setEtag($fingerprint);
        $response->isNotModified(request());

        return $response;
    }
}
