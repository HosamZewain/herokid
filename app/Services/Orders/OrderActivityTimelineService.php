<?php

namespace App\Services\Orders;

use App\Models\AdminActivityLog;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OrderActivityTimelineService
{
    /**
     * Build one operational timeline for every order record in a checkout.
     *
     * @return array{events: Collection<int, array<string, mixed>>, count: int}
     */
    public function forGroup(array $group): array
    {
        $orders = collect($group['orders'] ?? [])
            ->filter(fn (mixed $order): bool => $order instanceof Order)
            ->values();

        if ($orders->isEmpty()) {
            return ['events' => collect(), 'count' => 0];
        }

        $orderById = $orders->keyBy(fn (Order $order): int => (int) $order->id);
        $logs = AdminActivityLog::query()
            ->with('user:id,name')
            ->where('subject_type', Order::class)
            ->whereIn('subject_id', $orderById->keys())
            ->latest('created_at')
            ->latest('id')
            ->get();

        $events = $logs
            ->map(fn (AdminActivityLog $log): array => $this->presentLog($log, $orderById->get((int) $log->subject_id)))
            ->values();

        $hasCreationLog = $logs->contains(fn (AdminActivityLog $log): bool => in_array($log->action, [
            'order.created_manually',
            'order.created',
            'checkout.created',
        ], true));

        if (! $hasCreationLog) {
            /** @var Order $firstOrder */
            $firstOrder = $orders->sortBy('created_at')->first();
            $events->push([
                'id' => 'creation-'.$firstOrder->id,
                'action' => 'order.created',
                'description' => 'تم إنشاء عملية الشراء واستلام بيانات الطلب.',
                'actor' => $firstOrder->createdByAdmin?->name ?? 'العميل عبر الموقع',
                'created_at' => $firstOrder->created_at,
                'order_reference' => $this->orderReference($firstOrder),
                'category' => 'created',
                'details' => collect([
                    ['label' => 'عدد سجلات الطلب', 'value' => (string) $orders->count()],
                ]),
                'synthetic' => true,
            ]);
        }

        $events = $events
            ->sortByDesc(fn (array $event): int => $event['created_at']?->getTimestamp() ?? 0)
            ->values();

        return ['events' => $events, 'count' => $events->count()];
    }

    /** @param Collection<int, Order> $orderById */
    private function presentLog(AdminActivityLog $log, ?Order $order): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description ?: $this->actionLabel($log->action),
            'actor' => $log->user?->name ?? (in_array(data_get($log->properties, 'actor_name'), ['Automation', 'HeroKid Auto Pilot'], true) ? $log->properties['actor_name'] : 'النظام'),
            'created_at' => $log->created_at,
            'order_reference' => $order ? $this->orderReference($order) : null,
            'category' => $this->category($log->action),
            'details' => $this->details($log->properties ?? []),
            'synthetic' => false,
        ];
    }

    private function orderReference(Order $order): string
    {
        return $order->checkoutReference?->short_reference
            ?: $order->order_number;
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'order.prompt_copied' => 'تم نسخ برومبت الإنتاج.',
            'order.attachments_uploaded' => 'تم رفع مرفقات الطلب.',
            'order.attachment_deleted' => 'تم حذف مرفق من الطلب.',
            'order.status_updated', 'checkout.workflow_statuses_updated' => 'تم تحديث حالة الطلب.',
            'checkout.payment_updated' => 'تم تحديث حالة الدفع.',
            'checkout.discount_updated' => 'تم تحديث خصم عملية الشراء.',
            'order.details_updated', 'checkout.full_order_updated' => 'تم تعديل بيانات الطلب.',
            'checkout.contact_updated' => 'تم تعديل بيانات التواصل.',
            'checkout.product_added' => 'تمت إضافة منتج إلى الطلب.',
            'checkout.story_added' => 'تمت إضافة قصة إلى الطلب.',
            'checkout.item_removed' => 'تم حذف عنصر من الطلب ونقله للمحذوفات.',
            'order.product_details_updated' => 'تم تعديل بيانات منتج واحد.',
            'order.note_added' => 'تمت إضافة ملاحظة داخلية.',
            'order.assignment_acquired' => 'تم استلام مسؤولية الطلب.',
            'order.assignment_released' => 'تم ترك مسؤولية الطلب.',
            'order.assignment_taken_over' => 'تم نقل مسؤولية الطلب.',
            default => Str::headline(str_replace(['order.', 'checkout.'], '', $action)),
        };
    }

    private function category(string $action): string
    {
        return match (true) {
            Str::contains($action, ['attachment', 'preview', 'photo']) => 'attachment',
            Str::contains($action, ['status', 'payment', 'workflow']) => 'status',
            Str::contains($action, ['prompt']) => 'prompt',
            Str::contains($action, ['note']) => 'note',
            Str::contains($action, ['created']) => 'created',
            Str::contains($action, ['delete', 'void', 'cancel']) => 'deleted',
            Str::contains($action, ['assignment']) => 'assignment',
            default => 'updated',
        };
    }

    /** @return Collection<int, array{label: string, value: string}> */
    private function details(array $properties): Collection
    {
        $details = collect();

        if (($properties['field_changes_version'] ?? null) === 1) {
            foreach ($properties['field_changes'] ?? [] as $change) {
                $old = $this->exactValue($change['old'] ?? null, $change['format'] ?? 'text', $change['old_exists'] ?? true);
                $new = $this->exactValue($change['new'] ?? null, $change['format'] ?? 'text', $change['new_exists'] ?? true);
                $details->push(['label' => $change['label'], 'value' => $old.' ← '.$new, 'old_value' => $old, 'new_value' => $new]);
            }
            if (! empty($properties['shipping_calculation'])) {
                $details->push(['label' => 'حساب الشحن', 'value' => match ($properties['shipping_calculation']) {
                    'preserved_existing_fee' => 'تم الاحتفاظ بقيمة الشحن المحفوظة للطلب.',
                    'governorate_selected_current_discount' => 'تم استكمال محافظة توصيل غير مسجلة؛ حسب الشحن بسعر المحافظة والخصم العام الحالي.',
                    default => 'تغيرت محافظة التوصيل؛ أعيد حساب الشحن بسعر المحافظة والخصم العام الحالي.',
                }]);
            }
            if (! empty($properties['reason'])) {
                $details->push(['label' => 'سبب التعديل', 'value' => $properties['reason']]);
            }

            return $details;
        }

        foreach ((array) ($properties['changes'] ?? []) as $field => $change) {
            if (! is_array($change) || (! array_key_exists('old', $change) && ! array_key_exists('new', $change))) {
                continue;
            }

            $details->push([
                'label' => $this->propertyLabel((string) $field),
                'value' => $this->displayValue($change['old'] ?? null).' ← '.$this->displayValue($change['new'] ?? null),
            ]);
        }

        $this->addStatusChanges($details, (array) ($properties['before'] ?? []), (array) ($properties['after'] ?? []));
        $this->addCheckoutChanges($details, (array) ($properties['before'] ?? []), (array) ($properties['after'] ?? []));
        foreach ((array) ($properties['removed_items'] ?? []) as $item) {
            if (is_array($item) && isset($item['title'])) {
                $details->push(['label' => 'العنصر المحذوف', 'value' => $item['title'].' × '.($item['quantity'] ?? 1)]);
            }
        }

        if (isset($properties['status']) && is_array($properties['status']) && array_key_exists('new', $properties['status'])) {
            $details->push([
                'label' => 'حالة الطلب',
                'value' => $this->displayValue($properties['status']['old'] ?? null).' ← '.$this->displayValue($properties['status']['new'] ?? null),
            ]);
        }

        $fileNames = collect($properties['file_names'] ?? [])->filter(fn (mixed $value): bool => is_scalar($value));
        if ($fileNames->isNotEmpty()) {
            $details->push(['label' => 'الملفات', 'value' => $fileNames->map(fn ($value) => (string) $value)->implode('، ')]);
        } elseif (isset($properties['file_name'])) {
            $details->push(['label' => 'الملف', 'value' => $this->displayValue($properties['file_name'])]);
        }

        foreach ([
            'stage' => 'المرحلة',
            'source_id' => 'رقم سجل التنفيذ',
            'decision' => 'قرار العميل',
            'reason' => 'السبب',
            'admin_notes' => 'ملاحظة الحالة',
            'assigned_to_name' => 'مسؤول الطلب',
            'previous_assignee' => 'المسؤول السابق',
            'released_user_name' => 'المسؤول السابق',
            'prompt_type_label' => 'نوع البرومبت',
            'product_title' => 'المنتج',
            'story_title' => 'القصة',
            'child_name' => 'الطفل',
            'autopilot_run' => 'مرجع قرار Auto Pilot',
            'autopilot_target' => 'الوحدة المستهدفة',
            'autopilot_decision' => 'قرار Auto Pilot',
            'autopilot_policy' => 'نتيجة فحص القرار',
            'autopilot_mode' => 'نوع السجل',
            'autopilot_receipt' => 'إيصال تسليم الخطوة',
            'autopilot_action' => 'الخطوة المطلوبة',
            'autopilot_reason' => 'سبب التوقف',
            'autopilot_executor' => 'مرجع المسار المسؤول',
            'autopilot_state' => 'حالة التنفيذ',
        ] as $key => $label) {
            if (! isset($properties[$key]) || $properties[$key] === '') {
                continue;
            }

            $details->push(['label' => $label, 'value' => $this->displayValue($properties[$key])]);
        }

        return $details
            ->filter(fn (array $detail): bool => $detail['value'] !== '—')
            ->unique(fn (array $detail): string => $detail['label'].'|'.$detail['value'])
            ->take(12)
            ->values();
    }

    private function addStatusChanges(Collection $details, array $before, array $after): void
    {
        foreach (['status', 'payment_status', 'printing_status', 'shipping_status'] as $field) {
            if (! array_key_exists($field, $after) || ($before[$field] ?? null) === $after[$field]) {
                continue;
            }

            $details->push([
                'label' => $this->propertyLabel($field),
                'value' => $this->displayValue($before[$field] ?? null).' ← '.$this->displayValue($after[$field]),
            ]);
        }
    }

    private function addCheckoutChanges(Collection $details, array $before, array $after): void
    {
        foreach ([
            'story_count' => 'عدد القصص',
            'product_quantity' => 'عدد المنتجات',
            'add_on_quantity' => 'عدد الإضافات',
            'items_cents' => 'قيمة العناصر بالقرش',
            'delivery_cents' => 'التوصيل بالقرش',
            'discount_cents' => 'الخصم بالقرش',
            'total_cents' => 'الإجمالي بالقرش',
            'paid_amount_cents' => 'المدفوع بالقرش',
        ] as $field => $label) {
            if (! array_key_exists($field, $after) || ($before[$field] ?? null) === $after[$field]) {
                continue;
            }

            $details->push([
                'label' => $label,
                'value' => $this->displayValue($before[$field] ?? null).' ← '.$this->displayValue($after[$field]),
            ]);
        }
    }

    private function propertyLabel(string $property): string
    {
        return match ($property) {
            'status' => 'حالة الطلب',
            'payment_status' => 'حالة الدفع',
            'printing_status' => 'حالة الطباعة',
            'shipping_status' => 'حالة الشحن',
            'parent_name' => 'اسم ولي الأمر',
            'phone' => 'الهاتف',
            'child_name' => 'اسم الطفل',
            'child_age' => 'عمر الطفل',
            'child_gender' => 'جنس الطفل',
            'story_id' => 'القصة',
            'language' => 'اللغة',
            'lesson' => 'الدرس',
            'interests' => 'الاهتمامات',
            'gift_note' => 'الإهداء',
            'parent_notes' => 'ملاحظات العميل',
            'alternate_phone' => 'الهاتف الإضافي',
            'school_name' => 'اسم المدرسة',
            'class_name' => 'الفصل',
            'uploaded_photos_count' => 'عدد صور الطفل',
            'items_cents' => 'قيمة العناصر بالقرش',
            'total_cents' => 'إجمالي الطلب بالقرش',
            default => str_replace('_', ' ', $property),
        };
    }

    private function exactValue(mixed $value, string $format, bool $exists): string
    {
        if (! $exists) {
            return 'غير موجود';
        }
        if ($value === null || $value === '') {
            return '—';
        }
        if ($format === 'money_cents') {
            return format_money(((int) $value) / 100);
        }
        if (is_bool($value)) {
            return $value ? 'نعم' : 'لا';
        }

        return is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—');
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'نعم' : 'لا';
        }

        if (is_scalar($value)) {
            return Str::limit((string) $value, 180, '…');
        }

        return Str::limit(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—', 180, '…');
    }
}
