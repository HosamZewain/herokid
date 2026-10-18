<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SiteLogoUpload
{
    /** Re-encode raster uploads: no executable SVG, original filename, or metadata. */
    public function store(UploadedFile $file): array
    {
        $image = @imagecreatefromstring($file->get());
        if ($image === false) {
            throw ValidationException::withMessages(['site_logo_file' => 'تعذّر قراءة صورة اللوجو. اختر صورة PNG أو JPG أو WebP سليمة.']);
        }
        $disk = (string) config('media.public_disk', 'public');
        $directory = 'branding/logos/'.Str::uuid();
        $variants = [];
        try {
            $width = imagesx($image);
            $height = imagesy($image);
            foreach (array_unique(array_map(fn ($size) => min($size, $width), [192, 384, 768])) as $targetWidth) {
                $targetHeight = max(1, (int) round($height * $targetWidth / $width));
                $variant = imagecreatetruecolor($targetWidth, $targetHeight);
                imagealphablending($variant, false);
                imagesavealpha($variant, true);
                imagefill($variant, 0, 0, imagecolorallocatealpha($variant, 255, 255, 255, 127));
                imagecopyresampled($variant, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
                ob_start();
                try {
                    if (! imagepng($variant, null, 9)) {
                        throw ValidationException::withMessages(['site_logo_file' => 'تعذّر تجهيز صورة اللوجو. لم يتم تغيير اللوجو الحالي.']);
                    }
                    $bytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                    imagedestroy($variant);
                }
                $path = $directory.'/logo-'.$targetWidth.'.png';
                if (! Storage::disk($disk)->put($path, $bytes)) {
                    throw ValidationException::withMessages(['site_logo_file' => 'تعذّر حفظ اللوجو. لم يتم تغيير اللوجو الحالي؛ حاول مرة أخرى.']);
                }
                $variants[] = ['path' => $path, 'width' => $targetWidth, 'height' => $targetHeight];
            }

            return ['disk' => $disk, 'variants' => $variants];
        } catch (\Throwable $exception) {
            foreach ($variants as $variant) {
                Storage::disk($disk)->delete($variant['path']);
            }
            throw $exception;
        } finally {
            imagedestroy($image);
        }
    }
}
