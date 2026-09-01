<?php

namespace App\Services\Notifications;

use App\DTOs\Notifications\NotificationMessage;
use App\Models\Order;
use App\Models\ProductionProject;
use App\Models\SceneGenerationJob;
use App\Support\AppDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NotificationMessageBuilder
{
    public function __construct(
        private readonly OrderCreatedNotificationMessage $orderCreatedMessage,
    ) {}

    public function build(string $eventKey, string $channelType, string $recipient, ?Model $notifiable, array $context = [], ?string $severity = null): NotificationMessage
    {
        $definition = config("admin_notifications.events.{$eventKey}", []);
        $severity ??= $definition['severity'] ?? 'info';
        $body = match ($eventKey) {
            'order.created' => $this->orderCreatedMessage->build($notifiable instanceof Order ? $notifiable : null),
            'production.project.created' => $this->productionCreated($this->projectFrom($notifiable)),
            'production.project.started' => $this->productionStarted($this->projectFrom($notifiable)),
            'production.project.completed' => $this->productionCompleted($this->projectFrom($notifiable)),
            'production.project.stuck' => $this->productionStuck($this->projectFrom($notifiable), $context),
            'production.project.budget_exceeded' => $this->productionBudget($this->projectFrom($notifiable), $context),
            'ai.generation.completed' => $this->aiCompleted($this->jobFrom($notifiable)),
            'ai.generation.failed' => $this->aiFailed($this->jobFrom($notifiable)),
            'ai.generation.stuck' => $this->aiStuck($this->jobFrom($notifiable), $context),
            'ai.generation.budget_exceeded' => $this->aiBudget($this->jobFrom($notifiable), $context),
            default => $this->generic($eventKey, $context),
        };

        return new NotificationMessage(
            eventKey: $eventKey,
            channelType: $channelType,
            recipient: $recipient,
            body: $this->sanitizeMessage($body),
            severity: $severity,
            subject: $definition['name_en'] ?? $eventKey,
            notifiableType: $notifiable ? $notifiable::class : null,
            notifiableId: $notifiable?->getKey(),
            payload: [
                'event_key' => $eventKey,
                'severity' => $severity,
                'dedupe_key' => $context['dedupe_key'] ?? null,
            ],
        );
    }

    private function productionCreated(?ProductionProject $project): string
    {
        $project?->loadMissing('order.story');

        return implode("\n", array_filter([
            '🎬 تم إرسال طلب إلى Production Studio',
            '',
            'رقم الطلب: '.$this->safe($project?->order?->order_number),
            'الطفل: '.$this->safe($project?->order?->child_name),
            'القصة: '.$this->safe($project?->order?->story?->title),
            'المشروع: #'.$this->safe($project?->id),
            'المرحلة الحالية: '.$this->safe($project?->stageLabel()),
            '',
            'فتح المشروع:',
            $project ? route('admin.production-studio.show', $project) : null,
        ], fn ($line): bool => filled($line)));
    }

    private function productionStarted(?ProductionProject $project): string
    {
        $project?->loadMissing('order.story');

        return implode("\n", array_filter([
            '▶️ بدأ مشروع Production Studio',
            '',
            'رقم الطلب: '.$this->safe($project?->order?->order_number),
            'الطفل: '.$this->safe($project?->order?->child_name),
            'القصة: '.$this->safe($project?->order?->story?->title),
            'المشروع: #'.$this->safe($project?->id),
            'المرحلة الحالية: '.$this->safe($project?->stageLabel()),
            '',
            'فتح المشروع:',
            $project ? route('admin.production-studio.show', $project) : null,
        ], fn ($line): bool => filled($line)));
    }

    private function productionCompleted(?ProductionProject $project): string
    {
        $project?->loadMissing(['order.story', 'generationJobs']);
        $summary = $project?->aiCostSummary() ?? ['actual' => '0.0000', 'attempts' => 0];

        return implode("\n", array_filter([
            '✅ اكتمل مشروع Production Studio',
            '',
            'رقم الطلب: '.$this->safe($project?->order?->order_number),
            'الطفل: '.$this->safe($project?->order?->child_name),
            'القصة: '.$this->safe($project?->order?->story?->title),
            'التكلفة الفعلية: $'.$this->safe($summary['actual'] ?? '0.0000'),
            'عدد محاولات AI: '.$this->safe($summary['attempts'] ?? 0),
            'آخر مرحلة: '.$this->safe($project?->stageLabel()),
            '',
            'فتح المشروع:',
            $project ? route('admin.production-studio.show', $project) : null,
        ], fn ($line): bool => filled($line)));
    }

    private function productionStuck(?ProductionProject $project, array $context): string
    {
        $project?->loadMissing('order');
        $age = $context['stuck_for_human'] ?? ($context['stuck_for_minutes'] ?? '?').' دقيقة';

        return implode("\n", array_filter([
            '⚠️ مشروع Production Studio متوقف',
            '',
            'المشروع: #'.$this->safe($project?->id),
            'رقم الطلب: '.$this->safe($project?->order?->order_number),
            'المرحلة: '.$this->safe($project?->stageLabel()),
            'آخر تحديث: '.$this->safe(AppDateTime::format($project?->updated_at, 'Y-m-d H:i')),
            'السبب المحتمل: لم يحدث تقدم منذ '.$this->safe($age),
            '',
            'فتح المشروع:',
            $project ? route('admin.production-studio.show', $project) : null,
        ], fn ($line): bool => filled($line)));
    }

    private function productionBudget(?ProductionProject $project, array $context): string
    {
        $project?->loadMissing('order');
        $title = ($context['threshold'] ?? null) === '80_percent'
            ? '⚠️ اقتربت تكلفة الإنتاج من الميزانية'
            : '🚨 تم تجاوز ميزانية الإنتاج';

        return implode("\n", array_filter([
            $title,
            '',
            'المشروع: #'.$this->safe($project?->id),
            'رقم الطلب: '.$this->safe($project?->order?->order_number),
            'الميزانية المحددة: $'.$this->safe($context['budget_usd'] ?? null),
            'التكلفة الحالية: $'.$this->safe($context['current_cost_usd'] ?? null),
            'محاولات AI: '.$this->safe($context['attempts'] ?? $project?->generationJobs()->count()),
            '',
            'فتح المشروع:',
            $project ? route('admin.production-studio.show', $project) : null,
        ], fn ($line): bool => filled($line)));
    }

    private function aiCompleted(?SceneGenerationJob $job): string
    {
        $job?->loadMissing(['project.order', 'scene', 'model']);

        return implode("\n", array_filter([
            '✅ اكتملت مهمة توليد AI',
            '',
            'المشروع: #'.$this->safe($job?->production_project_id),
            'النوع: '.$this->safe($job?->job_type),
            'المشهد: '.$this->sceneLabel($job),
            'الموديل: '.$this->safe($job?->model?->code),
            'التكلفة: $'.$this->safe($job?->actual_cost ?? $job?->estimated_cost),
            '',
            'فتح المشروع:',
            $job?->project ? route('admin.production-studio.show', $job->project) : null,
        ], fn ($line): bool => filled($line)));
    }

    private function aiFailed(?SceneGenerationJob $job): string
    {
        $job?->loadMissing(['project.order', 'scene', 'model']);

        return implode("\n", array_filter([
            '❌ فشل توليد صورة',
            '',
            'المشروع: #'.$this->safe($job?->production_project_id),
            'النوع: '.$this->safe($job?->job_type),
            'المشهد: '.$this->sceneLabel($job),
            'الموديل: '.$this->safe($job?->model?->code),
            'الخطأ: '.$this->safeError($job?->error_message),
            '',
            'فتح المشروع:',
            $job?->project ? route('admin.production-studio.show', $job->project) : null,
        ], fn ($line): bool => filled($line)));
    }

    private function aiStuck(?SceneGenerationJob $job, array $context): string
    {
        $job?->loadMissing(['project', 'scene', 'model']);
        $age = $context['stuck_for_human'] ?? ($context['stuck_for_minutes'] ?? '?').' دقيقة';

        return implode("\n", array_filter([
            '⚠️ مهمة توليد AI متوقفة',
            '',
            'المشروع: #'.$this->safe($job?->production_project_id),
            'المهمة: #'.$this->safe($job?->id),
            'النوع: '.$this->safe($job?->job_type),
            'الحالة: '.$this->safe($job?->status),
            'الموديل: '.$this->safe($job?->model?->code),
            'السبب المحتمل: لم يحدث تقدم منذ '.$this->safe($age),
            '',
            'فتح المشروع:',
            $job?->project ? route('admin.production-studio.show', $job->project) : null,
        ], fn ($line): bool => filled($line)));
    }

    private function aiBudget(?SceneGenerationJob $job, array $context): string
    {
        $job?->loadMissing(['project', 'scene', 'model']);

        return implode("\n", array_filter([
            '🚨 تم تجاوز حد تكلفة مهمة AI',
            '',
            'المشروع: #'.$this->safe($job?->production_project_id),
            'المهمة: #'.$this->safe($job?->id),
            'النوع: '.$this->safe($job?->job_type),
            'المشهد: '.$this->sceneLabel($job),
            'الموديل: '.$this->safe($job?->model?->code),
            'الحد المحدد: $'.$this->safe($context['threshold_usd'] ?? null),
            'التكلفة الحالية: $'.$this->safe($context['current_cost_usd'] ?? null),
            '',
            'فتح المشروع:',
            $job?->project ? route('admin.production-studio.show', $job->project) : null,
        ], fn ($line): bool => filled($line)));
    }

    private function generic(string $eventKey, array $context): string
    {
        return $this->safe($context['message'] ?? $eventKey);
    }

    private function projectFrom(?Model $notifiable): ?ProductionProject
    {
        if ($notifiable instanceof ProductionProject) {
            return $notifiable;
        }

        if ($notifiable instanceof SceneGenerationJob) {
            return $notifiable->project;
        }

        return null;
    }

    private function jobFrom(?Model $notifiable): ?SceneGenerationJob
    {
        return $notifiable instanceof SceneGenerationJob ? $notifiable : null;
    }

    private function sceneLabel(?SceneGenerationJob $job): string
    {
        if (! $job?->scene) {
            return 'غير محدد';
        }

        return trim('#'.$job->scene->scene_number.' '.$job->scene->title);
    }

    private function safe(mixed $value): string
    {
        return Str::limit($this->sanitizeText((string) ($value ?? 'غير محدد')), 180, '...');
    }

    private function safeError(?string $value): string
    {
        return Str::limit($this->sanitizeText((string) ($value ?: 'AI generation failed.')), 260, '...');
    }

    private function sanitizeMessage(string $message): string
    {
        return Str::limit($this->sanitizeText($message), 3500, '...');
    }

    private function sanitizeText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/Bearer\s+[A-Za-z0-9_\-:.]+/', 'Bearer [redacted]', $text) ?: '';
        $text = preg_replace('/Key\s+[A-Za-z0-9_\-:.]+/', 'Key [redacted]', $text) ?: '';
        $text = preg_replace('/bot[0-9]+:[A-Za-z0-9_\-]+/', 'bot[redacted]', $text) ?: '';
        $text = preg_replace('/\b[0-9]{5,}:[A-Za-z0-9_\-]+\b/', '[redacted-token]', $text) ?: '';
        $text = preg_replace('/[A-Za-z0-9_\-:.]*(secret|token|api_key|apikey)[A-Za-z0-9_\-:.]*/i', '[redacted]', $text) ?: '';

        return trim($text);
    }
}
