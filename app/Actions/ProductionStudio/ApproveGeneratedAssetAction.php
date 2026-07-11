<?php

namespace App\Actions\ProductionStudio;

use App\Models\ProductionProjectAsset;
use App\Support\ProductionStudio;
use RuntimeException;

class ApproveGeneratedAssetAction
{
    public function execute(ProductionProjectAsset $asset, ?string $notes = null): ProductionProjectAsset
    {
        $asset->loadMissing(['project']);

        $identityReview = data_get($asset->metadata_json, 'identity_review');
        if ($asset->asset_type === 'scene_image' && is_array($identityReview)) {
            if (in_array(data_get($identityReview, 'status'), ['queued', 'processing'], true)) {
                throw new RuntimeException('انتظر اكتمال فحص اتساق هوية الطفل قبل اعتماد الصورة.');
            }

            if (data_get($identityReview, 'status') === 'completed' && data_get($identityReview, 'result.decision') === 'fail') {
                throw new RuntimeException('فشل فحص اتساق هوية الطفل. استخدم تصحيح الهوية أو أعد التوليد قبل الاعتماد.');
            }
        }

        if ($asset->asset_type === 'character_sheet') {
            $asset->project->assets()
                ->where('asset_type', 'character_sheet')
                ->where('id', '!=', $asset->id)
                ->update(['is_primary' => false]);

            $asset->is_primary = true;
        }

        if ($asset->asset_type === 'scene_image' && $asset->production_scene_id) {
            $asset->project->assets()
                ->where('asset_type', 'scene_image')
                ->where('production_scene_id', $asset->production_scene_id)
                ->where('id', '!=', $asset->id)
                ->update(['is_final' => false]);

            $asset->is_final = true;
        }

        if ($asset->asset_type === 'cover_image') {
            $asset->project->assets()
                ->where('asset_type', 'cover_image')
                ->where('id', '!=', $asset->id)
                ->update(['is_final' => false]);

            $asset->is_final = true;
        }

        $asset->status = 'approved';
        $asset->review_notes = $notes;
        $asset->reviewed_by_user_id = auth()->id();
        $asset->reviewed_at = now();
        $asset->save();

        ProductionStudio::log($asset->project, 'ai_asset.approved', 'تم اعتماد مخرج صورة من الذكاء الاصطناعي.', [
            'asset_id' => $asset->id,
            'asset_type' => $asset->asset_type,
            'scene_id' => $asset->production_scene_id,
        ], auth()->user());

        return $asset;
    }
}
