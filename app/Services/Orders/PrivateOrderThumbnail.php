<?php

namespace App\Services\Orders;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PrivateOrderThumbnail
{
    public function forget(string $source): void
    {
        if (is_file($source)) {
            Storage::disk('local')->delete($this->path($source));
        }
    }

    private function path(string $source): string
    {
        $fingerprint = hash('sha256', $source.'|'.filemtime($source).'|'.filesize($source));

        return 'order-thumbnails/'.substr($fingerprint, 0, 2).'/'.$fingerprint.'.jpg';
    }

    public function response(string $source): BinaryFileResponse
    {
        $fingerprint = hash('sha256', $source.'|'.filemtime($source).'|'.filesize($source));
        $disk = Storage::disk('local');
        $path = $this->path($source);
        if (! $disk->exists($path)) {
            try {
                $image = new \Imagick;
                $image->pingImage($source);
                if ($image->getImageWidth() * $image->getImageHeight() > 40000000) {
                    return response()->file($source, ['Cache-Control' => 'no-cache'])->setPrivate();
                }
                $image->clear();
                $image->readImage($source.'[0]');
                $image->setIteratorIndex(0);
                $image->autoOrient();
                $image->thumbnailImage(400, 400, true, true);
                $image->setImageBackgroundColor('white');
                $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $image->stripImage();
                $image->setImageFormat('jpeg');
                $image->setImageCompressionQuality(78);
                if (! $disk->put($path, $image->getImageBlob(), ['visibility' => 'private'])) {
                    throw new \RuntimeException('Thumbnail storage unavailable.');
                }
                $image->clear();
            } catch (\Throwable) {
                // Unsupported hosting decoder: never prevent access to the original.
                return response()->file($source, ['Cache-Control' => 'no-cache'])->setPrivate();
            }
        }
        $response = response()->file($disk->path($path), ['Cache-Control' => 'private, no-cache', 'Content-Type' => 'image/jpeg']);
        $response->setPrivate();
        $response->setEtag($fingerprint);
        $response->isNotModified(request());

        return $response;
    }
}
