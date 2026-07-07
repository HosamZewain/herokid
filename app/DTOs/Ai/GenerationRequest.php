<?php

namespace App\DTOs\Ai;

use App\Models\AiModel;
use App\Models\ProductionProject;
use App\Models\ProductionScene;

class GenerationRequest
{
    public function __construct(
        public readonly ProductionProject $project,
        public readonly ?ProductionScene $scene,
        public readonly AiModel $model,
        public readonly string $jobType,
        public readonly string $generationMode,
        public readonly string $prompt,
        public readonly string $negativePrompt,
        public readonly array $inputAssets = [],
        public readonly array $options = [],
    ) {}
}
