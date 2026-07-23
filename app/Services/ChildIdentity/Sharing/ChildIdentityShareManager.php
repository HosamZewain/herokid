<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Jobs\GenerateChildIdentityShareCardsJob;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChildIdentityShareManager
{
    public const CONSENT_VERSION = 'child-identity-public-share-v1-2026-07';

    public function __construct(
        private readonly ChildIdentityShareSettings $settings,
        private readonly ChildIdentityShareText $text,
        private readonly ChildIdentityShareFingerprint $fingerprints,
        private readonly ChildIdentityShareEventService $events,
    ) {}

    public function createOrUpdate(
        ChildIdentityRequest $identity,
        ChildIdentityGenerationAttempt $attempt,
        Request $request,
        bool $displayFirstName,
        bool $consentAccepted,
        string $actorType = 'customer',
        ?User $actor = null,
    ): ChildIdentityShare {
        abort_unless($this->settings->enabled(), 404);
        $this->assertShareable($identity, $attempt);

        if ($displayFirstName && ! $this->settings->allowFirstName()) {
            throw ValidationException::withMessages([
                'display_child_first_name' => 'عرض الاسم الأول غير متاح حاليًا.',
            ]);
        }

        $share = DB::transaction(function () use ($identity, $attempt, $request, $displayFirstName, $consentAccepted, $actorType, $actor): ChildIdentityShare {
            $lockedIdentity = ChildIdentityRequest::withTrashed()->lockForUpdate()->findOrFail($identity->id);
            abort_if($lockedIdentity->trashed(), 422);
            $share = ChildIdentityShare::withTrashed()
                ->where('child_identity_request_id', $lockedIdentity->id)
                ->lockForUpdate()
                ->first();

            if (! $share && ! $consentAccepted) {
                throw ValidationException::withMessages([
                    'share_consent' => 'يجب الموافقة صراحةً قبل إنشاء الصورة والرابط العام.',
                ]);
            }

            if ($share?->trashed()) {
                $share->restore();
            }

            $isNew = ! $share;
            $share ??= new ChildIdentityShare([
                'child_identity_request_id' => $lockedIdentity->id,
                'public_token' => Str::random(64),
                'consent_accepted_at' => now(),
                'consent_version' => self::CONSENT_VERSION,
                'created_by_type' => $actorType,
                'created_by_id' => $actor?->id,
                'guest_session_hash' => $actor ? null : $this->sessionHash($request),
                'ip_hash' => $this->ipHash($request),
                'generation_version' => 0,
            ]);
            $share->generation_attempt_id = $attempt->id;
            $share->display_child_first_name = $displayFirstName;
            $share->share_enabled = true;
            $share->revoked_at = null;
            $share->status = 'generating';
            $share->template_version = $this->settings->templateVersion();
            $share->caption_snapshot = $this->text->caption(
                $lockedIdentity,
                route('child-identity-shares.show', $share->public_token),
                $displayFirstName,
            );
            $share->hashtags_snapshot = $this->text->normalizeHashtags($this->settings->hashtags());
            $share->generation_error = null;
            $share->generation_version = ((int) $share->generation_version) + 1;
            $share->save();
            $share->load(['identityRequest', 'generationAttempt']);
            $share->forceFill(['card_fingerprint' => $this->fingerprints->make($share)])->save();

            $this->events->record(
                $share,
                $isNew ? 'share.created' : 'share.updated',
                metadata: [
                    'generation_attempt_id' => $attempt->id,
                    'display_child_first_name' => $displayFirstName,
                    'generation_version' => $share->generation_version,
                ],
            );
            $this->events->record($share, 'share.card_generation_queued', metadata: [
                'generation_version' => $share->generation_version,
            ]);

            return $share->fresh();
        });

        GenerateChildIdentityShareCardsJob::dispatch($share->id, $share->generation_version)->afterCommit();

        return $share;
    }

    public function regenerate(ChildIdentityShare $share, ?User $actor = null): ChildIdentityShare
    {
        $share = DB::transaction(function () use ($share): ChildIdentityShare {
            $locked = ChildIdentityShare::query()->lockForUpdate()->findOrFail($share->id);
            $locked->load(['identityRequest', 'generationAttempt']);
            $locked->forceFill([
                'status' => 'generating',
                'generation_error' => null,
                'template_version' => $this->settings->templateVersion(),
                'generation_version' => $locked->generation_version + 1,
            ])->save();
            $locked->forceFill(['card_fingerprint' => $this->fingerprints->make($locked)])->save();
            $this->events->record($locked, 'share.card_generation_queued', metadata: [
                'generation_version' => $locked->generation_version,
                'actor' => $actor ? 'admin' : 'customer',
            ]);

            return $locked->fresh();
        });

        GenerateChildIdentityShareCardsJob::dispatch($share->id, $share->generation_version)->afterCommit();

        return $share;
    }

    public function revoke(ChildIdentityShare $share, ?User $actor = null): ChildIdentityShare
    {
        $share->forceFill([
            'share_enabled' => false,
            'status' => 'revoked',
            'revoked_at' => now(),
        ])->save();
        $this->events->record($share, 'share.revoked', metadata: ['actor' => $actor ? 'admin' : 'customer']);

        return $share->fresh();
    }

    public function reenable(ChildIdentityShare $share, ?User $actor = null): ChildIdentityShare
    {
        $hasCards = collect(ChildIdentityShare::VARIANTS)
            ->every(fn (string $variant): bool => filled($share->cardPath($variant))
                && Storage::disk($share->card_disk)->exists($share->cardPath($variant)));

        $share->forceFill([
            'share_enabled' => true,
            'status' => $hasCards ? 'ready' : 'generating',
            'revoked_at' => null,
        ])->save();
        $this->events->record($share, 'share.reenabled', metadata: ['actor' => $actor ? 'admin' : 'customer']);

        if (! $hasCards) {
            return $this->regenerate($share, $actor);
        }

        return $share->fresh();
    }

    public function removePublicCards(ChildIdentityShare $share, User $actor): ChildIdentityShare
    {
        foreach (ChildIdentityShare::VARIANTS as $variant) {
            if ($path = $share->cardPath($variant)) {
                Storage::disk($share->card_disk)->delete($path);
            }
        }

        $share->forceFill([
            'share_enabled' => false,
            'status' => 'cards_removed',
            'feed_card_path' => null,
            'story_card_path' => null,
            'og_card_path' => null,
            'cards_generated_at' => null,
            'revoked_at' => now(),
        ])->save();
        $this->events->record($share, 'share.cards_removed', metadata: ['actor' => 'admin']);
        AdminActivityLogger::log(
            'child_identity_share.cards_removed',
            'حذف بطاقات مشاركة هوية طفل العامة.',
            $share,
            ['identity_id' => $share->child_identity_request_id],
            $actor,
        );

        return $share->fresh();
    }

    private function assertShareable(ChildIdentityRequest $identity, ChildIdentityGenerationAttempt $attempt): void
    {
        if ($attempt->child_identity_request_id !== $identity->id
            || $attempt->status !== 'succeeded'
            || blank($attempt->output_storage_path)
            || $identity->approved_attempt_id !== $attempt->id) {
            throw ValidationException::withMessages([
                'attempt' => 'يمكن مشاركة محاولة ناجحة ومعتمدة فقط.',
            ]);
        }
    }

    private function sessionHash(Request $request): ?string
    {
        $sessionId = $request->session()->getId();

        return $sessionId ? hash_hmac('sha256', $sessionId, (string) config('app.key')) : null;
    }

    private function ipHash(Request $request): ?string
    {
        return $request->ip()
            ? hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'))
            : null;
    }
}
