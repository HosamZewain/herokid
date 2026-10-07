<?php

namespace App\Services\Images;

use Imagick;
use RuntimeException;

class PublicImageEncoder
{
    public function encode(string $source, callable $write): array
    {
        if (filesize($source) > (int) config('public_images.max_bytes')) {
            throw new RuntimeException('Public image exceeds the safe processing size.');
        }
        $image = new Imagick;
        try {
            $image->pingImage($source);
            if ($image->getNumberImages() !== 1 || ! in_array(strtoupper($image->getImageFormat()), ['JPEG', 'PNG', 'WEBP'], true)) {
                throw new RuntimeException('Animated or unsupported source: keep the original.');
            }
            if ($image->getImageWidth() * $image->getImageHeight() > (int) config('public_images.max_pixels')) {
                throw new RuntimeException('Public image exceeds the safe pixel limit.');
            }
            $image->clear();
            $image->readImage($source);
            $image->autoOrient();
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            $image->stripImage();
            $sourceWidth = $image->getImageWidth();
            $widths = array_unique(array_map(fn ($width) => min((int) $width, $sourceWidth), config('public_images.widths')));
            $variants = [];
            foreach ($widths as $width) {
                $variant = clone $image;
                try {
                    if ($width < $sourceWidth) {
                        $variant->resizeImage($width, 0, Imagick::FILTER_LANCZOS, 1);
                    }
                    $variant->setImageFormat('webp');
                    $variant->setImageCompressionQuality((int) config('public_images.quality'));
                    $bytes = $variant->getImageBlob();
                    if (strlen($bytes) < filesize($source)) {
                        $variants[] = $write($width, $variant->getImageHeight(), $bytes);
                    }
                } finally {
                    $variant->clear();
                }
            }

            return $variants;
        } finally {
            $image->clear();
        }
    }
}
