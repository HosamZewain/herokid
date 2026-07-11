<?php

namespace App\Services\ProductionStudio;

use App\Jobs\AdvanceProductionAutomationRun;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionProject;
use App\Models\User;
use App\Support\ProductionAutomation;
use App\Support\ProductionStudio;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductionAutomationRunService
{
    public function __construct(
        private readonly ProductionAutomationPreflightService $preflight,
        private readonly ProductionAutomationStateMachine $stateMachine,
    ) {}

    public function start(ProductionProject $project, array $options, User $actor): ProductionAutomationRun
    {
        if (! ProductionAutomation::enabled()) {
            throw new RuntimeException('Production Studio automation is disabled.');
        }

        $preflight = $this->preflight->inspect($project, $options);
        if (! $preflight['ok']) {
            throw new RuntimeException(implode(' ', $preflight['blockers']));
        }

        $hardBudget = (float) ($options['hard_budget'] ?? 0);
        if ($hardBudget + 0.00001 < (float) $preflight['required_minimum_budget']) {
            throw new RuntimeException('Hard budget must be at least the preflight base estimate.');
        }

        try {
            $run = DB::transaction(function () use ($project, $options, $actor, $preflight, $hardBudget): ProductionAutomationRun {
                ProductionProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

                $active = ProductionAutomationRun::query()
                    ->where('active_project_id', $project->id)
                    ->lockForUpdate()
                    ->first();

                if ($active) {
                    throw new RuntimeException('This Production Studio project already has an active automation run.');
                }

                $run = ProductionAutomationRun::create([
                    'production_project_id' => $project->id,
                    'active_project_id' => $project->id,
                    'status' => ProductionAutomation::STATUS_QUEUED,
                    'current_stage' => 'preflight',
                    'current_step_key' => 'preflight',
                    'base_estimated_cost' => $preflight['base_estimated_cost'],
                    'retry_exposure_estimate' => $preflight['retry_exposure_estimate'],
                    'hard_budget' => number_format($hardBudget, 4, '.', ''),
                    'currency' => $preflight['currency'],
                    'options_snapshot_json' => $this->sanitizeOptions(($preflight['options_snapshot'] ?? $options) + [
                        'hard_budget' => $hardBudget,
                    ]),
                    'pricing_snapshot_json' => $preflight['pricing_snapshot'] + ['models' => $preflight['models']],
                    'blockers_json' => [],
                    'started_by_user_id' => $actor->id,
                    'last_heartbeat_at' => now(),
                    'last_transition_at' => now(),
                ]);

                $this->seedSteps($run);
                $this->stateMachine->transitionStep(
                    $run->steps()->where('step_key', 'preflight')->firstOrFail(),
                    ProductionAutomation::STEP_COMPLETED,
                    [
                        'approval_type' => 'automatic',
                        'validation_summary_json' => [
                            'photo_count' => $preflight['photo_count'],
                            'warnings' => $preflight['warnings'],
                        ],
                    ],
                    $actor,
                    'preflight'
                );

                ProductionStudio::log($project, 'automation.created', 'تم إنشاء دورة إنتاج تلقائي.', [
                    'run_id' => $run->id,
                    'hard_budget' => $run->hard_budget,
                    'base_estimated_cost' => $run->base_estimated_cost,
                    'scene_concurrency' => $preflight['scene_concurrency'],
                ], $actor);

                return $run->fresh(['steps', 'project']);
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062
                && str_contains($exception->getMessage(), 'production_automation_runs_one_active_project_unique')) {
                throw new RuntimeException('This Production Studio project already has an active automation run.', previous: $exception);
            }

            throw $exception;
        }

        AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();

        return $run;
    }

    public function seedSteps(ProductionAutomationRun $run): void
    {
        $run->loadMissing('project.scenes');
        $scenesByNumber = $run->project->scenes->keyBy('scene_number');

        foreach (ProductionAutomation::stepDefinitions() as $definition) {
            $scene = isset($definition['scene_number']) ? $scenesByNumber->get($definition['scene_number']) : null;

            $run->steps()->firstOrCreate(
                ['step_key' => $definition['step_key']],
                [
                    'production_project_id' => $run->production_project_id,
                    'production_scene_id' => $scene?->id,
                    'name' => $definition['name'],
                    'sequence' => $definition['sequence'],
                    'stage' => $definition['stage'],
                    'status' => ProductionAutomation::STEP_PENDING,
                    'weight' => number_format((float) $definition['weight'], 4, '.', ''),
                    'attempt_limit' => $definition['attempt_limit'],
                    'run_version' => (int) ($run->version ?: 1),
                ]
            );
        }
    }

    private function sanitizeOptions(array $options): array
    {
        unset($options['provider_secret'], $options['api_key'], $options['private_prompt']);

        return $options;
    }
}
