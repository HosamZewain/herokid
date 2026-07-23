<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityPhoto;
use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use Illuminate\Support\Facades\Storage;

class ChildIdentityMediaController extends Controller
{
    public function photo(int $identity, ChildIdentityPhoto $photo)
    {
        $identity = ChildIdentityRequest::withTrashed()->findOrFail($identity);
        abort_unless($photo->child_identity_request_id === $identity->id, 404);

        return $this->respond($photo->disk, $photo->path, $photo->mime_type);
    }

    public function attempt(int $identity, ChildIdentityGenerationAttempt $attempt)
    {
        $identity = ChildIdentityRequest::withTrashed()->findOrFail($identity);
        abort_unless(
            $attempt->child_identity_request_id === $identity->id && filled($attempt->output_storage_path),
            404,
        );

        return $this->respond(
            $attempt->output_disk ?: 'local',
            $attempt->output_storage_path,
            (string) data_get($attempt->response_metadata, 'output_mime_type', 'image/png'),
        );
    }

    public function shareCard(ChildIdentityShare $share, string $variant)
    {
        abort_unless(in_array($variant, ChildIdentityShare::VARIANTS, true), 404);
        $path = $share->cardPath($variant);
        abort_unless(filled($path), 404);

        return $this->respond($share->card_disk, $path, 'image/jpeg');
    }

    private function respond(string $disk, string $path, string $mime)
    {
        abort_if(str_contains($path, '..') || ! Storage::disk($disk)->exists($path), 404);
        $mime = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'application/octet-stream';

        return Storage::disk($disk)->response($path, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
