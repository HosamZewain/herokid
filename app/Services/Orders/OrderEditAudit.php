<?php

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** Explicit business fields only; never persist request payloads, URLs or credentials. */
class OrderEditAudit
{
    public function snapshot(Collection $orders): array
    {
        $fields = [];
        (new \Illuminate\Database\Eloquent\Collection($orders->all()))->loadMissing(['items', 'story:id,title']);
        foreach ($orders as $order) {
            $prefix = 'orders.'.$order->id;
            $scope = 'سجل #'.$order->id;
            foreach ([
                'parent_name' => 'اسم ولي الأمر', 'child_name' => 'اسم الطفل', 'child_age' => 'عمر الطفل',
                'child_gender' => 'جنس الطفل', 'story_id' => 'رقم القصة', 'language' => 'اللغة',
                'lesson' => 'القيمة التربوية', 'interests' => 'الاهتمامات', 'gift_note' => 'الإهداء',
                'parent_notes' => 'ملاحظات العميل', 'notes' => 'ملاحظات الفريق',
                'order_source' => 'مصدر الطلب', 'source_notes' => 'تفاصيل المصدر',
                'discount_cents' => 'خصم الطلب', 'discount_reason' => 'سبب الخصم',
                'status' => 'حالة الطلب', 'printing_status' => 'حالة الطباعة', 'shipping_status' => 'حالة الشحن',
                'payment_status' => 'حالة الدفع', 'payment_method' => 'طريقة الدفع', 'paid_amount_cents' => 'المدفوع',
            ] as $key => $label) {
                $fields[$prefix.'.'.$key] = $this->field($scope.' · '.$label, $order->{$key}, str_ends_with($key, '_cents') ? 'money_cents' : 'text');
            }
            $fields[$prefix.'.story_title'] = $this->field($scope.' · عنوان القصة', $order->story?->title);
            foreach ([
                'phone' => 'الهاتف', 'alternate_phone' => 'الهاتف الإضافي',
                'delivery_country_id' => 'رقم دولة التوصيل', 'country' => 'الدولة',
                'delivery_governorate_id' => 'رقم محافظة التوصيل', 'governorate' => 'المحافظة',
                'city' => 'المدينة / المنطقة', 'area' => 'الحي', 'street' => 'الشارع',
                'address' => 'العنوان', 'address_details' => 'تفاصيل العنوان',
                'bosta_city_id' => 'رقم مدينة بوسطة', 'bosta_city_name' => 'مدينة بوسطة',
                'bosta_city_other_name' => 'مدينة بوسطة بالعربية', 'bosta_district_id' => 'رقم منطقة بوسطة',
                'bosta_district_name' => 'منطقة بوسطة', 'bosta_district_other_name' => 'منطقة بوسطة بالعربية',
                'bosta_zone_id' => 'رقم نطاق بوسطة', 'bosta_zone_name' => 'نطاق بوسطة',
                'bosta_zone_other_name' => 'نطاق بوسطة بالعربية',
                'original_delivery_fee' => 'الشحن قبل الخصم', 'shipping_discount' => 'خصم الشحن',
                'delivery_fee' => 'الشحن المستحق', 'subtotal' => 'قيمة العناصر', 'total' => 'الإجمالي',
                'general_shipping_discount' => 'إعداد خصم الشحن المحفوظ', 'free_shipping_offer' => 'عرض الشحن المحفوظ',
            ] as $key => $label) {
                $fields[$prefix.'.delivery.'.$key] = $this->field($scope.' · '.$label, data_get($order->delivery_details, $key),
                    in_array($key, ['original_delivery_fee', 'shipping_discount', 'delivery_fee', 'subtotal', 'total'], true) ? 'money' : 'text');
            }
            $fields[$prefix.'.photos'] = $this->field($scope.' · مراجع صور الطفل', $this->photoReferences($order));
            foreach ($order->items as $item) {
                $itemPrefix = $prefix.'.items.'.$item->id;
                $itemScope = $scope.' · عنصر #'.$item->id;
                foreach ([
                    'item_type' => 'نوع العنصر', 'title' => 'اسم العنصر', 'product_id' => 'رقم المنتج',
                    'story_id' => 'رقم قصة العنصر', 'product_variant_id' => 'رقم الخيار', 'sku' => 'SKU',
                    'quantity' => 'الكمية', 'unit_price_cents' => 'سعر الوحدة', 'total_price_cents' => 'إجمالي العنصر',
                    'linked_order_item_id' => 'العنصر المرتبط', 'personalization_mode' => 'طريقة التخصيص',
                ] as $key => $label) {
                    $fields[$itemPrefix.'.'.$key] = $this->field($itemScope.' · '.$label, $item->{$key}, str_ends_with($key, '_cents') ? 'money_cents' : 'text');
                }
                // Read only submitted personalization values, not schema instructions or prompts.
                $personalization = $item->personalization_snapshot ?? [];
                foreach (Arr::only($personalization, ['child_name', 'child_age', 'child_gender', 'child_age_range', 'school_name', 'class_name', 'language', 'interests', 'gift_note', 'parent_notes', 'uploaded_photos_count']) as $key => $value) {
                    $fields[$itemPrefix.'.personalization.'.$key] = $this->field($itemScope.' · '.$this->personalizationLabel((string) $key), $value);
                }
                foreach (($personalization['fields'] ?? []) as $key => $field) {
                    if (is_array($field) && array_key_exists('value', $field)) {
                        $fields[$itemPrefix.'.custom.'.$key] = $this->field($itemScope.' · '.($field['label'] ?? $key), $field['value']);
                    }
                }
                foreach (Arr::only($item->variant_snapshot ?? [], ['id', 'name_ar', 'name_en', 'sku', 'book_page_count']) as $key => $value) {
                    $fields[$itemPrefix.'.variant.'.$key] = $this->field($itemScope.' · الخيار · '.$key, $value);
                }
            }
        }

        return $fields;
    }

