<?php

namespace App\Services\OrderAutomation;

use App\Models\AiModel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkflowDefinition
{
    public const LABELS = ['prepare' => 'تجهيز البيانات والبرومبت', 'generate' => 'توليد صورة الهوية', 'save' => 'حفظ معاينة على الطلب', 'review' => 'مراجعة موظف', 'whatsapp' => 'إرسال قالب المعاينة', 'wait' => 'إنهاء المسار دون اعتماد تلقائي'];

    public static function defaults(): array
    {
        return ['schema' => 1, 'trigger' => 'new_order', 'target' => 'story', 'story_ids' => [], 'model_id' => null,
            'prompt' => '{{identity_prompt}}', 'photo_limit' => 2, 'quality' => 'medium', 'size' => '1536x1024',
            'max_cost_usd' => 1, 'daily_budget_usd' => 10, 'max_concurrent' => 1,
            'template' => '', 'steps' => ['prepare', 'generate', 'save', 'wait']];
    }

    public function validate(array $data, bool $publishing = false): array
    {
        $clean = Validator::make($data, [
            'schema' => 'required|integer|in:1', 'trigger' => 'required|in:new_order', 'target' => 'required|in:story',
            'story_ids' => 'present|array|max:500', 'story_ids.*' => 'integer|distinct|exists:stories,id',
            'model_id' => ($publishing ? 'required' : 'nullable').'|integer|exists:ai_models,id',
            'prompt' => 'required|string|max:30000', 'photo_limit' => 'required|integer|min:1|max:4',
            'quality' => 'required|in:low,medium,high', 'size' => 'required|in:1024x1024,1536x1024,1024x1536',
            'max_cost_usd' => 'required|numeric|min:0.01|max:20', 'daily_budget_usd' => 'required|numeric|min:0.01|max:1000',
            'max_concurrent' => 'required|integer|min:1|max:5', 'template' => 'nullable|string|max:120|regex:/^[a-zA-Z0-9_.-]*$/',
            'steps' => 'required|array|min:4|max:6', 'steps.*' => 'required|distinct|in:prepare,generate,save,review,whatsapp,wait',
        ])->validate();
        $steps = $clean['steps'];
        $expected = array_values(array_filter(array_keys(self::LABELS), fn ($step) => in_array($step, $steps, true)));
        if ($steps !== $expected || array_diff(['prepare', 'generate', 'save', 'wait'], $steps)) {
            throw ValidationException::withMessages(['steps' => 'رتّب الخطوات: تجهيز ← توليد ← حفظ ← مراجعة اختيارية ← إرسال اختياري ← انتظار.']);
        }
        preg_match_all('/\{\{\s*([^}]+?)\s*\}\}/u', $clean['prompt'], $variables);
        if (array_diff($variables[1], ['identity_prompt', 'child_name', 'child_age', 'child_gender', 'story_title', 'order_reference'])) {
            throw ValidationException::withMessages(['prompt' => 'البرومبت يحتوي متغيرًا غير مدعوم.']);
        }
        if ($clean['daily_budget_usd'] < $clean['max_cost_usd']) {
            throw ValidationException::withMessages(['daily_budget_usd' => 'الميزانية اليومية أقل من حد المحاولة.']);
        }
        if ($publishing) {
            $model = AiModel::with('provider')->findOrFail($clean['model_id']);
            if (! $model->is_active || ! $model->provider?->is_active || ! $model->supportsCapability('character_sheet')
                || ! in_array($model->provider->driver, ['openai', 'fal'], true)
                || ($clean['photo_limit'] > 1 && ! $model->supportsMultipleReferences())) {
                throw ValidationException::withMessages(['model_id' => 'اختر موديل هوية نشطًا يدعم عدد الصور المحدد؛ لن تُحذف صور مرجعية بصمت.']);
            }
            $clean['model_snapshot'] = $model->only(['id', 'ai_provider_id', 'code', 'configuration_json', 'estimated_cost_amount', 'estimated_cost_per_output', 'estimated_cost_currency', 'generation_capabilities_json']);
            // Snapshot only execution options, never arbitrary provider/model metadata.
            $clean['model_snapshot']['configuration_json'] = array_intersect_key($model->configuration_json ?? [], array_flip([
                'supports_multiple_references', 'requires_image_url', 'supports_text_to_image_only',
                'supports_image_editing', 'supports_high_input_fidelity', 'quality_costs',
            ]));
            $clean['provider_driver'] = $model->provider->driver;
        }

        return $clean;
    }
}
