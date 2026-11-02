<?php

namespace App\Support;

use RuntimeException;

class BookArtwork
{
    /** Add paper around original pixels; never stretch/crop a child's face or claim upscaled DPI. */
    public static function a4(string $bytes, bool $cover = false): array
    {
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] < 1024 || $size[1] <= $size[0] || $size[0] * $size[1] > 25000000) {
            throw new RuntimeException('puzzle_output_quality');
        }
        [$w, $h] = $size;
        $width = max($w, (int) ceil($h * 210 / 297));
        $height = max($h, (int) ceil($w * 297 / 210));
        $source = imagecreatefromstring($bytes);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, (int) floor(($width - $w) / 2), (int) floor(($height - $h) / 2), 0, 0, $w, $h);
        if ($cover) {
            $logo = imagecreatefrompng(public_path('images/branding/herokid-20261018-768.png'));
            $logoWidth = (int) round($width * .25);
            $logoHeight = (int) round(imagesy($logo) * $logoWidth / imagesx($logo));
            imagecopyresampled($canvas, $logo, (int) round($width * .055), $height - $logoHeight - (int) round($height * .045), 0, 0, $logoWidth, $logoHeight, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
        }
        ob_start();
        imagepng($canvas);
        $result = ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);

        return ['bytes' => $result, 'width' => $width, 'height' => $height, 'source_width' => $w, 'source_height' => $h,
            'effective_ppi' => round(min($width / (210 / 25.4), $height / (297 / 25.4)), 1),
            'print_review_required' => true, 'resampled' => false];
    }
}
