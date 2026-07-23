<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityShare;
use App\Services\ChildIdentity\Sharing\ChildIdentityReferralService;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareEventService;
use App\Services\ChildIdentity\Sharing\ChildIdentitySharePresenter;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PublicChildIdentityShareController extends Controller
{
    public function show(
        Request $request,
        ChildIdentityShare $share,
        ChildIdentitySharePresenter $presenter,
        ChildIdentityShareEventService $events,
        ChildIdentityReferralService $referrals,
        ChildIdentityShareSettings $settings,
    ) {
        $this->ensureAvailable($share);
        $referrals->remember($share, $request);
        $events->record($share, 'share.page_viewed', $request);
        $public = $presenter->safePublicPayload($share);

        return response()
            ->view('front.child-identity-share.show', compact('share', 'public', 'settings'))
            ->header('Cache-Control', 'private, no-cache, max-age=0')
            ->header('X-Robots-Tag', 'noindex, follow');
    }

    public function card(Request $request, ChildIdentityShare $share, string $variant)
    {
        $this->ensureAvailable($share);
        abort_unless(in_array($variant, ChildIdentityShare::VARIANTS, true), 404);
        $path = $share->cardPath($variant);
        abort_unless($path && Storage::disk($share->card_disk)->exists($path), 404);
        $contents = Storage::disk($share->card_disk)->get($path);
        $etag = '"'.hash('sha256', $share->card_fingerprint.':'.$variant.':'.$share->generation_version).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        $response = response($contents, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'public, max-age=300, must-revalidate',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);

        if ($request->boolean('download')) {
            $filename = $variant === 'story'
                ? 'herokid-child-identity-story.jpg'
                : 'herokid-child-identity-feed.jpg';
            $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        }

        return $response;
    }

    public function cta(
        Request $request,
        ChildIdentityShare $share,
        ChildIdentityShareEventService $events,
        ChildIdentityReferralService $referrals,
    ) {
        $this->ensureAvailable($share);
        $referrals->remember($share, $request);
        $events->record($share, 'share.cta_clicked', $request, 'public_page');

        return redirect()->route('child-identity.index');
    }

    private function ensureAvailable(ChildIdentityShare $share): void
    {
        abort_unless($share->isPubliclyAvailable(), 410, 'رابط المشاركة غير متاح.');
    }
}
