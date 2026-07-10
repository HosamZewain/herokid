<?php

namespace App\Actions\ProductionStudio;

use App\Models\ProductionProjectAsset;
use App\Support\ProductionStudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DeleteGeneratedAssetAction
{
    private const GENERATED_IMAGE_TYPES = [
        'character_sheet',
        'scene_image',
        'cover_image',
    ];

    public function execute(ProductionProjectAsset $asset): array
    {
        $asset->loadMissing(['project', 'scene', 'generationJob']);

        if (! in_array($asset->asset_type, self::GENERATED_IMAGE_TYPES, true)) {
            throw new RuntimeException('يمكن حذف الصور المولدة بالذكاء الاصطناعي فقط من هذا الإجراء.');
        }

        $path = (string) $asset->file_path;
        $expectedPrefix = 'production-studio/projects/'.$asset->production_project_id.'/generated/';

        if ($path === '' || str_contains($path, '..') || ! str_starts_with($path, $expectedPrefix)) {
            throw new RuntimeException('مسار الصورة المولدة غير صالح للحذف.');
        }

        $disk = Storage::disk('local');
        $bytesFreed = $disk->exists($path) ? (int) $disk->size($path) : 0;

        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new RuntimeException('تعذر حذف ملف الصورة من التخزين الخاص. حاول مرة أخرى.');
        }

        $assetId = $asset->id;
        $assetType = $asset->asset_type;
        $sceneId = $asset->production_scene_id;
        $jobId = $asset->scene_generation_job_id;
        $project = $asset->project;

        DB::transaction(function () use ($asset, $path, $jobId, $project): void {
            if ($asset->scene) {
                $updates = [];
                foreach (['base_scene_image_path', 'generated_child_image_path', 'approved_final_image_path'] as $field) {
                    if ($asset->scene->{$field} === $path) {
                        $updates[$field] = null;
                    }
                }

                if ($updates !== []) {
                    $asset->scene->update($updates);
                }
            }

            if ($asset->generationJob) {
                $metadata = $asset->generationJob->output_metadata_json ?? [];
                unset($metadata['asset_id']);
                $metadata['asset_deleted_at'] = now()->toIso8601String();
                $metadata['asset_deleted_by_user_id'] = auth()->id();

                $asset->generationJob->update([
                    'output_asset_path' => null,
                    'output_metadata_json' => $metadata,
                ]);
            }

            $asset->delete();

            ProductionStudio::log($project, 'ai_asset.deleted', 'تم حذف صورة مولدة وملفها الخاص نهائيًا.', [
                'asset_id' => $asset->id,
                'asset_type' => $asset->asset_type,
                'scene_id' => $asset->production_scene_id,
                'generation_job_id' => $jobId,
            ], auth()->user());
        });

        return [
            'asset_id' => $assetId,
            'asset_type' => $assetType,
            'scene_id' => $sceneId,
            'generation_job_id' => $jobId,
            'bytes_freed' => $bytesFreed,
        ];
    }
}
