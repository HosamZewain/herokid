<?php

namespace App\Http\Controllers;

use App\Models\AdminMediaFile;
use App\Services\Storage\PersistentMediaResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicMediaController extends Controller
{
    public function __construct(private readonly PersistentMediaResponse $mediaResponse) {}

    public function __invoke(AdminMediaFile $media): BinaryFileResponse
    {
        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->path), 404);

        $mime = $media->mime_type === 'text/plain' ? 'text/plain; charset=UTF-8' : $media->mime_type;

        return $this->mediaResponse->inline($media->disk, $media->path, $media->original_name, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
