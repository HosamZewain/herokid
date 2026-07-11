<?php

namespace App\Jobs;

use App\Models\ProductionPrintLayout;
use App\Services\ProductionStudio\ProductionAutomationLayoutValidator;
use App\Services\ProductionStudio\ProductionLayoutBuilder;
use App\Support\ProductionAutomation;
use App\Support\ProductionStudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateProductionLayoutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $layoutId) {}

    public function handle(ProductionLayoutBuilder $builder, ?ProductionAutomationLayoutValidator $validator = null): void
    {
        $validator ??= app(ProductionAutomationLayoutValidator::class);

        $layout = ProductionPrintLayout::with(['project.order', 'automationRun', 'automationAttempt'])->findOrFail($this->layoutId);

        if ($layout->isReady()) {
            if ($layout->automationRun) {
                AdvanceProductionAutomationRun::dispatch($layout->automationRun->id)->afterCommit();
            }

            return;
        }

        if ($layout->automationRun && $layout->automationRun->status !== ProductionAutomation::STATUS_RUNNING) {
            $layout->update([
                'status' => 'cancelled',
                'error_message' => 'Automation run was no longer active for layout generation.',
            ]);

            return;
        }

        $layout->update(['status' => 'processing', 'error_message' => null]);

        try {
            $result = $builder->generate($layout);
            $layout->update([
                'status' => 'validating',
                'settings_json' => $result['settings'],
                'manifest_json' => $result['manifest'],
                'reader_pdf_path' => $result['reader_pdf_path'],
                'print_pdf_path' => $result['print_pdf_path'],
                'manifest_path' => $result['manifest_path'],
                'proof_checklist_path' => $result['proof_checklist_path'],
                'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
            ]);

            $layout = $layout->fresh(['project.order.story', 'project.scenes.approvedFinalImage', 'project.assets', 'automationRun', 'automationAttempt']);
            $validation = $validator->validate($layout);

            if (! $validation['ok']) {
                throw new RuntimeException(implode(' ', $validation['errors']));
            }

            $manifest = $validator->enrichManifest($layout, $result['manifest'], $validation);
            Storage::disk('local')->put($result['manifest_path'], $validator->manifestCsv($manifest));
            $manifestContents = Storage::disk('local')->get($result['manifest_path']);
            $validation['files']['manifest']['sha256'] = hash('sha256', $manifestContents);
            $validation['files']['manifest']['bytes'] = strlen($manifestContents);
            $validation['output_fingerprint'] = $validator->outputFingerprint($layout, $validation);
            $manifest = $validator->enrichManifest($layout, $result['manifest'], $validation);
            Storage::disk('local')->put($result['manifest_path'], $validator->manifestCsv($manifest));

            $layout->update([
                'status' => 'ready',
                'manifest_json' => $manifest,
                'output_fingerprint' => $validation['output_fingerprint'],
                'generated_at' => now(),
            ]);

            $layout->automationAttempt?->update([
                'status' => 'approved',
                'output_fingerprint' => $validation['output_fingerprint'],
                'validation_result_json' => $validation,
                'approval_type' => 'automatic',
                'completed_at' => now(),
                'heartbeat_at' => now(),
            ]);

            if (! in_array($layout->project->status, ['archived', 'cancelled'], true)) {
                $layout->project->update(['current_stage' => 'quality_check']);
            }

            $layout->project->qaChecks()
                ->whereIn('item_key', ['cover_exists', 'back_cover_exists', 'reader_order_asset_complete', 'print_ready_asset_complete'])
                ->update([
                    'result' => 'pass',
                    'note' => 'تم التحقق تلقائيًا عند توليد إصدار الإخراج v'.$layout->version_number.'.',
                    'reviewed_by_user_id' => $layout->generated_by_user_id,
                    'reviewed_at' => now(),
                ]);

            ProductionStudio::log($layout->project, 'layout.generated', 'تم توليد ملفات الإخراج والطباعة.', [
                'layout_id' => $layout->id,
                'version' => $layout->version_number,
                'automation_run_id' => $layout->production_automation_run_id,
                'output_fingerprint' => $validation['output_fingerprint'],
            ], $layout->generatedBy);

            if ($layout->automationRun) {
                AdvanceProductionAutomationRun::dispatch($layout->automationRun->id)->afterCommit();
            }
        } catch (Throwable $exception) {
            $layout->update([
                'status' => 'failed',
                'error_message' => Str::limit($this->safeError($exception), 1500),
            ]);
            $layout->automationAttempt?->update([
                'status' => 'failed',
                'safe_failure_code' => 'layout_generation_failed',
                'safe_failure_summary' => Str::limit($this->safeError($exception), 1000),
                'failed_at' => now(),
                'heartbeat_at' => now(),
            ]);

            ProductionStudio::log($layout->project, 'layout.failed', 'فشل توليد ملفات الإخراج والطباعة.', [
                'layout_id' => $layout->id,
                'error' => Str::limit($this->safeError($exception), 300),
            ], $layout->generatedBy);

            if ($layout->automationRun) {
                AdvanceProductionAutomationRun::dispatch($layout->automationRun->id)->afterCommit();
            }
        }
    }

    private function safeError(Throwable $exception): string
    {
        return preg_replace('/(?:\/[^\s]+)+/', '[path redacted]', $exception->getMessage()) ?: 'تعذر توليد ملفات الإخراج.';
    }
}
