<?php

namespace App\Services\Ai;

use App\Models\AiModel;

class AiImagePricingService
{
    public function calculate(AiModel $model, string $size, string $quality, array $usage): array
    {
        foreach (['cost_usd', 'total_cost_usd', 'amount_usd'] as $field) {
            $amount = data_get($usage, $field);

            if (is_numeric($amount)) {
                return [
                    'cost_usd' => (string) $amount,
                    'method' => 'provider_reported',
                    'billing_status' => 'billable',
                    'rule' => ['source' => 'provider_usage', 'field' => $field],
                ];
            }
        }

        $configuration = $model->configuration_json ?? [];
        $matrixAmount = data_get($configuration, "pricing.{$size}.{$quality}")
            ?? data_get($configuration, "quality_costs.{$quality}");
        $amount = is_numeric($matrixAmount) ? $matrixAmount : $model->estimatedCost();

        if (! is_numeric($amount) || (float) $amount <= 0) {
            return [
                'cost_usd' => null,
                'method' => 'unknown',
                'billing_status' => 'unknown',
                'rule' => ['source' => 'unmatched'],
            ];
        }

        return [
            'cost_usd' => (string) $amount,
            'method' => 'calculated',
            'billing_status' => 'estimated',
            'rule' => [
                'source' => is_numeric($matrixAmount) ? 'model_configuration' : 'model_estimate',
                'model' => $model->code,
                'size' => $size,
                'quality' => $quality,
                'amount_usd' => (string) $amount,
            ],
        ];
    }
}
