<?php

namespace App\Services\ProductionStudio;

use App\Models\ProductionAutomationCostEntry;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionAutomationStep;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductionAutomationCostLedger
{
    public function reserve(
        ProductionAutomationRun|int $run,
        ?ProductionAutomationStep $step,
        mixed $attempt,
        string $provider,
        string $model,
        string|float|int $projectedCost,
        array $pricingSnapshot,
        string $idempotencyKey,
        ?string $providerRequestId = null,
    ): ProductionAutomationCostEntry {
        $amount = $this->money($projectedCost);
        $runId = $run instanceof ProductionAutomationRun ? $run->id : $run;

        return DB::transaction(function () use ($runId, $step, $attempt, $provider, $model, $amount, $pricingSnapshot, $idempotencyKey, $providerRequestId): ProductionAutomationCostEntry {
            $existing = ProductionAutomationCostEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $lockedRun = ProductionAutomationRun::query()
                ->whereKey($runId)
                ->lockForUpdate()
                ->firstOrFail();

            $exposure = $this->currentExposure($lockedRun);
            $hardBudget = (float) $lockedRun->hard_budget;

            if ($hardBudget <= 0 || $exposure + (float) $amount > $hardBudget + 0.00001) {
                throw new RuntimeException('Automation hard budget would be exceeded by this provider request.');
            }

            return ProductionAutomationCostEntry::create([
                'automation_run_id' => $lockedRun->id,
                'automation_step_id' => $step?->id,
                'attempt_id' => is_object($attempt) ? $attempt->id : $attempt,
                'idempotency_key' => $idempotencyKey,
                'provider' => $provider,
                'model' => $model,
                'provider_request_id' => $providerRequestId,
                'status' => 'reserved',
                'estimated_amount' => $amount,
                'actual_amount' => null,
                'currency' => $lockedRun->currency ?: 'USD',
                'pricing_snapshot' => $pricingSnapshot,
            ]);
        });
    }

    public function incur(
        ProductionAutomationCostEntry $entry,
        string|float|int|null $actualAmount = null,
        ?string $providerRequestId = null,
        bool $actualProviderCostAvailable = false,
        array $metadata = [],
    ): ProductionAutomationCostEntry {
        return DB::transaction(function () use ($entry, $actualAmount, $providerRequestId, $actualProviderCostAvailable, $metadata): ProductionAutomationCostEntry {
            $locked = ProductionAutomationCostEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'incurred') {
                return $locked;
            }

            if (! in_array($locked->status, ['reserved', 'unknown'], true)) {
                throw new RuntimeException('Only reserved or unknown automation cost entries can be incurred.');
            }

            $estimated = (float) $locked->estimated_amount;
            $actual = $actualAmount === null ? $estimated : (float) $this->money($actualAmount);
            $locked->update([
                'status' => 'incurred',
                'actual_amount' => $this->money($actual),
                'provider_request_id' => $providerRequestId ?: $locked->provider_request_id,
                'metadata_json' => array_merge($locked->metadata_json ?? [], $metadata, [
                    'cost_source' => $actualProviderCostAvailable ? 'provider_actual' : 'estimated_fallback',
                ]),
                'finalized_at' => now(),
            ]);

            $unused = $estimated - $actual;
            if ($unused > 0.00001) {
                ProductionAutomationCostEntry::create([
                    'automation_run_id' => $locked->automation_run_id,
                    'automation_step_id' => $locked->automation_step_id,
                    'attempt_id' => $locked->attempt_id,
                    'released_from_cost_entry_id' => $locked->id,
                    'provider' => $locked->provider,
                    'model' => $locked->model,
                    'provider_request_id' => $locked->provider_request_id,
                    'status' => 'released',
                    'estimated_amount' => $this->money($unused),
                    'currency' => $locked->currency,
                    'pricing_snapshot' => $locked->pricing_snapshot,
                    'metadata_json' => ['reason' => 'unused_reservation'],
                    'finalized_at' => now(),
                ]);
            }

            return $locked->fresh();
        });
    }

    public function attachProviderRequest(ProductionAutomationCostEntry|int $entry, string $providerRequestId): ProductionAutomationCostEntry
    {
        $entryId = $entry instanceof ProductionAutomationCostEntry ? $entry->id : $entry;

        return DB::transaction(function () use ($entryId, $providerRequestId): ProductionAutomationCostEntry {
            $locked = ProductionAutomationCostEntry::query()
                ->whereKey($entryId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->provider_request_id) {
                $locked->update(['provider_request_id' => $providerRequestId]);
            }

            return $locked->fresh();
        });
    }

    public function release(ProductionAutomationCostEntry $entry, string $reason = 'request_not_submitted'): ProductionAutomationCostEntry
    {
        return DB::transaction(function () use ($entry, $reason): ProductionAutomationCostEntry {
            $locked = ProductionAutomationCostEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'released') {
                return $locked;
            }

            if ($locked->status !== 'reserved') {
                throw new RuntimeException('Only reserved automation cost entries can be released.');
            }

            $locked->update([
                'status' => 'released',
                'actual_amount' => '0.0000',
                'metadata_json' => array_merge($locked->metadata_json ?? [], ['release_reason' => $reason]),
                'finalized_at' => now(),
            ]);

            return $locked;
        });
    }

    public function markUnknownExposure(ProductionAutomationCostEntry $entry, string $reason = 'provider_billing_unknown'): ProductionAutomationCostEntry
    {
        return DB::transaction(function () use ($entry, $reason): ProductionAutomationCostEntry {
            $locked = ProductionAutomationCostEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'unknown') {
                return $locked;
            }

            if ($locked->status !== 'reserved') {
                throw new RuntimeException('Only reserved automation cost entries can become unknown exposure.');
            }

            $locked->update([
                'status' => 'unknown',
                'metadata_json' => array_merge($locked->metadata_json ?? [], ['unknown_reason' => $reason]),
                'finalized_at' => now(),
            ]);

            return $locked;
        });
    }

    public function summary(ProductionAutomationRun $run): array
    {
        $entries = $run->relationLoaded('costEntries') ? $run->costEntries : $run->costEntries()->get();
        $reserved = $entries->where('status', 'reserved')->sum(fn (ProductionAutomationCostEntry $entry): float => (float) $entry->estimated_amount);
        $incurred = $entries->where('status', 'incurred')->sum(fn (ProductionAutomationCostEntry $entry): float => (float) ($entry->actual_amount ?? $entry->estimated_amount));
        $released = $entries->where('status', 'released')->sum(fn (ProductionAutomationCostEntry $entry): float => (float) $entry->estimated_amount);
        $unknown = $entries->where('status', 'unknown')->sum(fn (ProductionAutomationCostEntry $entry): float => (float) $entry->estimated_amount);
        $budget = (float) $run->hard_budget;

        return [
            'currency' => $run->currency ?: 'USD',
            'hard_budget' => $this->money($budget),
            'reserved_cost' => $this->money($reserved),
            'incurred_cost' => $this->money($incurred),
            'released_cost' => $this->money($released),
            'unknown_billing_exposure' => $this->money($unknown),
            'remaining_budget' => $this->money(max(0, $budget - $reserved - $incurred - $unknown)),
        ];
    }

    private function currentExposure(ProductionAutomationRun $run): float
    {
        $summary = $this->summary($run->loadMissing('costEntries'));

        return (float) $summary['reserved_cost']
            + (float) $summary['incurred_cost']
            + (float) $summary['unknown_billing_exposure'];
    }

    private function money(string|float|int $amount): string
    {
        return number_format(max(0, (float) $amount), 4, '.', '');
    }
}
