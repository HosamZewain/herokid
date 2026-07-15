<?php

namespace App\Services\Notifications;

use App\Models\ProductionProject;
use App\Models\SceneGenerationJob;
use App\Models\Setting;

class NotificationStuckChecker
{
    public function __construct(
        private readonly AdminNotificationDispatcher $notifications,
        private readonly NotificationSettings $settings,
    ) {}

    public function run(): array
    {
        $this->settings->ensureDefaults();

        $productionCount = $this->checkProductionProjects();
        $aiCount = $this->checkAiJobs();

        Setting::query()->updateOrCreate(
            ['key' => 'notification_last_stuck_check_run_at'],
            ['value' => now()->toIso8601String()]
        );

        return [
            'production_projects' => $productionCount,
            'ai_jobs' => $aiCount,
        ];
    }

    private function checkProductionProjects(): int
    {
        $threshold = max(1, $this->settings->int('notification_production_stuck_after_minutes', 120));
        $repeatAfter = max(1, $this->settings->int('notification_repeat_stuck_alert_after_minutes', 180));
        $cutoff = now()->subMinutes($threshold);
        $count = 0;

        ProductionProject::query()
            ->with(['order.story', 'activityLogs' => fn ($query) => $query->latest()->limit(1)])
            ->whereIn('status', ['in_progress', 'waiting_review', 'approved', 'ready_for_print'])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->chunkById(50, function ($projects) use ($threshold, $repeatAfter, &$count): void {
                foreach ($projects as $project) {
                    $latestActivity = $project->activityLogs->first();

                    if ($latestActivity && $latestActivity->created_at && $latestActivity->created_at->gt(now()->subMinutes($threshold))) {
                        continue;
                    }

                    $minutes = max(0, (int) $project->updated_at?->diffInMinutes(now()));
                    $this->notifications->dispatchSafely('production.project.stuck', $project, [
                        'dedupe_key' => 'production.project.stuck:'.$project->id.':'.$project->status.':'.$project->current_stage,
                        'status' => $project->status,
                        'stuck_for_minutes' => $minutes,
                        'stuck_for_human' => $this->humanDuration($minutes),
                        'allow_repeat_after_minutes' => $repeatAfter,
                    ], 'warning');
                    $count++;
                }
            });

        return $count;
    }

    private function checkAiJobs(): int
    {
        $threshold = max(1, $this->settings->int('notification_ai_job_stuck_after_minutes', 20));
        $repeatAfter = max(1, $this->settings->int('notification_repeat_stuck_alert_after_minutes', 180));
        $cutoff = now()->subMinutes($threshold);
        $count = 0;

        SceneGenerationJob::query()
            ->with(['project.order', 'scene', 'model'])
            ->whereIn('status', ['queued', 'processing'])
            ->where(function ($query) use ($cutoff) {
                $query->where(function ($inner) use ($cutoff) {
                    $inner->whereNotNull('heartbeat_at')->where('heartbeat_at', '<=', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('heartbeat_at')->where('updated_at', '<=', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('heartbeat_at')->whereNull('updated_at')->where('created_at', '<=', $cutoff);
                });
            })
            ->orderBy('updated_at')
            ->chunkById(50, function ($jobs) use ($repeatAfter, &$count): void {
                foreach ($jobs as $job) {
                    $lastActivity = $job->heartbeat_at ?? $job->updated_at ?? $job->created_at;
                    $minutes = max(0, (int) $lastActivity?->diffInMinutes(now()));
                    $this->notifications->dispatchSafely('ai.generation.stuck', $job, [
                        'dedupe_key' => 'ai.generation.stuck:'.$job->id.':'.$job->status,
                        'status' => $job->status,
                        'stuck_for_minutes' => $minutes,
                        'stuck_for_human' => $this->humanDuration($minutes),
                        'allow_repeat_after_minutes' => $repeatAfter,
                    ], 'warning');
                    $count++;
                }
            });

        return $count;
    }

    private function humanDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' دقيقة';
        }

        $hours = (int) floor($minutes / 60);
        $remaining = $minutes % 60;

        return trim($hours.' ساعة'.($remaining > 0 ? ' و '.$remaining.' دقيقة' : ''));
    }
}
