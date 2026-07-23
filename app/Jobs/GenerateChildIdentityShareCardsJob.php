<?php

namespace App\Jobs;

use App\Models\ChildIdentityShare;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareCardGenerator;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareEventService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GenerateChildIdentityShareCardsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $shareId,
        public readonly int $generationVersion,
    ) {}

    public function uniqueId(): string
    {
        return $this->shareId.':'.$this->generationVersion;
    }

    public function handle(
        ChildIdentityShareCardGenerator $generator,
        ChildIdentityShareEventService $events,
    ): void {
        $share = ChildIdentityShare::query()->find($this->shareId);
        if (! $share || $share->generation_version !== $this->generationVersion) {
            return;
        }

        $existing = collect(ChildIdentityShare::VARIANTS)
            ->every(fn (string $variant): bool => filled($share->cardPath($variant))
                && Storage::disk($share->card_disk)->exists($share->cardPath($variant)));

        if ($existing
            && $share->cards_generated_at
            && $share->generation_error === null
            && hash_equals((string) $share->card_fingerprint, (string) $share->generated_fingerprint)) {
            $share->forceFill(['status' => $share->share_enabled ? 'ready' : 'revoked'])->save();

            return;
        }

        try {
            $paths = $generator->generate($share, $this->generationVersion);

            DB::transaction(function () use ($paths, $events): void {
                $locked = ChildIdentityShare::query()->lockForUpdate()->find($this->shareId);
                if (! $locked || $locked->generation_version !== $this->generationVersion) {
                    foreach ($paths as $path) {
                        Storage::disk('local')->delete($path);
                    }

                    return;
                }

                $oldPaths = collect(ChildIdentityShare::VARIANTS)->map(fn (string $variant) => $locked->cardPath($variant))->filter();
                $locked->forceFill([
                    'status' => $locked->share_enabled ? 'ready' : 'revoked',
                    'feed_card_path' => $paths['feed'],
                    'story_card_path' => $paths['story'],
                    'og_card_path' => $paths['og'],
                    'cards_generated_at' => now(),
                    'generated_fingerprint' => $locked->card_fingerprint,
                    'generation_error' => null,
                ])->save();
                $oldPaths->reject(fn (string $path) => in_array($path, $paths, true))
                    ->each(fn (string $path) => Storage::disk($locked->card_disk)->delete($path));
                $events->record($locked, 'share.card_generation_succeeded', metadata: [
                    'generation_version' => $this->generationVersion,
                    'variants' => ChildIdentityShare::VARIANTS,
                ]);
            });
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($exception, $events): void {
                $locked = ChildIdentityShare::query()->lockForUpdate()->find($this->shareId);
                if (! $locked || $locked->generation_version !== $this->generationVersion) {
                    return;
                }
                $locked->forceFill([
                    'status' => 'failed',
                    'generation_error' => 'تعذر تجهيز صورة المشاركة. يمكنك إعادة المحاولة.',
                ])->save();
                $events->record($locked, 'share.card_generation_failed', metadata: [
                    'generation_version' => $this->generationVersion,
                    'error_class' => $exception::class,
                ]);
            });

            throw $exception;
        }
    }
}
