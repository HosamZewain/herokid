<?php

namespace App\Console\Commands;

use App\Services\Images\PublicImageEncoder;
use App\Support\StoryCover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BuildSiteImageVariants extends Command
{
    protected $signature = 'images:build-site-variants';

    protected $description = 'Build content-versioned responsive variants for bundled public site artwork (build time only)';

    public function handle(PublicImageEncoder $encoder): int
    {
        $files = [...File::files(public_path('images/homepage')), ...File::files(public_path('images/site/settings'))];
        $fallback = public_path(ltrim(StoryCover::FALLBACK_PATH, '/'));
        if (File::isFile($fallback)) {
            $files[] = new \SplFileInfo($fallback);
        }
        $manifest = [];
        foreach ($files as $file) {
            if (! in_array(strtolower($file->getExtension()), ['webp', 'jpg', 'jpeg', 'png'], true)) {
                continue;
            }
            $relative = str_replace(public_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            $hash = substr(hash('sha256', hash_file('sha256', $file->getPathname()).'|v1|'.config('public_images.quality')), 0, 24);
            $variants = $encoder->encode($file->getPathname(), function (int $width, int $height, string $bytes) use ($hash): array {
                $path = 'images/optimized/'.$hash.'/'.$width.'.webp';
                File::ensureDirectoryExists(dirname(public_path($path)));
                File::put(public_path($path), $bytes);

                return ['path' => $path, 'width' => $width, 'height' => $height, 'bytes' => strlen($bytes)];
            });
            if ($variants !== []) {
                $manifest[$relative] = $variants;
            }
        }
        ksort($manifest);
        File::ensureDirectoryExists(public_path('images/optimized'));
        File::put(public_path('images/optimized/manifest.json'), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $this->info('Prepared responsive variants for '.count($manifest).' bundled images. Originals unchanged.');

        return self::SUCCESS;
    }
}
