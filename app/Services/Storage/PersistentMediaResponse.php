<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PersistentMediaResponse
{
    public function __construct(private readonly MediaStorage $mediaStorage) {}

    /**
     * Return a range-capable file response without requiring the persistent disk
     * to expose a local filesystem path.
     */
    public function inline(string $diskName, string $path, ?string $downloadName = null, array $headers = []): BinaryFileResponse
    {
        $disk = Storage::disk($diskName);

        if (! $disk->exists($path)) {
            throw new RuntimeException("Media object does not exist on disk [{$diskName}].");
        }

        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            $response = response()->file($disk->path($path), $headers);
            if ($downloadName !== null) {
                $response->setContentDisposition('inline', $downloadName);
            }

            return $response;
        }

        $processing = $this->mediaStorage->processingDisk();
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $temporaryPath = 'media-responses/'.Str::uuid().($extension !== '' ? '.'.$extension : '');
        $stream = $disk->readStream($path);

        if ($stream === false) {
            throw new RuntimeException("Unable to read media object from disk [{$diskName}].");
        }

        try {
            if (! $processing->put($temporaryPath, $stream)) {
                throw new RuntimeException('Unable to materialize media response on the processing disk.');
            }
        } finally {
            fclose($stream);
        }

        $response = response()->file($processing->path($temporaryPath), $headers);
        $response->deleteFileAfterSend(true);

        if ($downloadName !== null) {
            $response->setContentDisposition('inline', $downloadName);
        }

        return $response;
    }
}
