<?php

namespace App\Services\CodexScenes;

use App\Models\CodexSceneRun;
use Carbon\CarbonImmutable;

class SceneTiming
{
    /** Never invent a worker clock for an older attempt or an interrupted worker. */
    public static function validated(mixed $data, CodexSceneRun $run): ?array
    {
        if (! is_array($data)) {
            return null;
        }
        foreach (['started_at', 'elapsed_seconds'] as $field) {
            if (! isset($data[$field]) || ! is_numeric($data[$field]) || ! is_finite((float) $data[$field])) {
                return null;
            }
        }
        $start = (float) $data['started_at'];
        $elapsed = (float) $data['elapsed_seconds'];
        if ($start < $run->created_at->timestamp || $start > now()->timestamp + 5 || $elapsed < 0 || $elapsed > ($run->production_kind === 'coloring_book' ? max(86400, ($run->scene_count ?? 13) * 2500) : 86400)) {
            return null;
        }
        $result = ['started_at' => $start, 'elapsed_seconds' => $elapsed];
        if (isset($data['finished_at'])) {
            if (! is_numeric($data['finished_at']) || ! is_finite((float) $data['finished_at'])) {
                return null;
            }
            $end = (float) $data['finished_at'];
            if ($end < $start || $end > now()->timestamp + 5 || abs(($end - $start) - $elapsed) > 5) {
                return null;
            }
            $result['finished_at'] = $end;
        }
        if ($run->timing && (abs(($run->timing['started_at'] ?? 0) - $start) > 0.001
            || ($run->timing['elapsed_seconds'] ?? 0) > $elapsed
            || (isset($run->timing['finished_at']) && $result !== $run->timing))) {
            return null;
        }

        return $result;
    }

    public static function describe(CodexSceneRun $run): array
    {
        $t = $run->timing ?? [];
        $start = isset($t['started_at']) ? CarbonImmutable::createFromTimestampUTC($t['started_at']) : $run->started_at;
        $end = isset($t['finished_at']) ? CarbonImmutable::createFromTimestampUTC($t['finished_at']) : $run->finished_at;
        $live = in_array($run->status, ['running', 'queued', 'preparing', 'waiting_slot'], true);
        $seconds = isset($t['finished_at']) ? (float) $t['elapsed_seconds']
            : ($start && ($end || $live) ? max(0, $start->diffInSeconds($end ?? now(), false)) : null);
        $wait = $start ? max(0, $run->created_at->diffInSeconds($start, false))
            : ($run->status === 'waiting_slot' || $run->status === 'queued' || $run->status === 'preparing' ? max(0, $run->created_at->diffInSeconds(now(), false)) : null);

        $history = $run->snapshot['resume_history'] ?? [];
        $total = $seconds;
        $totalExact = isset($t['finished_at']);
        foreach ($history as $previous) {
            $total = $total !== null && ($previous['seconds'] ?? null) !== null ? $total + $previous['seconds'] : null;
            $totalExact = $totalExact && ($previous['source'] ?? '') === 'worker';
        }

        return ['resumed' => count($history) > 0, 'story_seconds' => $total, 'story_duration' => self::format($total), 'story_exact' => $totalExact,
            'start' => $start, 'end' => $end, 'seconds' => $seconds, 'wait_seconds' => $wait,
            'source' => $t ? 'worker' : 'observed', 'live' => $live, 'duration' => self::format($seconds), 'wait' => self::format($wait)];
    }

    public static function format(?float $seconds): string
    {
        if ($seconds === null) {
            return 'غير متاح';
        }
        $s = max(0, (int) round($seconds));

        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }

    public static function summary(): array
    {
        $exact = [];
        $estimated = [];
        foreach (CodexSceneRun::where('status', 'completed')->where('production_kind', 'story')->select(['snapshot', 'timing', 'started_at', 'finished_at', 'created_at', 'status'])->cursor() as $run) {
            $value = self::describe($run);
            if ($value['story_seconds'] === null) {
                continue;
            }
            if ($value['story_exact']) {
                $exact[] = $value['story_seconds'];
            } else {
                $estimated[] = $value['story_seconds'];
            }
        }

        return ['count' => count($exact), 'average' => self::format($exact ? array_sum($exact) / count($exact) : null),
            'estimated_count' => count($estimated), 'estimated_average' => self::format($estimated ? array_sum($estimated) / count($estimated) : null)];
    }
}
