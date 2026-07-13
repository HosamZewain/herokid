<?php

namespace App\Actions\ProductionStudio;

use App\Models\ProductionProjectAsset;
use App\Services\ProductionStudio\ProductionAutomationIdentityValidator;
use App\Services\ProductionStudio\ProductionAutomationVisualValidator;
use App\Support\ProductionStudio;
use RuntimeException;

class ApproveGeneratedAssetAction
{
    public function __construct(
        private readonly ProductionAutomationIdentityValidator $identityValidator,
        private readonly ProductionAutomationVisualValidator $visualValidator,
    ) {}

    public function execute(
        ProductionProjectAsset $asset,
        ?string $notes = null,
        bool $identityOverride = false,
        ?string $identityOverrideReason = null,
    ): ProductionProjectAsset {
        $asset->loadMissing(['project']);

        $manualIdentityOverride = false;
        $identityReview = data_get($asset->metadata_json, 'identity_review');
        if (in_array($asset->asset_type, ['scene_image', 'cover_image'], true) && is_array($identityReview)) {
            if (in_array(data_get($identityReview, 'status'), ['queued', 'processing'], true)) {
                throw new RuntimeException('انتظر اكتمال فحص اتساق هوية الطفل قبل اعتماد الصورة.');
            }

            if (data_get($identityReview, 'status') === 'completed') {
                $result = data_get($identityReview, 'result', []);
                $decision = data_get($result, 'decision');
                $visualType = data_get($result, 'identity_score') !== null
                    ? ($asset->asset_type === 'cover_image' ? 'cover' : 'scene')
                    : null;

                $evaluatedDecision = is_array($result)
                    ? ($visualType
                        ? $this->visualValidator->evaluate($result, $visualType)['decision']
                        : $this->identityValidator->evaluate($result)['decision'])
                    : 'fail';

                if ($decision === 'fail' || $evaluatedDecision === 'fail') {
                    if ($identityOverride && filled($identityOverrideReason)) {
                        $manualIdentityOverride = true;
                    } else {
                        throw new RuntimeException('فشل فحص اتساق هوية الطفل أو يحتوي على علامة حظر. استخدم تصحيح الهوية أو أعد التوليد قبل الاعتماد.');
                    }
                }
            }
        }

        if ($manualIdentityOverride) {
            $metadata = $asset->metadata_json ?? [];
            data_set($metadata, 'identity_review.manual_override', [
                'approved' => true,
                'reason' => $identityOverrideReason,
                'actor_user_id' => auth()->id(),
                'approved_at' => now()->toIso8601String(),
            ]);
            $asset->metadata_json = $metadata;
            $notes = trim((string) ($notes ?: ''));
            $notes = trim($notes."\n".'Manual identity validation override: '.$identityOverrideReason);
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
        $asset->rejection_reason = null;
        $asset->reviewed_by_user_id = auth()->id();
        $asset->reviewed_at = now();
        $asset->save();

        ProductionStudio::log($asset->project, 'ai_asset.approved', 'تم اعتماد مخرج صورة من الذكاء الاصطناعي.', [
            'asset_id' => $asset->id,
            'asset_type' => $asset->asset_type,
            'scene_id' => $asset->production_scene_id,
            'manual_identity_override' => $manualIdentityOverride,
        ], auth()->user());

        return $asset;
    }
}
