<?php

namespace App\Support;

use RuntimeException;

class BookArtwork
{
    /** Preserve native pixels: pad legacy art, trim only v2 safe margins; never claim upscaled DPI. */
    public static function a4(string $bytes, bool $cover = false, string $contract = 'a4-cover-v1'): array
    {
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] < 1024 || $size[1] <= $size[0] || $size[0] * $size[1] > 25000000) {
            throw new RuntimeException('puzzle_output_quality');
        }
        [$w, $h] = $size;
        $width = max($w, (int) ceil($h * 210 / 297));
        $height = max($h, (int) ceil($w * 297 / 210));
        $v2 = $cover && $contract === 'a4-cover-v2';
        $offsetX = $offsetY = 0;
        if ($v2) {
            // Native-pixel crop only for the explicitly safe-composed v2 cover. Interiors/v1 retain padding.
            $width = min($w, (int) round($h * 210 / 297));
            $height = min($h, (int) round($w * 297 / 210));
            $offsetX = (int) floor(($w - $width) / 2);
            $offsetY = (int) floor(($h - $height) / 2);
            if (($w - $width) / $w > .06 || ($h - $height) / $h > .06) {
                throw new RuntimeException('puzzle_output_quality');
            }
        }
        $source = imagecreatefromstring($bytes);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        if ($v2) {
            imagecopy($canvas, $source, 0, 0, $offsetX, $offsetY, $width, $height);
        } else {
            imagecopy($canvas, $source, (int) floor(($width - $w) / 2), (int) floor(($height - $h) / 2), 0, 0, $w, $h);
        }
        if ($cover) {
            $logo = imagecreatefrompng(public_path($v2 ? 'images/branding/herokid-cover-transparent-v2.png' : 'images/branding/herokid-20261018-768.png'));
            $logoWidth = (int) round($width * ($v2 ? .22 : .25));
            $logoHeight = (int) round(imagesy($logo) * $logoWidth / imagesx($logo));
            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $logo, $v2 ? (int) round(($width - $logoWidth) / 2) : (int) round($width * .055), $height - $logoHeight - (int) round($height * .045), 0, 0, $logoWidth, $logoHeight, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
        }
        ob_start();
        imagepng($canvas);
        $result = ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);

        return ['bytes' => $result, 'width' => $width, 'height' => $height, 'source_width' => $w, 'source_height' => $h,
            'effective_ppi' => round(min($width / (210 / 25.4), $height / (297 / 25.4)), 1),
            'print_review_required' => true, 'resampled' => false,
            'fit' => $v2 ? 'safe_center_crop' : 'paper_padding', 'crop_origin' => [$offsetX, $offsetY]];
    }
}
