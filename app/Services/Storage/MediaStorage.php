<?php

namespace App\Services\Storage;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MediaStorage
{
    public function privateDiskName(): string
    {
        return (string) config('media.private_disk', 'local');
    }

    public function publicDiskName(): string
    {
        return (string) config('media.public_disk', 'public');
    }

    public function processingDiskName(): string
    {
        return (string) config('media.processing_disk', 'local');
    }

    public function privateDisk(): Filesystem
    {
        return Storage::disk($this->privateDiskName());
    }

    public function publicDisk(): Filesystem
    {
        return Storage::disk($this->publicDiskName());
    }

    public function processingDisk(): Filesystem
    {
        $name = $this->processingDiskName();
        if (config("filesystems.disks.{$name}.driver") !== 'local') {
            throw new RuntimeException('PROCESSING_DISK must use the local filesystem driver.');
        }

        return Storage::disk($name);
    }

    /**
     * Materialize a persistent object to a unique local working directory.
     * The local copy and its directory are always removed afterwards.
     */
    public function withLocalCopy(string $sourceDisk, string $sourcePath, Closure $callback): mixed
    {
        $this->assertSafePath($sourcePath);

        $source = Storage::disk($sourceDisk);
        if (! $source->exists($sourcePath)) {
            throw new RuntimeException("Media object does not exist on disk [{$sourceDisk}].");
        }

        $processing = $this->processingDisk();
        $directory = 'media-processing/'.Str::uuid();
        $localPath = $directory.'/'.($this->safeBasename($sourcePath) ?: 'source.bin');
        $stream = $source->readStream($sourcePath);

        if ($stream === false) {
            throw new RuntimeException("Unable to read media object from disk [{$sourceDisk}].");
        }

        try {
            if (! $processing->put($localPath, $stream)) {
                throw new RuntimeException('Unable to materialize media object on the processing disk.');
            }

            return $callback($processing->path($localPath));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $processing->deleteDirectory($directory);
        }
    }

    /**
     * Build a file through a real local path then persist it to any disk.
     */
    public function buildAndStore(string $destinationDisk, string $destinationPath, Closure $builder): mixed
    {
        $this->assertSafePath($destinationPath);

        $processing = $this->processingDisk();
        $directory = 'media-processing/'.Str::uuid();
        $workingPath = $directory.'/'.($this->safeBasename($destinationPath) ?: 'output.bin');

        try {
            $absolutePath = $processing->path($workingPath);
            $processing->makeDirectory($directory);
            $result = $builder($absolutePath);

            if (! is_file($absolutePath)) {
                throw new RuntimeException('Media processing did not produce the expected output file.');
            }

            $stream = fopen($absolutePath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the processed media output.');
            }

            try {
                if (! Storage::disk($destinationDisk)->put($destinationPath, $stream)) {
                    throw new RuntimeException("Unable to persist media output to disk [{$destinationDisk}].");
                }
            } finally {
                fclose($stream);
            }

            return $result;
        } finally {
            $processing->deleteDirectory($directory);
        }
    }

    public function copy(string $sourceDisk, string $sourcePath, string $destinationDisk, string $destinationPath): void
    {
        $this->assertSafePath($sourcePath);
        $this->assertSafePath($destinationPath);

        $stream = Storage::disk($sourceDisk)->readStream($sourcePath);
        if ($stream === false) {
            throw new RuntimeException("Unable to read media object from disk [{$sourceDisk}].");
        }

        try {
            if (! Storage::disk($destinationDisk)->put($destinationPath, $stream)) {
                throw new RuntimeException("Unable to copy media object to disk [{$destinationDisk}].");
            }
        } finally {
            fclose($stream);
        }
    }

    public function checksum(string $disk, string $path, string $algorithm = 'sha256'): string
    {
        $this->assertSafePath($path);
        $stream = Storage::disk($disk)->readStream($path);
        if ($stream === false) {
            throw new RuntimeException("Unable to read media object from disk [{$disk}].");
        }

        try {
            $hash = hash_init($algorithm);
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    private function assertSafePath(string $path): void
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new RuntimeException('Unsafe media path.');
        }
    }

    private function safeBasename(string $path): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', basename($path)) ?: 'media.bin';
    }
}
