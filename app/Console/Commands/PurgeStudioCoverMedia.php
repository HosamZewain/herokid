<?php

namespace App\Console\Commands;

use App\Models\StudioCoverGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeStudioCoverMedia extends Command
{
    protected $signature = 'studio:purge-cover-media';

    protected $description = 'Remove expired temporary Studio cover media only; never order or settings assets.';

    public function handle(): int
    {
        $count = 0;
        StudioCoverGeneration::where('created_at', '<', now()->subHours((int) config('remote_studio.cover_result_hours')))
            ->where(fn ($q) => $q->whereNotNull('reference_path')->orWhereNotNull('output_path')->orWhereIn('status', ['queued', 'running']))
            ->orderBy('id')->chunkById(100, function ($generations) use (&$count): void {
                foreach ($generations as $generation) {
                    // Exact application-owned paths; no traversal or arbitrary DB path deletion.
                    foreach ([$generation->reference_path, $generation->output_path] as $path) {
                        if (is_string($path) && preg_match('~^covers/'.preg_quote($generation->id, '~').'/(reference|output\.(png|jpg|webp))$~D', $path)) {
                            Storage::disk('studio_private')->delete($path);
                        }
                    }
                    $generation->forceFill(['reference_path' => null, 'output_path' => null,
                        'status' => in_array($generation->status, ['queued', 'running'], true) ? 'failed' : $generation->status,
                        'error_code' => in_array($generation->status, ['queued', 'running'], true) ? 'GENERATION_INTERRUPTED' : $generation->error_code,
                        'output' => $generation->status === 'completed' ? $generation->output : null])->save();
                    $count++;
                }
            }, 'id');
        $this->info('Expired temporary Studio cover media cleared: '.$count);

        return self::SUCCESS;
    }
}
