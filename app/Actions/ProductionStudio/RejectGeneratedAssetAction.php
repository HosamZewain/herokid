<?php

namespace App\Actions\ProductionStudio;

use App\Models\ProductionProjectAsset;
use App\Support\ProductionStudio;

class RejectGeneratedAssetAction
{
    public function execute(ProductionProjectAsset $asset, string $reason, bool $archive = false): ProductionProjectAsset
    {
        $asset->loadMissing('project');
        $asset->update([
            'status' => $archive ? 'archived' : 'rejected',
            'is_primary' => false,
            'is_final' => false,
            'rejection_reason' => $reason,
            'reviewed_by_user_id' => auth()->id(),
            'reviewed_at' => now(),
            'archived_at' => $archive ? now() : null,
        ]);

        ProductionStudio::log($asset->project, $archive ? 'ai_asset.archived' : 'ai_asset.rejected', $archive ? 'تمت أرشفة مخرج صورة.' : 'تم رفض مخرج صورة.', [
            'asset_id' => $asset->id,
            'asset_type' => $asset->asset_type,
            'reason' => $reason,
        ], auth()->user());

        return $asset;
    }
}
