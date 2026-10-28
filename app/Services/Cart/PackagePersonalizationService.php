<?php

namespace App\Services\Cart;

use App\Models\PricingPackage;
use App\Support\ProductPersonalizationSchema;

class PackagePersonalizationService
{
    /** One child form; each component keeps its own schema and photo limit. */
    public function schema(PricingPackage $package): array
    {
        $package->loadMissing('items.product');
        $schemas = $package->items->filter(fn ($item) => $item->product)
            ->map(fn ($item) => ProductPersonalizationSchema::forProduct($item->product))->all();
        if ($package->story_count > 0) {
            $schemas[] = ProductPersonalizationSchema::normalize(['fields' => [
                'child_name' => ['enabled' => true, 'required' => true],
                'child_age' => ['enabled' => true, 'required' => true],
                'child_gender' => ['enabled' => true, 'required' => true],
                'photos' => ['enabled' => true, 'required' => true,
                    'min_files' => (int) config('photo_uploads.min_files', 2),
                    'max_files' => (int) config('photo_uploads.max_files', 3)],
            ]]);
        }
        $fields = [];
        foreach ($schemas as $schema) {
            foreach (ProductPersonalizationSchema::enabledFields($schema) as $key => $field) {
                $previous = $fields[$key] ?? [];
                $fields[$key] = $field;
                $fields[$key]['required'] = $field['required'] || ($previous['required'] ?? false);
                if ($key === 'photos') {
                    $fields[$key]['min_files'] = max($previous['min_files'] ?? 1, $field['required'] ? $field['min_files'] : 1);
                    $fields[$key]['max_files'] = max($previous['max_files'] ?? 1, $field['max_files']);
                }
            }
        }

        return ProductPersonalizationSchema::normalize(['fields' => $fields]);
    }
}
