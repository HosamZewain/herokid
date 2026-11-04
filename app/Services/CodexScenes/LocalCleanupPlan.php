<?php

namespace App\Services\CodexScenes;

use App\Models\CodexSceneRun;
use App\Models\OrderAttachment;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Read-only S3 attestation. The isolated OS helper deletes only exact local image copies. */
class LocalCleanupPlan
{
    public const RETENTION_HOURS = 48;

    public function build(int $limit = 5): array
    {
        $cutoff = now()->subHours(self::RETENTION_HOURS);
        $plan = ['version' => 1, 'generated_at' => now()->timestamp, 'retention_seconds' => self::RETENTION_HOURS * 3600,
            'allow_generated_cache' => ! CodexSceneRun::where('active_slot', 1)->whereNotIn('status', ['waiting_slot', 'quality_review'])->exists(),
            'runs' => [], 'skipped' => []];
        foreach (CodexSceneRun::where('status', 'completed')->whereNull('active_slot')
            ->where('finished_at', '<=', $cutoff)->where('updated_at', '<=', $cutoff)->orderBy('finished_at')->cursor() as $run) {
            $receipt = config('codex_scenes.spool').'/outbox/'.$run->id.'/local-cleanup.json';
            if (is_file($receipt) && ! is_link($receipt)) {
                if (! is_readable($receipt) || filesize($receipt) > 65536) {
                    $plan['skipped'][] = ['id' => $run->id, 'reason' => 'local_receipt_requires_review'];

                    continue;
                }
                $saved = json_decode(file_get_contents($receipt), true);
                if (($saved['id'] ?? null) === $run->id && ($saved['local_images_removed'] ?? false)
                    && (($saved['shared_cache_done'] ?? false) || ! $plan['allow_generated_cache'])) {
                    continue;
                }
            }
            try {
                $plan['runs'][] = $this->attest($run);
            } catch (\Throwable) {
                // Never expose storage credentials, customer media paths or provider payloads.
                $plan['skipped'][] = ['id' => $run->id, 'reason' => 's3_or_output_verification_failed'];
            }
            if (count($plan['runs']) >= max(1, min(100, $limit))) {
                break;
            }
        }

        return $plan;
    }

    public function attest(CodexSceneRun $run): array
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $run->id) || $run->status !== 'completed' || $run->active_slot !== null
            || ! $run->finished_at || $run->finished_at->gt(now()->subHours(self::RETENTION_HOURS))
            || $run->updated_at->gt(now()->subHours(self::RETENTION_HOURS)) || $run->scene_count < 1
            || ! in_array($run->production_kind, ['story', 'coloring_book'], true)
            || count($run->scenes ?? []) !== $run->scene_count) {
            throw new RuntimeException('cleanup_ineligible');
        }
        $prefix = 'orders/'.$run->order_id.'/codex-scenes/'.$run->id.'/';
        $scenes = [];
        foreach (range(1, $run->scene_count) as $number) {
            $scene = $run->scenes[(string) $number] ?? [];
            $raw = $prefix.'scene-'.sprintf('%02d', $number).'.png';
            if (($scene['source_run_id'] ?? $run->id) !== $run->id
                || ! in_array($scene['path'] ?? '', [$raw, $prefix.'page-'.sprintf('%02d', $number).'-a4.png'], true)) {
                throw new RuntimeException('cleanup_source_mismatch');
            }
            $sourceSha = $scene['source_sha256'] ?? $scene['sha256'] ?? '';
            $this->verify($raw, $sourceSha);
            if ($scene['path'] !== $raw || ($scene['sha256'] ?? '') !== $sourceSha) {
                $this->verify($scene['path'], $scene['sha256'] ?? '');
            }
            if ($run->production_kind === 'story') {
                $attachment = OrderAttachment::find($scene['attachment_id'] ?? 0);
                if (! $attachment || $attachment->order_id !== $run->order_id || $attachment->disk !== 's3_private'
                    || $attachment->path !== $scene['path'] || $attachment->production_unit_key !== 'story:'.$run->order_id) {
                    throw new RuntimeException('cleanup_attachment_mismatch');
                }
            }
            $scenes[(string) $number] = $sourceSha;
        }
        if ($run->production_kind === 'coloring_book') {
            $pdf = $run->book_pdf ?? [];
            $attachment = OrderAttachment::find($pdf['attachment_id'] ?? 0);
            if (($pdf['status'] ?? '') !== 'ready' || ($pdf['complete'] ?? false) !== true || ($pdf['missing_pages'] ?? []) !== []
                || ! $attachment || $attachment->order_id !== $run->order_id || $attachment->disk !== 's3_private'
                || $attachment->production_unit_key !== ($run->snapshot['unit_key'] ?? null)
                || $attachment->path !== ($pdf['path'] ?? null) || ! preg_match('/\Abook-[a-f0-9]{64}\.pdf\z/', substr($attachment->path, strlen($prefix)))
                || ! str_starts_with($attachment->path, $prefix)) {
                throw new RuntimeException('cleanup_pdf_not_ready');
            }
            $this->verify($attachment->path, $pdf['sha256'] ?? '');
        }

        return ['id' => $run->id, 'revision' => $this->revision($run), 'order_id' => $run->order_id, 'scene_count' => $run->scene_count,
            'finished_at' => $run->finished_at->timestamp, 'protocol' => $run->protocol,
            'identity_checksum' => $run->snapshot['identity_checksum'] ?? null, 'scenes' => $scenes];
    }

    public function unchanged(array $entry): bool
    {
        $current = CodexSceneRun::find($entry['id']);

        return $current && $current->status === 'completed' && $current->active_slot === null
            && hash_equals($entry['revision'], $this->revision($current));
    }

    private function revision(CodexSceneRun $run): string
    {
        return hash('sha256', json_encode(array_map(fn ($field) => $run->getRawOriginal($field),
            ['status', 'active_slot', 'order_id', 'order_item_id', 'scene_count', 'scenes', 'book_pdf', 'finished_at', 'updated_at']), JSON_THROW_ON_ERROR));
    }

    private function verify(string $path, string $sha): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $sha)) {
            throw new RuntimeException('cleanup_checksum_missing');
        }
        $stream = Storage::disk('s3_private')->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('cleanup_object_missing');
        }
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            if (! hash_equals($sha, hash_final($context))) {
                throw new RuntimeException('cleanup_object_changed');
            }
        } finally {
            fclose($stream);
        }
    }
}
