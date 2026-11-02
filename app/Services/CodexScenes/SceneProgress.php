<?php

namespace App\Services\CodexScenes;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Services\Orders\AutomationActivity;

/** Worker protocol 4: bounded attempts, immutable outcomes, and explicit missing scenes. */
class SceneProgress
{
    /** MySQL JSON round-trips epoch doubles by one ULP; tolerate <1 microsecond only. */
    private static function sameTime(mixed $a, mixed $b): bool
    {
        return is_numeric($a) && is_numeric($b) && is_finite((float) $a) && is_finite((float) $b)
            && abs((float) $a - (float) $b) <= 0.000001;
    }

    private static function sameAttempt(array $a, array $b): bool
    {
        foreach (['started_at', 'finished_at'] as $key) {
            if (array_key_exists($key, $a) !== array_key_exists($key, $b)
                || (array_key_exists($key, $a) && ! self::sameTime($a[$key], $b[$key]))) {
                return false;
            }
            unset($a[$key], $b[$key]);
        }

        return $a == $b;
    }

    private static function sameEntry(array $a, array $b): bool
    {
        $attempts = $a['attempts'] ?? [];
        $next = $b['attempts'] ?? [];
        if (! is_array($next) || count($attempts) !== count($next)) {
            return false;
        }
        foreach ($attempts as $i => $attempt) {
            if (! isset($next[$i]) || ! self::sameAttempt($attempt, $next[$i])) {
                return false;
            }
        }
        unset($a['attempts'], $b['attempts']);

        return $a == $b;
    }

    public const QUALITY = ['invalid_dimensions', 'invalid_png', 'oversized_scene', 'duplicate_scene'];

    public const FAILURES = [...self::QUALITY, 'content_review', 'generation_result_uncertain'];

