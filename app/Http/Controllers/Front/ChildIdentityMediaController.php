<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityPhoto;
use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Services\ChildIdentity\ChildIdentityAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChildIdentityMediaController extends Controller
{
    public function photo(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityPhoto $photo,
        ChildIdentityAccessService $access,
    ) {
        abort_unless($access->authorized($identity, $request), 403);
        abort_unless($photo->child_identity_request_id === $identity->id, 404);

        return $this->privateFile($photo->disk, $photo->path, $photo->mime_type);
    }

    public function attempt(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityGenerationAttempt $attempt,
        ChildIdentityAccessService $access,
    ) {
        abort_unless($access->authorized($identity, $request), 403);
        abort_unless(
            $attempt->child_identity_request_id === $identity->id
            && $attempt->status === 'succeeded'
            && filled($attempt->output_storage_path),
            404,
        );

        $share = ChildIdentityShare::query()
            ->where('child_identity_request_id', $identity->id)
            ->where('generation_attempt_id', $attempt->id)
            ->where('status', 'ready')
            ->where('share_enabled', true)
            ->first();

        if ($share
            && filled($share->feed_card_path)
            && Storage::disk($share->card_disk)->exists($share->feed_card_path)) {
            return $this->privateFile($share->card_disk, $share->feed_card_path, 'image/jpeg');
        }

        if (filled($attempt->share_feed_card_path)
            && Storage::disk('local')->exists($attempt->share_feed_card_path)) {
            return $this->privateFile('local', $attempt->share_feed_card_path, 'image/jpeg');
        }

        return $this->privateFile(
            $attempt->output_disk ?: 'local',
            $attempt->output_storage_path,
            (string) data_get($attempt->response_metadata, 'output_mime_type', 'image/png'),
        );
    }

    private function privateFile(string $disk, string $path, string $mime)
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
