<?php

namespace App\Jobs;

use App\Models\StudioCoverGeneration;
use App\Models\User;
use App\Services\RemoteStudio\StudioCoverService;
use App\Support\AdminActivityLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateStudioCover implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public string $generationId) {}

    public function handle(StudioCoverService $service): void
    {
        // No automatic replay after a crash: an upstream request may have been billed.
        if (! StudioCoverGeneration::whereKey($this->generationId)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()])) {
            return;
        }
        $generation = StudioCoverGeneration::findOrFail($this->generationId);
        try {
            $service->run($generation);
            AdminActivityLogger::log('studio.cover.completed', properties: ['generation_id' => $generation->id], admin: User::find($generation->user_id));
        } catch (Throwable) {
            $generation->forceFill(['status' => 'failed', 'error_code' => 'GENERATION_FAILED', 'output' => null])->save();
            AdminActivityLogger::log('studio.cover.failed', properties: ['generation_id' => $generation->id], admin: User::find($generation->user_id));
        } finally {
            if ($generation->reference_path) {
                Storage::disk('studio_private')->delete($generation->reference_path);
            }
            $generation->forceFill(['reference_path' => null])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $generation = StudioCoverGeneration::find($this->generationId);
        if (! $generation || $generation->status === 'completed') {
            return;
        }
        if ($generation->reference_path) {
            Storage::disk('studio_private')->delete($generation->reference_path);
        }
        $generation->forceFill(['status' => 'failed', 'error_code' => 'GENERATION_INTERRUPTED', 'reference_path' => null, 'output' => null])->save();
    }
}
