<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Services\ChildIdentity\ChildIdentityAccessService;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareEventService;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareManager;
use App\Services\ChildIdentity\Sharing\ChildIdentitySharePresenter;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChildIdentityShareController extends Controller
{
    public function store(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityAccessService $access,
        ChildIdentityShareManager $manager,
        ChildIdentitySharePresenter $presenter,
        ChildIdentityShareEventService $events,
    ) {
        $this->authorizeIdentity($identity, $request, $access);
        $validated = $request->validate([
            'share_consent' => ['sometimes', 'accepted'],
            'share_action' => ['nullable', Rule::in(['whatsapp', 'facebook', 'download'])],
        ]);
        if (! $request->boolean('share_consent') && blank($validated['share_action'] ?? null)) {
            throw ValidationException::withMessages([
                'share_consent' => 'اختر وسيلة المشاركة للمتابعة.',
            ]);
        }
        $attempt = $identity->approvedAttempt()->firstOrFail();
        $share = $manager->createOrUpdate(
            $identity,
            $attempt,
            $request,
            false,
            true,
            actor: $request->user(),
            generateImmediately: true,
        );

        if ($share->status === 'failed') {
            return back()->with('error', $share->generation_error);
        }

        $action = $validated['share_action'] ?? null;
        if ($action && $share->status === 'ready') {
            $payload = $presenter->customerPayload($share);
            $event = match ($action) {
                'whatsapp' => ['share.whatsapp_clicked', 'whatsapp', null],
                'facebook' => ['share.facebook_clicked', 'facebook', null],
                'download' => ['share.image_saved', 'download_feed', 'feed'],
            };
            $events->record($share, $event[0], $request, $event[1], metadata: ['variant' => $event[2]]);

            return match ($action) {
                'whatsapp' => redirect()->away($payload['whatsapp']),
                'facebook' => redirect()->away($payload['facebook']),
                'download' => redirect()->to($payload['cards']['feed'].'&download=1'),
            };
        }

        return back()
            ->with('success', 'بطاقة وأدوات المشاركة جاهزة.')
            ->with('child_identity_share_created', true);
    }

    public function update(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityShare $share,
        ChildIdentityAccessService $access,
        ChildIdentityShareManager $manager,
    ) {
        $this->authorizeShare($identity, $share, $request, $access);
        $validated = $request->validate([
            'generation_attempt_id' => ['required', 'integer'],
        ]);
        $attempt = ChildIdentityGenerationAttempt::query()->findOrFail($validated['generation_attempt_id']);
        $share = $manager->createOrUpdate(
            $identity,
            $attempt,
            $request,
            false,
            false,
            actor: $request->user(),
            generateImmediately: true,
        );

        return $share->status === 'failed'
            ? back()->with('error', $share->generation_error)
            : back()->with('success', 'تم تحديث بطاقة وأدوات المشاركة.');
    }

    public function revoke(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityShare $share,
        ChildIdentityAccessService $access,
        ChildIdentityShareManager $manager,
    ) {
        $this->authorizeShare($identity, $share, $request, $access);
        $manager->revoke($share, $request->user());

        return back()->with('success', 'تم إيقاف الرابط العام فورًا.');
    }

    public function reenable(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityShare $share,
        ChildIdentityAccessService $access,
        ChildIdentityShareManager $manager,
    ) {
        $this->authorizeShare($identity, $share, $request, $access);
        $manager->reenable($share, $request->user());

        return back()->with('success', 'تم تفعيل رابط المشاركة.');
    }

    public function regenerate(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityShare $share,
        ChildIdentityAccessService $access,
        ChildIdentityShareManager $manager,
    ) {
        $this->authorizeShare($identity, $share, $request, $access);
        $manager->regenerate($share, $request->user());

        return back()->with('success', 'تمت إضافة بطاقات المشاركة إلى قائمة التجهيز.');
    }

    public function event(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityShare $share,
        ChildIdentityAccessService $access,
        ChildIdentityShareEventService $events,
        ChildIdentityShareSettings $settings,
    ) {
        $this->authorizeShare($identity, $share, $request, $access);
        $validated = $request->validate([
            'event_type' => ['required', Rule::in([
                'share.native_opened',
                'share.whatsapp_clicked',
                'share.facebook_clicked',
                'share.instagram_clicked',
                'share.link_copied',
                'share.caption_copied',
                'share.image_saved',
            ])],
            'channel' => ['nullable', Rule::in(['native', 'whatsapp', 'facebook', 'instagram_feed', 'instagram_story', 'copy_link', 'copy_caption', 'download_feed', 'download_story'])],
            'variant' => ['nullable', Rule::in(ChildIdentityShare::VARIANTS)],
        ]);
        $channelKey = str_starts_with((string) ($validated['channel'] ?? ''), 'instagram')
            ? 'instagram'
            : match ($validated['channel'] ?? null) {
                'download_feed', 'download_story' => 'download',
                default => $validated['channel'] ?? 'native',
            };
        abort_unless($settings->channelEnabled($channelKey), 404);
        $events->record(
            $share,
            $validated['event_type'],
            $request,
            $validated['channel'] ?? null,
            metadata: ['variant' => $validated['variant'] ?? null],
        );

        return response()->json(['recorded' => true]);
    }

    public function status(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityShare $share,
        ChildIdentityAccessService $access,
    ) {
        $this->authorizeShare($identity, $share, $request, $access);
        $fresh = $share->fresh();

        return response()->json([
            'status' => $fresh->status,
            'ready' => $fresh->status === 'ready' && $fresh->share_enabled,
            'refresh' => $fresh->status === 'generating',
            'message' => $fresh->generation_error,
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    private function authorizeShare(
        ChildIdentityRequest $identity,
        ChildIdentityShare $share,
        Request $request,
        ChildIdentityAccessService $access,
    ): void {
        $this->authorizeIdentity($identity, $request, $access);
        abort_unless($share->child_identity_request_id === $identity->id, 404);
    }

    private function authorizeIdentity(
        ChildIdentityRequest $identity,
        Request $request,
        ChildIdentityAccessService $access,
    ): void {
        abort_unless($access->authorized($identity, $request), 403);
    }
}
