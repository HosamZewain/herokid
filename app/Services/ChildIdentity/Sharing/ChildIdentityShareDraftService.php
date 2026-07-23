<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityGenerationAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChildIdentityShareDraftService
{
    public function __construct(
        private readonly ChildIdentityShareCardGenerator $generator,
        private readonly ChildIdentityShareFingerprint $fingerprints,
    ) {}

    public function prepare(ChildIdentityGenerationAttempt $attempt): ChildIdentityGenerationAttempt
    {
        $attempt = DB::transaction(function () use ($attempt): ChildIdentityGenerationAttempt {
            $locked = ChildIdentityGenerationAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->status !== 'succeeded' || blank($locked->output_storage_path)) {
                throw new \RuntimeException('A successful identity output is required before preparing share cards.');
            }

            if (blank($locked->share_draft_token)) {
                $locked->forceFill(['share_draft_token' => Str::random(64)])->save();
            }

            return $locked->fresh(['identityRequest']);
        });

        $fingerprint = $this->fingerprints->makeForAttempt($attempt);
        if ($this->cardsExist($attempt)
            && hash_equals((string) $attempt->share_card_fingerprint, $fingerprint)) {
            return $attempt;
        }

        $paths = $this->generator->generateDraft($attempt);

        return DB::transaction(function () use ($attempt, $fingerprint, $paths): ChildIdentityGenerationAttempt {
            $locked = ChildIdentityGenerationAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->status !== 'succeeded'
                || $locked->output_checksum !== $attempt->output_checksum
                || $locked->share_draft_token !== $attempt->share_draft_token) {
                collect($paths)->each(fn (string $path) => Storage::disk('local')->delete($path));

                return $locked;
            }

            $locked->forceFill([
                'share_feed_card_path' => $paths['feed'],
                'share_story_card_path' => $paths['story'],
                'share_og_card_path' => $paths['og'],
                'share_card_fingerprint' => $fingerprint,
                'share_cards_generated_at' => now(),
            ])->save();

            return $locked->fresh(['identityRequest']);
        });
    }

    private function cardsExist(ChildIdentityGenerationAttempt $attempt): bool
    {
        return collect(['feed', 'story', 'og'])->every(function (string $variant) use ($attempt): bool {
            $path = $attempt->getAttribute("share_{$variant}_card_path");

            return filled($path) && Storage::disk('local')->exists($path);
        });
    }
}
