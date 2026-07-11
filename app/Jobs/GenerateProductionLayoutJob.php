<?php

namespace App\Jobs;

use App\Models\ProductionPrintLayout;
use App\Services\ProductionStudio\ProductionLayoutBuilder;
use App\Support\ProductionStudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class GenerateProductionLayoutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $layoutId) {}

    public function handle(ProductionLayoutBuilder $builder): void
    {
        $layout = ProductionPrintLayout::with('project.order')->findOrFail($this->layoutId);
        $layout->update(['status' => 'processing', 'error_message' => null]);

        try {
            $result = $builder->generate($layout);
            $layout->update([
                'status' => 'ready',
                'settings_json' => $result['settings'],
                'manifest_json' => $result['manifest'],
                'reader_pdf_path' => $result['reader_pdf_path'],
                'print_pdf_path' => $result['print_pdf_path'],
                'manifest_path' => $result['manifest_path'],
                'proof_checklist_path' => $result['proof_checklist_path'],
                'generated_at' => now(),
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
            ], $layout->generatedBy);
        } catch (Throwable $exception) {
            $layout->update([
                'status' => 'failed',
                'error_message' => Str::limit($this->safeError($exception), 1500),
            ]);

            ProductionStudio::log($layout->project, 'layout.failed', 'فشل توليد ملفات الإخراج والطباعة.', [
                'layout_id' => $layout->id,
                'error' => Str::limit($this->safeError($exception), 300),
            ], $layout->generatedBy);
        }
    }

    private function safeError(Throwable $exception): string
    {
        return preg_replace('/(?:\/[^\s]+)+/', '[path redacted]', $exception->getMessage()) ?: 'تعذر توليد ملفات الإخراج.';
    }
}
