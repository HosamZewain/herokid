<?php

namespace App\Services\Orders;

use App\Models\OrderPreview;
use App\Services\Storage\MediaStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickDraw;
use ImagickException;
use ImagickPixel;
use RuntimeException;

class OrderProductPreviewImageService
{
    public function __construct(private readonly MediaStorage $mediaStorage) {}

    public function customerImage(OrderPreview $preview): array
    {
        $disk = Storage::disk($preview->disk ?: 'local');
        abort_unless($disk->exists($preview->file_path), 404);

        $protectedPath = $this->protectedPath($preview);
        if (! $disk->exists($protectedPath)) {
            $this->mediaStorage->withLocalCopy(
                $preview->disk ?: 'local',
                $preview->file_path,
                fn (string $sourcePath) => $this->createProtectedImage($sourcePath, $protectedPath, $preview->disk ?: 'local'),
            );
        }

        return [
            'disk' => $preview->disk ?: 'local',
            'path' => $protectedPath,
            'mime_type' => 'image/jpeg',
        ];
    }

    public function deleteCustomerImage(OrderPreview $preview): void
    {
        Storage::disk($preview->disk ?: 'local')->delete($this->protectedPath($preview));
    }

    public function protectedPath(OrderPreview $preview): string
    {
        $directory = trim(dirname($preview->file_path), '.');
        $fingerprint = substr(hash('sha256', implode('|', [
            $preview->checksum ?: $preview->file_path,
            'mandatory-watermark-v4',
            (string) config('order_product_previews.watermark_version', 1),
            (string) config('order_product_previews.watermark_text', 'HeroKid Preview'),
            (string) config('order_product_previews.customer_max_width', 1400),
            (string) config('order_product_previews.customer_max_height', 1400),
            (string) config('order_product_previews.customer_jpeg_quality', 80),
        ])), 0, 16);

        $stem = pathinfo($preview->file_path, PATHINFO_FILENAME);

        return $directory.'/customer-previews/'.$stem.'-'.$fingerprint.'.jpg';
    }

    private function createProtectedImage(string $sourcePath, string $protectedPath, string $disk): void
    {
        $blob = $this->cachedProtectedBytes(file_get_contents($sourcePath));
        abort_unless(Storage::disk($disk)->put($protectedPath, $blob, ['visibility' => 'private']), 500);
    }

    /** Cache only a derived, watermarked JPEG; original bytes are verified by the caller on every read. */
    public function cachedProtectedBytes(string $bytes): string
    {
        $fingerprint = hash('sha256', hash('sha256', $bytes).'|mandatory-watermark-v4|'.json_encode(config('order_product_previews'), JSON_THROW_ON_ERROR));
        // Reuse the existing seven-day private derived-thumbnail retention, never customer originals.
        $disk = $this->mediaStorage->processingDisk();
        $path = 'order-thumbnails/protected/'.$fingerprint.'.jpg';
        if ($disk->exists($path)) {
            return $disk->get($path);
        }

        $protected = $this->protectedBytes($bytes);
        $temporary = $path.'.'.Str::uuid().'.tmp';
        try {
            if ($disk->put($temporary, $protected, ['visibility' => 'private'])) {
                $disk->move($temporary, $path);
            }
        } finally {
            $disk->delete($temporary);
        }

        return $protected;
    }

    /** Always render a derivative; callers must never fall back to the original on failure. */
    public function protectedBytes(string $bytes): string
    {
        $image = new Imagick;
        $threads = Imagick::getResourceLimit(Imagick::RESOURCETYPE_THREAD);
        try {
            // FPM already parallelizes image requests; nested OpenMP threads contend
            // on the same small CPU pool. Restore the per-process limit afterwards.
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 1);
            $image->readImageBlob($bytes);
            $image->setIteratorIndex(0);
            $image->autoOrient();
            $image->setImageBackgroundColor(new ImagickPixel('white'));
            $flattened = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $image->clear();
            $image = $flattened;
            $image->thumbnailImage(
                (int) config('order_product_previews.customer_max_width', 1400),
                (int) config('order_product_previews.customer_max_height', 1400),
                true,
                true,
            );
            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $this->applyWatermark($image);
            $image->stripImage();
            $image->setImageFormat('jpeg');
            $image->setImageCompression(Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality((int) config('order_product_previews.customer_jpeg_quality', 80));

            return $image->getImagesBlob();
        } catch (ImagickException $exception) {
            throw new RuntimeException('تعذر تجهيز نسخة المعاينة المحمية.', previous: $exception);
        } finally {
            $image->clear();
            $image->destroy();
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, $threads);
        }
    }

    private function applyWatermark(Imagick $image): void
    {
        $text = trim((string) config('order_product_previews.watermark_text', 'HeroKid Preview'));
        if ($text === '') {
            $text = 'HeroKid Preview';
        }

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $fontSize = max(20, (int) round(min($width, $height) * 0.045));
        $horizontalStep = max(260, (int) round($fontSize * 8));
        $verticalStep = max(180, (int) round($fontSize * 5));

        $draw = new ImagickDraw;
        $draw->setFont(public_path('fonts/cairo-bold.ttf'));
        $draw->setFillColor(new ImagickPixel('rgba(255,255,255,0.42)'));
        $draw->setStrokeColor(new ImagickPixel('rgba(15,23,42,0.28)'));
        $draw->setStrokeWidth(max(1, $fontSize * 0.035));
        $draw->setFontSize($fontSize);
        $draw->setFontWeight(700);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);

        // Draw a small seamless tile once. Annotating the full image repeatedly was
        // rerasterizing hundreds of text runs, including many outside the image.
        $tile = new Imagick;
        $stamp = new Imagick;
        $canvas = new Imagick;
        $texture = null;
        try {
            $stamp->newImage($horizontalStep * 3, $verticalStep * 3, new ImagickPixel('transparent'), 'png');
            $stamp->annotateImage($draw, $horizontalStep * 1.5, $verticalStep * 1.5, -28, $text);
            $stamp->trimImage(0);
            $stamp->setImagePage(0, 0, 0, 0);
            $tile->newImage($horizontalStep * 3, $verticalStep * 3, new ImagickPixel('transparent'), 'png');
            for ($y = 0; $y < 3; $y++) {
                for ($x = 0; $x < 3; $x++) {
                    $tile->compositeImage($stamp, Imagick::COMPOSITE_OVER, (int) round(($x + 0.5) * $horizontalStep - $stamp->getImageWidth() / 2), (int) round(($y + 0.5) * $verticalStep - $stamp->getImageHeight() / 2));
                }
            }
            $tile->cropImage($horizontalStep, $verticalStep, $horizontalStep, $verticalStep);
            $tile->setImagePage(0, 0, 0, 0);
            $canvas->newImage($width, $height, new ImagickPixel('transparent'), 'png');
            $texture = $canvas->textureImage($tile);
            $image->compositeImage($texture, Imagick::COMPOSITE_OVER, 0, 0);
        } finally {
            $texture?->clear();
            $canvas->clear();
            $tile->clear();
            $stamp->clear();
            $draw->clear();
        }
    }
}
