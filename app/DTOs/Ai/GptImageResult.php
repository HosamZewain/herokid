<?php

namespace App\DTOs\Ai;

readonly class GptImageResult
{
    public function __construct(
        public string $contents,
        public string $extension,
        public string $mimeType,
        public string $providerRequestId,
        public array $usage,
        public array $metadata,
    ) {}
}
