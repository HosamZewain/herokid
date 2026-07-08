<?php

namespace App\Contracts;

use App\DTOs\Ai\GeneratedAssetResult;
use App\DTOs\Ai\GenerationRequest;
use App\DTOs\Ai\GenerationStatusResult;
use App\DTOs\Ai\GenerationSubmissionResult;
use App\DTOs\Ai\MoneyValue;
use App\Models\AiProvider;

interface AiImageProvider
{
    public function isAvailable(): bool;

    public function listSupportedModels(): array;

    public function supportsCapability(string $capability): bool;

    public function estimateCost(GenerationRequest $request): MoneyValue;

    public function submitGeneration(GenerationRequest $request): GenerationSubmissionResult;

    public function pollGeneration(string $externalRequestId, ?string $statusUrl = null, ?string $responseUrl = null): GenerationStatusResult;

    public function downloadOutput(GenerationStatusResult $result): GeneratedAssetResult;

    public function testConnection(AiProvider $provider, bool $allowBillable = false): array;
}