    public function changes(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key]['value'] ?? null;
            $new = $after[$key]['value'] ?? null;
            if ($old === $new && array_key_exists($key, $before) === array_key_exists($key, $after)) {
                continue;
            }
            $field = $after[$key] ?? $before[$key];
            $changes[] = ['field' => $key, 'label' => $field['label'], 'format' => $field['format'],
                'old' => $old, 'new' => $new, 'old_exists' => array_key_exists($key, $before), 'new_exists' => array_key_exists($key, $after)];
        }

        return $changes;
    }

    private function personalizationLabel(string $key): string
    {
        return match ($key) {
            'child_name' => 'اسم الطفل في العنصر', 'child_age' => 'عمر الطفل في العنصر',
            'child_gender' => 'جنس الطفل في العنصر', 'child_age_range' => 'الفئة العمرية',
            'school_name' => 'اسم المدرسة', 'class_name' => 'الفصل', 'language' => 'لغة العنصر',
            'interests' => 'اهتمامات الطفل', 'gift_note' => 'إهداء العنصر',
            'parent_notes' => 'ملاحظات تخصيص العنصر', 'uploaded_photos_count' => 'عدد صور العنصر',
            default => $key,
        };
    }

    private function field(string $label, mixed $value, string $format = 'text'): array
    {
        if ($value !== null && in_array($format, ['money', 'money_cents'], true)) {
            $value = (int) round((float) $value * ($format === 'money' ? 100 : 1));
            $format = 'money_cents';
        }

        return ['label' => $label, 'value' => $value, 'format' => $format];
    }

    private function photoReferences(Order $order): array
    {
        return collect($order->uploaded_photos ?? [])->map(fn (string $path): array => [
            'file' => basename($path), 'reference' => hash('sha256', $path),
        ])->values()->all();
    }
}
