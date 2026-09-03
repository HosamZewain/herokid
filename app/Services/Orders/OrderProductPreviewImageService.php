<?php

namespace App\Services\Orders;

use App\Models\OrderPreview;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickDraw;
use ImagickException;
use ImagickPixel;
use RuntimeException;

class OrderProductPreviewImageService
{
    public function customerImage(OrderPreview $preview): array
    {
        $disk = Storage::disk($preview->disk ?: 'local');
        abort_unless($disk->exists($preview->file_path), 404);

        $protectedPath = $this->protectedPath($preview);
        if (! $disk->exists($protectedPath)) {
            $this->createProtectedImage($disk->path($preview->file_path), $protectedPath, $preview->disk ?: 'local');
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
        try {
            $image = new Imagick($sourcePath);
            $image->setIteratorIndex(0);
            $image->autoOrient();
            $image->setImageBackgroundColor(new ImagickPixel('white'));
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
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

            $blob = $image->getImagesBlob();
            $image->clear();
            $image->destroy();

            abort_unless(Storage::disk($disk)->put($protectedPath, $blob, ['visibility' => 'private']), 500);
        } catch (ImagickException $exception) {
            throw new RuntimeException('تعذر تجهيز نسخة المعاينة المحمية.', previous: $exception);
        }
    }

    private function applyWatermark(Imagick $image): void
    {
        $text = trim((string) config('order_product_previews.watermark_text', 'HeroKid Preview'));
        if ($text === '') {
            return;
        }

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $fontSize = max(20, (int) round(min($width, $height) * 0.045));
        $horizontalStep = max(260, (int) round($fontSize * 8));
        $verticalStep = max(180, (int) round($fontSize * 5));

        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel('rgba(255,255,255,0.42)'));
        $draw->setStrokeColor(new ImagickPixel('rgba(15,23,42,0.28)'));
        $draw->setStrokeWidth(max(1, $fontSize * 0.035));
        $draw->setFontSize($fontSize);
        $draw->setFontWeight(700);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);

        for ($y = -$height; $y <= $height * 2; $y += $verticalStep) {
            for ($x = -$width; $x <= $width * 2; $x += $horizontalStep) {
                $image->annotateImage($draw, $x, $y, -28, $text);
            }
        }
    }
}
