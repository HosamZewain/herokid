<?php

namespace App\Support;

class ProductionAutomation
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED_RECOVERABLE = 'paused_recoverable';

    public const STATUS_PAUSED_BUDGET = 'paused_budget';

    public const STATUS_PAUSED_REVIEW = 'paused_review';

    public const STATUS_PROVIDER_FAILED = 'provider_failed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FILES_READY = 'files_ready';

    public const STATUS_COMPLETED = 'completed';

    public const STEP_PENDING = 'pending';

    public const STEP_QUEUED = 'queued';

    public const STEP_RUNNING = 'running';

    public const STEP_WAITING_REVIEW = 'waiting_review';

    public const STEP_COMPLETED = 'completed';

    public const STEP_SKIPPED = 'skipped';

    public const STEP_FAILED_RECOVERABLE = 'failed_recoverable';

    public const STEP_PROVIDER_FAILED = 'provider_failed';

    public const STEP_FAILED = 'failed';

    public const STEP_CANCELLED = 'cancelled';

    public static function enabled(): bool
    {
        return ProductionStudio::enabled() && (bool) config('production_studio.automation.enabled', false);
    }

    public static function activeStatuses(): array
    {
        return [
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
            self::STATUS_PAUSED_RECOVERABLE,
            self::STATUS_PAUSED_BUDGET,
            self::STATUS_PAUSED_REVIEW,
            self::STATUS_PROVIDER_FAILED,
            self::STATUS_FILES_READY,
        ];
    }

    public static function pausedStatuses(): array
    {
        return [
            self::STATUS_PAUSED_RECOVERABLE,
            self::STATUS_PAUSED_BUDGET,
            self::STATUS_PAUSED_REVIEW,
            self::STATUS_PROVIDER_FAILED,
        ];
    }

    public static function terminalStatuses(): array
    {
        return [
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_COMPLETED,
        ];
    }

    public static function progressCompleteStepStatuses(): array
    {
        return [
            self::STEP_COMPLETED,
            self::STEP_SKIPPED,
        ];
    }

    public static function stepDefinitions(): array
    {
        $steps = [
            [
                'step_key' => 'preflight',
                'name' => 'Preflight',
                'sequence' => 10,
                'stage' => 'preflight',
                'weight' => 5,
                'attempt_limit' => 1,
            ],
            [
                'step_key' => 'story_preparation',
                'name' => 'Story Preparation',
                'sequence' => 20,
                'stage' => 'story_preparation',
                'weight' => 15,
                'attempt_limit' => 1,
            ],
            [
                'step_key' => 'character_profile',
                'name' => 'Character Profile',
                'sequence' => 30,
                'stage' => 'character_profile',
                'weight' => 10,
                'attempt_limit' => 1,
            ],
            [
                'step_key' => 'child_reference',
                'name' => 'Child Reference',
                'sequence' => 40,
                'stage' => 'child_reference',
                'weight' => 10,
                'attempt_limit' => 3,
            ],
            [
                'step_key' => 'cover',
                'name' => 'Cover',
                'sequence' => 50,
                'stage' => 'cover',
                'weight' => 5,
                'attempt_limit' => (int) config('production_studio.automation.phase3.cover_attempt_limit', 3),
            ],
        ];

        foreach (range(1, 13) as $sceneNumber) {
            $steps[] = [
                'step_key' => 'scene_'.str_pad((string) $sceneNumber, 2, '0', STR_PAD_LEFT),
                'name' => 'Scene '.$sceneNumber,
                'sequence' => 60 + $sceneNumber,
                'stage' => 'scenes',
                'weight' => 35 / 13,
                'attempt_limit' => (int) config('production_studio.automation.phase3.scene_attempt_limit', 3),
                'scene_number' => $sceneNumber,
            ];
        }

        $steps[] = [
            'step_key' => 'layout_print',
            'name' => 'Layout and Print',
            'sequence' => 90,
            'stage' => 'layout_print',
            'weight' => 15,
            'attempt_limit' => 1,
        ];
        $steps[] = [
            'step_key' => 'final_proof',
            'name' => 'Final Proof',
            'sequence' => 100,
            'stage' => 'final_proof',
            'weight' => 5,
            'attempt_limit' => 1,
        ];

        return $steps;
    }
}
