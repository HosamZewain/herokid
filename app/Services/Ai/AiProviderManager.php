<?php

namespace App\Services\Ai;

use App\Contracts\AiImageProvider;
use InvalidArgumentException;

class AiProviderManager
{
    public function imageProvider(string $driver): AiImageProvider
    {
        return match ($driver) {
            'fal' => app(FalImageProvider::class),
            default => throw new InvalidArgumentException("Unsupported AI provider driver [{$driver}]."),
        };
    }
}
