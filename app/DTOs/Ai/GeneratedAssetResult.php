<?php

namespace App\DTOs\Ai;

class GeneratedAssetResult
{
    public function __construct(
        public readonly string $contents,
        public readonly string $extension = 'png',
        public readonly string $mimeType = 'image/png',
        public readonly array $metadata = [],
    ) {}
}
