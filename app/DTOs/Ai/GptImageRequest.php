<?php

namespace App\DTOs\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;

readonly class GptImageRequest
{
    /**
     * @param  list<string>  $inputImages  Private image data URIs, never public URLs.
     */
    public function __construct(
        public AiProvider $provider,
        public AiModel $model,
        public string $prompt,
        public array $inputImages,
        public string $size,
        public string $quality,
        public string $clientRequestId,
    ) {}
}
