<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChildIdentityShareDisplayService
{
    public function __construct(
        private readonly ChildIdentityShareDraftService $drafts,
        private readonly ChildIdentityShareManager $manager,
        private readonly ChildIdentityShareFingerprint $fingerprints,
    ) {}

    public function ensureCurrent(ChildIdentityRequest $identity, Request $request): void
    {
        $attempt = $identity->approvedAttempt;

        if (! $attempt || $attempt->status !== 'succeeded' || blank($attempt->output_storage_path)) {
            return;
        }

        $share = $identity->share;

        try {
            if ($this->canRefreshPublicShare($share, $attempt->id)) {
                $expectedFingerprint = $this->fingerprints->make($share);
                $isCurrent = filled($share->generated_fingerprint)
                    && hash_equals((string) $share->generated_fingerprint, $expectedFingerprint)
                    && $this->shareCardsExist($share);

                if (! $isCurrent) {
                    $share = $this->manager->createOrUpdate(
                        $identity,
                        $attempt,
                        $request,
                        (bool) $share->display_child_first_name,
                        true,
                        actorType: 'system',
                        actor: $request->user(),
                        generateImmediately: true,
                    );
                    $identity->setRelation('share', $share);
                }

                return;
            }

            $attempt = $this->drafts->prepare($attempt);
            $identity->setRelation('approvedAttempt', $attempt);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function canRefreshPublicShare(?ChildIdentityShare $share, int $attemptId): bool
    {
        return $share !== null
            && $share->generation_attempt_id === $attemptId
            && $share->status === 'ready'
            && $share->share_enabled;
    }

    private function shareCardsExist(ChildIdentityShare $share): bool
    {
        return collect(ChildIdentityShare::VARIANTS)->every(function (string $variant) use ($share): bool {
            $path = $share->cardPath($variant);

            return filled($path) && Storage::disk($share->card_disk ?: 'local')->exists($path);
        });
    }
}
