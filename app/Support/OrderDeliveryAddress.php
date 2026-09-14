<?php

namespace App\Support;

class OrderDeliveryAddress
{
    /** Preserve provider mappings unless the destination itself was edited. */
    public static function mergeEdited(array $previous, array $changes): array
    {
        $merged = array_replace($previous, $changes);
        foreach (['delivery_country_id', 'delivery_governorate_id', 'country', 'governorate', 'city', 'area'] as $field) {
            if (array_key_exists($field, $changes) && trim((string) ($previous[$field] ?? '')) !== trim((string) ($changes[$field] ?? ''))) {
                foreach (['city', 'district', 'zone'] as $part) {
                    foreach (['id', 'name', 'other_name'] as $attribute) {
                        unset($merged['bosta_'.$part.'_'.$attribute]);
                    }
                }
                break;
            }
        }

        return $merged;
    }
}