    public static function collect(CodexSceneRun $run, array $state, array $saved, Order $order): void
    {
        if ($run->protocol < 4) {
            if (($state['status'] ?? '') === 'needs_manual') {
                throw new \RuntimeException('Partial result requires protocol 4');
            }

            return;
        }
        $count = $run->scene_count ?? 13;
        $progress = $state['scene_progress'] ?? [];
        $previous = $run->scene_progress ?? [];
        $terminal = in_array($state['status'] ?? '', ['completed', 'needs_manual'], true);
        if (! is_array($progress) || count($progress) > $count || ($terminal && count($progress) !== $count)) {
            throw new \RuntimeException('Invalid scene progress');
        }
        foreach ($previous as $number => $old) {
            if (! isset($progress[$number])) {
                throw new \RuntimeException('Scene progress disappeared');
            }
        }
        foreach ($progress as $number => $entry) {
            if (! ctype_digit((string) $number) || (int) $number < 1 || (int) $number > $count || ! is_array($entry)
                || ! in_array($entry['status'] ?? '', ['running', 'succeeded', 'failed', 'retained'], true)
                || array_diff(array_keys($entry), ['status', 'code', 'attempts', 'previous_run_id'])
                || ! is_array($entry['attempts'] ?? null) || ! array_is_list($entry['attempts']) || count($entry['attempts']) > 2) {
                throw new \RuntimeException('Invalid scene entry');
            }
            $attempts = $entry['attempts'];
            $old = $previous[$number] ?? null;
            if ($old && ($old['status'] !== 'running' ? ! self::sameEntry($old, $entry) : count($old['attempts']) > count($attempts))) {
                throw new \RuntimeException('Scene outcome mutated');
            }
            foreach ($attempts as $index => $attempt) {
                if (! is_array($attempt) || array_diff(array_keys($attempt), ['number', 'status', 'code', 'started_at', 'finished_at']) || ($attempt['number'] ?? null) !== $index + 1
                    || ! in_array($attempt['status'] ?? '', ['running', 'succeeded', 'failed'], true)
                    || ! is_numeric($attempt['started_at'] ?? null) || $attempt['started_at'] < $run->created_at->timestamp - 5
                    || $attempt['started_at'] > time() + 300
                    || ($index && (($attempts[$index - 1]['status'] ?? '') !== 'failed' || ! in_array($attempts[$index - 1]['code'] ?? '', self::QUALITY, true)
                        || $attempt['started_at'] < ($attempts[$index - 1]['finished_at'] ?? PHP_INT_MAX)))) {
                    throw new \RuntimeException('Unsafe attempt sequence');
                }
                if ($attempt['status'] !== 'running' && (! is_numeric($attempt['finished_at'] ?? null)
                    || $attempt['finished_at'] < $attempt['started_at'] || $attempt['finished_at'] > time() + 300)) {
                    throw new \RuntimeException('Invalid attempt timing');
                }
                if ($attempt['status'] === 'failed' && ! in_array($attempt['code'] ?? '', self::FAILURES, true)) {
                    throw new \RuntimeException('Unknown scene failure');
                }
                $prior = $old['attempts'][$index] ?? null;
                if ($prior && ($prior['status'] !== 'running' ? ! self::sameAttempt($prior, $attempt) : ! self::sameTime($prior['started_at'], $attempt['started_at']))) {
                    throw new \RuntimeException('Attempt outcome mutated');
                }
            }
            $last = $attempts ? $attempts[array_key_last($attempts)] : null;
            if ($entry['status'] === 'retained') {
                if ($attempts || ! isset($run->snapshot['retained_scenes'][$number], $saved[$number])) {
                    throw new \RuntimeException('Invalid retained result');
                }
            } elseif (! $last) {
                $skip = collect($run->snapshot['skipped_scenes'] ?? [])->firstWhere('scene', (int) $number);
                if ($entry['status'] !== 'failed' || ($entry['code'] ?? '') !== 'content_review' || ! $skip
                    || ($entry['previous_run_id'] ?? '') !== ($run->snapshot['resume_from'] ?? null)) {
                    throw new \RuntimeException('Missing scene attempts');
                }
            } elseif ($entry['status'] === 'failed') {
                if ($last['status'] !== 'failed' || ($entry['code'] ?? '') !== $last['code']) {
                    throw new \RuntimeException('Failure does not match attempt');
                }
            } elseif ($entry['status'] === 'running' && ($terminal || ! in_array($last['status'], ['running', 'failed'], true)
                || ($last['status'] === 'failed' && (! in_array($last['code'], self::QUALITY, true) || count($attempts) >= 2)))) {
                throw new \RuntimeException('Invalid pending scene');
            } elseif ($entry['status'] === 'succeeded' && ($last['status'] !== 'succeeded' || ! isset($saved[$number]))) {
                throw new \RuntimeException('Success without verified image');
            }
            if (isset($saved[$number]) && ! in_array($entry['status'], ['succeeded', 'retained'], true)) {
                // State is published after PNG + metadata. Wait for the next atomic state read.
                throw new \RuntimeException('Worker image/state not yet consistent');
            }
            if ($terminal && $entry['status'] === 'running') {
                throw new \RuntimeException('Terminal run has a pending attempt');
            }
        }
        if ($terminal && (($state['status'] === 'completed') !== (count($saved) === $count))) {
            throw new \RuntimeException('Incomplete story cannot be completed');
        }
        // Only log once, under the run lock. Never publish the private CLI transcript or prompt.
        foreach ($progress as $number => $entry) {
            foreach ($entry['attempts'] as $index => $attempt) {
                $prior = $previous[$number]['attempts'][$index] ?? null;
                if (! $prior) {
                    AutomationActivity::record($order, 'codex_scene_attempt_started', 'بدء محاولة '.($index + 1).' للمشهد '.$number.' بواسطة Automation.',
                        ['run_id' => $run->id, 'scene' => (int) $number, 'attempt' => $index + 1, 'started_at' => $attempt['started_at']]);
                }
                if ($attempt['status'] !== 'running' && ($prior['status'] ?? null) !== $attempt['status']) {
                    AutomationActivity::record($order, 'codex_scene_attempt_finished', 'انتهاء محاولة '.($index + 1).' للمشهد '.$number.': '.($attempt['status'] === 'succeeded' ? 'نجحت' : (CodexSceneRun::ERRORS[$attempt['code']] ?? 'تحتاج مراجعة')),
                        ['run_id' => $run->id, 'scene' => (int) $number, 'attempt' => $index + 1, 'result' => $attempt['status'], 'code' => $attempt['code'] ?? null]);
                }
            }
        }
        $run->scene_progress = $progress;
    }
}
