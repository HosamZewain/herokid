<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\BookletPreview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BookletPreviewController extends Controller
{
    public function show(Request $request, string $token)
    {
        $preview = $this->authorizeViewer($request, $token);

        return $this->privateReaderResponse(
            response()
                ->view('front.booklet-previews.show', [
                    'preview' => $preview,
                    'documentUrl' => route('booklet-previews.document', $preview),
                    'pageCount' => $preview->currentVersion->page_count,
                    'publicTitle' => $this->publicTitle($preview),
                    'scenesUrl' => route('booklet-previews.scenes', ['token' => $token]),
                ]),
        );
    }

    public function scenes(Request $request, string $token)
    {
        $preview = $this->authorizeViewer($request, $token);

        return $this->privateReaderResponse(
            response()->view('front.booklet-previews.scenes', [
                'preview' => $preview,
                'documentUrl' => route('booklet-previews.document', $preview),
                'pageCount' => $preview->currentVersion->page_count,
                'publicTitle' => $this->publicTitle($preview),
                'flipbookUrl' => route('booklet-previews.show', ['token' => $token]),
            ]),
        );
    }

    public function document(Request $request, string $bookletPreview)
    {
        $bookletPreview = BookletPreview::withTrashed()
            ->with(['currentVersion', 'order'])
            ->where('uuid', $bookletPreview)
            ->first();
        abort_unless($bookletPreview && $bookletPreview->isPubliclyAvailable(), 410, 'رابط المعاينة غير متاح.');

        $grant = $request->session()->get('booklet_preview_grants.'.$bookletPreview->uuid);
        abort_unless(
            is_array($grant)
            && (int) ($grant['version_id'] ?? 0) === (int) $bookletPreview->current_version_id
            && (int) ($grant['expires_at'] ?? 0) >= now()->timestamp,
            403,
            'انتهت جلسة عرض المعاينة. افتح رابط المعاينة مرة أخرى.',
        );

        $version = $bookletPreview->currentVersion;
        abort_unless($version && Storage::disk($version->disk)->exists($version->file_path), 404);

        return response()->file(Storage::disk($version->disk)->path($version->file_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="herokid-preview.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    private function authorizeViewer(Request $request, string $token): BookletPreview
    {
        $preview = BookletPreview::withTrashed()
            ->with(['currentVersion', 'story:id,title,slug', 'order:id,deleted_at'])
            ->where('public_token_hash', hash('sha256', $token))
            ->first();

        abort_unless($preview && $preview->isPubliclyAvailable(), 410, 'رابط المعاينة غير متاح.');
        abort_unless($preview->currentVersion && Storage::disk($preview->currentVersion->disk)->exists($preview->currentVersion->file_path), 404);

        $request->session()->put('booklet_preview_grants.'.$preview->uuid, [
            'version_id' => $preview->current_version_id,
            'expires_at' => now()->addMinutes((int) config('booklet_previews.media_grant_minutes', 30))->timestamp,
        ]);

        BookletPreview::query()->whereKey($preview->id)->increment('view_count', 1, ['last_viewed_at' => now()]);

        return $preview;
    }

    private function publicTitle(BookletPreview $preview): string
    {
        return $preview->source_type === 'order' ? 'معاينة قصة HeroKid' : $preview->title;
    }

    private function privateReaderResponse($response)
    {
        return $response
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
