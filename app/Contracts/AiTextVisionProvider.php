<?php

namespace App\Contracts;

use App\DTOs\Ai\StructuredAiResult;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ProductionProject;
use App\Models\ProductionProjectAsset;
use App\Models\ProductionScene;
use App\Models\ProductionStoryVersion;

interface AiTextVisionProvider
{
    public function isAvailable(): bool;

    public function analyzeImagesToJson(ProductionProject $project, AiModel $model, array $photoIndices): StructuredAiResult;

    public function extractScenesToJson(ProductionProject $project, AiModel $model, ProductionStoryVersion|string|null $source): StructuredAiResult;

    public function improveSceneToJson(ProductionProject $project, ProductionScene $scene, AiModel $model): StructuredAiResult;

    public function reviewGeneratedIdentityToJson(ProductionProject $project, ProductionProjectAsset $asset, AiModel $model, int $primaryPhotoIndex): StructuredAiResult;

    public function testConnection(AiProvider $provider, bool $allowBillable = false): array;
}
