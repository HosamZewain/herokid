<?php

namespace App\Services\Ai;

use App\Contracts\AiImageProvider;
use App\Support\Ai\SupportedProviderRegistry;
use InvalidArgumentException;

class AiProviderManager
{
    public function __construct(private readonly SupportedProviderRegistry $registry) {}

    public function imageProvider(string $driver): AiImageProvider
    {
        if (! $this->registry->supportsProvider($driver)) {
            throw new InvalidArgumentException("Unsupported AI provider driver [{$driver}].");
        }

        return match ($driver) {
            'fal' => app(FalImageProvider::class),
            default => throw new InvalidArgumentException("Unsupported AI provider driver [{$driver}]."),
        };
    }
}
