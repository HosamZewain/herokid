<?php

namespace App\Http\Controllers;

use App\Models\AdminMediaFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicMediaController extends Controller
{
    public function __invoke(AdminMediaFile $media): StreamedResponse
    {
        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->path), 404);

        $mime = $media->mime_type === 'text/plain' ? 'text/plain; charset=UTF-8' : $media->mime_type;
        $encodedName = rawurlencode($media->original_name);

        return $disk->response($media->path, $media->original_name, [
            'Content-Type' => $mime,
            'Content-Disposition' => "inline; filename*=UTF-8''{$encodedName}",
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
