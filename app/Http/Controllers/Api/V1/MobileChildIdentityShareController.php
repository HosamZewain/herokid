<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareEventService;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareManager;
use App\Services\ChildIdentity\Sharing\ChildIdentitySharePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobileChildIdentityShareController extends Controller
{
    public function store(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityShareManager $manager,
        ChildIdentitySharePresenter $presenter,
    ): JsonResponse {
        $this->owner($request, $identity);
        $request->validate(['share_consent' => ['required', 'accepted']]);
        $attempt = $identity->approvedAttempt()->firstOrFail();
        $share = $identity->share()->withTrashed()->first();

        if (! $share || $share->generation_attempt_id !== $attempt->id || in_array($share->status, ['failed', 'revoked', 'cards_removed'], true)) {
            $share = $manager->createOrUpdate(
                $identity,
                $attempt,
                $request,
                false,
                true,
                actor: $request->user(),
                generateImmediately: true,
            );
        }

        if ($share->status !== 'ready' || ! $share->share_enabled) {
            return response()->json(['data' => [
                'status' => $share->status,
                'message' => $share->generation_error ?: 'Your branded share card is being prepared.',
            ]], 202);
        }

        return response()->json(['data' => [
            'status' => 'ready',
            'share_id' => $share->id,
            ...$presenter->customerPayload($share->load('identityRequest')),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function event(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityShare $share,
        ChildIdentityShareEventService $events,
    ): JsonResponse {
        $this->owner($request, $identity);
        abort_unless($share->child_identity_request_id === $identity->id, 404);
        $data = $request->validate([
            'event_type' => ['required', Rule::in(['share.native_opened', 'share.whatsapp_clicked', 'share.facebook_clicked', 'share.instagram_clicked', 'share.image_saved'])],
            'channel' => ['required', Rule::in(['native', 'whatsapp', 'facebook', 'instagram_feed', 'download_feed'])],
        ]);
        $events->record($share, $data['event_type'], channel: $data['channel'], metadata: ['source' => 'mobile']);

        return response()->json(['recorded' => true]);
    }

    private function owner(Request $request, ChildIdentityRequest $identity): void
    {
        abort_unless($identity->user_id === $request->user()->id, 404);
    }
}
