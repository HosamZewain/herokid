<?php

namespace App\Services\ProductionStudio;

use App\Models\ProductionAutomationRun;
use App\Support\ProductionAutomation;

class ProductionAutomationProgress
{
    public function percentage(ProductionAutomationRun $run): int
    {
        if ($run->status === ProductionAutomation::STATUS_COMPLETED) {
            return 100;
        }

        if ($run->status === ProductionAutomation::STATUS_FILES_READY) {
            return 95;
        }

        $steps = $run->relationLoaded('steps') ? $run->steps : $run->steps()->get();
        $completedWeight = $steps
            ->filter(fn ($step): bool => $step->isCompleteForProgress())
            ->sum(fn ($step): float => (float) $step->weight);

        return (int) min(95, max(0, round($completedWeight)));
    }

    public function timing(ProductionAutomationRun $run): array
    {
        $end = $run->completed_at ?? $run->cancelled_at ?? now();
        $wallClock = $run->created_at ? max(0, $run->created_at->diffInSeconds($end)) : 0;
        $active = (int) $run->active_seconds;
        $paused = (int) $run->paused_seconds;

        if ($run->last_transition_at) {
            $elapsed = max(0, $run->last_transition_at->diffInSeconds(now()));

            if ($run->status === ProductionAutomation::STATUS_RUNNING) {
                $active += $elapsed;
            } elseif (in_array($run->status, ProductionAutomation::pausedStatuses(), true)) {
                $paused += $elapsed;
            }
        }

        return [
            'wall_clock_seconds' => $wallClock,
            'active_seconds' => $active,
            'paused_seconds' => $paused,
            'provider_wait_seconds' => (int) $run->provider_wait_seconds,
        ];
    }

    public function formula(): array
    {
        return [
            'preflight' => 5,
            'story_preparation' => 15,
            'character_profile' => 10,
            'child_reference' => 10,
            'cover' => 5,
            'scenes_total' => 35,
            'layout_print' => 15,
            'final_proof' => 5,
            'files_ready_cap' => 95,
            'completed' => 100,
        ];
    }
}
