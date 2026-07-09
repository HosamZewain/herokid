<?php

use App\Models\AiModel;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $model = AiModel::query()
            ->where('code', 'fal-ai/flux-pro/kontext')
            ->first();

        if (! $model) {
            return;
        }

        $capabilities = array_values(array_unique(array_merge(
            $model->generation_capabilities_json ?? [],
            ['scene_generation']
        )));

        $model->forceFill([
            'generation_capabilities_json' => $capabilities,
            'notes' => $model->notes ?: 'Premium cover generation, difficult retries, and high-priority scenes.',
        ])->save();
    }

    public function down(): void
    {
        $model = AiModel::query()
            ->where('code', 'fal-ai/flux-pro/kontext')
            ->first();

        if (! $model) {
            return;
        }

        $model->forceFill([
            'generation_capabilities_json' => array_values(array_diff(
                $model->generation_capabilities_json ?? [],
                ['scene_generation']
            )),
        ])->save();
    }
};
