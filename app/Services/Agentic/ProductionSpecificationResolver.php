<?php

namespace App\Services\Agentic;

use App\Models\OrderItem;
use App\Models\ProductProductionSpec;
use App\Support\ProductProductionPrompt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Compatibility projection only: never upgrades a catalogue or an order snapshot. */
class ProductionSpecificationResolver
{
    public const CAPABILITIES = ['single_image', 'story_scenes', 'book_cover', 'book_interior', 'identity', 'confirmation', 'preview', 'attachment', 'human_review'];

    public function validate(array $definition): array
    {
        return Validator::make($definition, [
            'schema_version' => 'required|integer|in:1',
            'capabilities' => 'required|array|min:1',
            'capabilities.*' => 'string|max:80|distinct',
            'output_type' => ['required', Rule::in(['image', 'pages', 'scenes', 'manual'])],
            'required_inputs' => 'present|array', 'required_inputs.*' => 'string|max:80',
            'human_steps' => 'present|array', 'human_steps.*' => 'string|max:80',
            'quality_requirements' => 'present|array', 'quality_requirements.*' => 'string|max:160',
        ])->validate();
    }

    public function forItem(OrderItem $item): array
    {
        $canonical = ProductProductionPrompt::forItem($item, privateImageInputs: true);
        $spec = $item->product_variant_id ? ProductProductionSpec::where('scope_key', 'variant:'.$item->product_variant_id)->latest('version')->first() : null;
        $spec ??= ProductProductionSpec::where('scope_key', 'product:'.$item->product_id)->latest('version')->first();
        $definition = $spec ? $this->validate($spec->definition) : null;

        return $canonical->map(function (array $unit) use ($item, $canonical, $spec, $definition): array {
            $stage = $unit['production_stage'];
            $capability = match ($stage) {
                'book_cover' => 'book_cover', 'book_interior' => 'book_interior', default => 'single_image',
            };
            $book = in_array($stage, ['book_cover', 'book_interior'], true);
            $supported = $book
                ? $canonical->count() === 2 && $canonical->where('production_stage', 'book_cover')->count() === 1 && $canonical->where('production_stage', 'book_interior')->count() === 1
                : in_array($item->product?->slug, config('puzzle_automation.product_slugs', []), true) && $canonical->count() === 1 && $unit['quantity_per_item'] === 1;
            if ($definition) {
                // A specification can restrict behavior, never expand an executor's scope.
                $supported = $supported && in_array($capability, $definition['capabilities'], true)
                    && ! array_diff($definition['capabilities'], self::CAPABILITIES)
                    && ! array_diff($definition['required_inputs'], ['child_photos', 'approved_identity', 'approved_cover', 'linked_story']);
            }
            $variantValid = ! $book || (! preg_match('/\bA3\b/i', json_encode($item->variant_snapshot ?? []))
                && (! $item->product_variant_id || (int) data_get($item->variant_snapshot, 'book_page_count') > 0));

            return [
                'unit_key' => $unit['unit_key'], 'capability' => $capability, 'supported' => (bool) $supported,
                'product_ref' => 'product:'.$item->product_id,
                'variant_ref' => $item->product_variant_id ? 'variant:'.$item->product_variant_id : null,
                'stage' => $stage, 'source' => $unit['prompt_source'],
                'spec_revision' => $spec ? $spec->scope_key.':v'.$spec->version : 'compat-v1',
                'spec_fingerprint' => ContextFingerprint::privateInputs([$definition, $unit['prompt'], $unit['quantity_per_item']]),
                'expected_outputs' => (int) $unit['quantity_per_item'], 'purchased_copies' => (int) $item->quantity,
                'prompt_complete' => trim($unit['prompt']) !== '' && ! preg_match('/\[MISSING|\{\{|signature=|X-Amz-/i', $unit['prompt']),
                'variant_valid' => $variantValid,
                'required_inputs' => $definition['required_inputs'] ?? ($stage === 'book_interior' ? ['child_photos', 'approved_cover'] : ['child_photos']),
                'human_steps' => $definition['human_steps'] ?? ['quality_review', 'studio', 'printing', 'packaging', 'shipping'],
                'quality_requirements' => $definition['quality_requirements'] ?? ['correct_child', 'correct_unit', 'print_review'],
            ];
        })->all();
    }
}
