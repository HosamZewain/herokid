<?php

namespace App\Services\Ai;

use RuntimeException;

class OpenAiImageInputNormalizer
{
    private const MAX_PIXELS = 50_000_000;

    private const MAX_LONG_EDGE = 4096;

    public function normalizeDataUri(string $dataUri): array
    {
        if (! preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.*)$/s', $dataUri, $matches)) {
            throw new RuntimeException('OpenAI image generation requires a private image data reference.');
        }

        $contents = base64_decode($matches[2], true);

        if ($contents === false || $contents === '') {
            throw new RuntimeException('تعذر قراءة الصورة المرجعية قبل إرسالها إلى OpenAI.');
        }

        $sourceMime = strtolower($matches[1]);
        $imageInfo = @getimagesizefromstring($contents);

        if (! is_array($imageInfo) && (str_contains($sourceMime, 'heic') || str_contains($sourceMime, 'heif'))) {
            $contents = $this->convertHeicToPng($contents);
            $imageInfo = @getimagesizefromstring($contents);
        }

        if (! is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
            throw new RuntimeException('الصورة المرجعية غير صالحة أو ليست ملف صورة قابلًا للقراءة.');
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];

        if (($width * $height) > self::MAX_PIXELS) {
            throw new RuntimeException('أبعاد الصورة المرجعية كبيرة جدًا للمعالجة الآمنة.');
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
            throw new RuntimeException('مكتبة GD مطلوبة لتجهيز صور OpenAI على الخادم.');
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            throw new RuntimeException('تعذر فك ترميز الصورة المرجعية. استخدم PNG أو JPEG أو WebP صالحًا.');
        }

        try {
            [$targetWidth, $targetHeight] = $this->targetDimensions($width, $height);
            $target = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($target === false) {
                throw new RuntimeException('تعذر تجهيز الصورة المرجعية قبل إرسالها إلى OpenAI.');
            }

            try {
                imagealphablending($target, false);
                imagesavealpha($target, true);
                $transparent = imagecolorallocatealpha($target, 255, 255, 255, 127);
                imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
                imagealphablending($target, true);

                if (! imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                    throw new RuntimeException('تعذر تحويل الصورة المرجعية إلى صيغة RGB متوافقة مع OpenAI.');
                }

                ob_start();
                $encoded = imagepng($target, null, 6);
                $normalized = ob_get_clean();

                if (! $encoded || ! is_string($normalized) || $normalized === '') {
                    throw new RuntimeException('تعذر ترميز الصورة المرجعية بصيغة PNG متوافقة مع OpenAI.');
                }
            } finally {
                imagedestroy($target);
            }
        } finally {
            imagedestroy($source);
        }

        return [
            'contents' => $normalized,
            'mime' => 'image/png',
            'extension' => 'png',
            'width' => $targetWidth,
            'height' => $targetHeight,
            'source_mime' => $sourceMime,
        ];
    }

    private function convertHeicToPng(string $contents): string
    {
        if (! class_exists(\Imagick::class) || \Imagick::queryFormats('HEI*') === []) {
            throw new RuntimeException('دعم HEIC/HEIF غير متاح على خادم الصور حاليًا.');
        }

        try {
            $probe = new \Imagick;
            $probe->pingImageBlob($contents);
            $width = $probe->getImageWidth();
            $height = $probe->getImageHeight();
            $probe->clear();

            if ($width < 1 || $height < 1 || ($width * $height) > self::MAX_PIXELS) {
                throw new RuntimeException('أبعاد صورة HEIC/HEIF غير صالحة أو كبيرة جدًا.');
            }

            $image = new \Imagick;
            $image->readImageBlob($contents);
            $image->setIteratorIndex(0);
            $image->autoOrientImage();
            $image->setImageFormat('png');
            $image->stripImage();
            $png = $image->getImageBlob();
            $image->clear();

            if ($png === '') {
                throw new RuntimeException('تعذر تحويل صورة HEIC/HEIF.');
            }

            return $png;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException('تعذر تحويل صورة HEIC/HEIF إلى صيغة متوافقة.', previous: $exception);
        }
    }

    private function targetDimensions(int $width, int $height): array
    {
        $longEdge = max($width, $height);

        if ($longEdge <= self::MAX_LONG_EDGE) {
            return [$width, $height];
        }

        $scale = self::MAX_LONG_EDGE / $longEdge;

        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }
}
