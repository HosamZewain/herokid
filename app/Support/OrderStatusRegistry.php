<?php

namespace App\Support;

use App\Models\OrderStatusDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OrderStatusRegistry
{
    private static ?Collection $runtimeDefinitions = null;

    public const TYPE_ORDER = 'order';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_PRINTING = 'printing';

    public const TYPE_SHIPPING = 'shipping';

    public const TYPES = [
        self::TYPE_ORDER,
        self::TYPE_PAYMENT,
        self::TYPE_PRINTING,
        self::TYPE_SHIPPING,
    ];

    public const COLORS = [
        'slate' => 'رمادي',
        'blue' => 'أزرق',
        'indigo' => 'نيلي',
        'violet' => 'بنفسجي',
        'amber' => 'كهرماني',
        'orange' => 'برتقالي',
        'rose' => 'وردي',
        'red' => 'أحمر',
        'teal' => 'فيروزي',
        'cyan' => 'سماوي',
        'emerald' => 'أخضر',
        'gray' => 'رمادي فاتح',
    ];

    private const COLOR_CLASSES = [
        'slate' => 'bg-slate-100 text-slate-700',
        'blue' => 'bg-blue-100 text-blue-800',
        'indigo' => 'bg-indigo-100 text-indigo-800',
        'violet' => 'bg-violet-100 text-violet-800',
        'amber' => 'bg-amber-100 text-amber-800',
        'orange' => 'bg-orange-100 text-orange-800',
        'rose' => 'bg-rose-100 text-rose-800',
        'red' => 'bg-red-100 text-red-800',
        'teal' => 'bg-teal-100 text-teal-800',
        'cyan' => 'bg-cyan-100 text-cyan-800',
        'emerald' => 'bg-emerald-100 text-emerald-800',
        'gray' => 'bg-gray-100 text-gray-600',
    ];

    public static function typeLabels(): array
    {
        return [
            self::TYPE_ORDER => 'حالات الطلب',
            self::TYPE_PAYMENT => 'حالات الدفع',
            self::TYPE_PRINTING => 'حالات الطباعة',
            self::TYPE_SHIPPING => 'حالات الشحن',
        ];
    }

    public static function behaviorOptions(string $type): array
    {
        return match ($type) {
            self::TYPE_ORDER => [
                'standard' => 'حالة تشغيل عادية',
                'cancelled' => 'طلب ملغي',
                'shipped' => 'طلب تم شحنه',
                'delivered' => 'طلب تم تسليمه',
            ],
            self::TYPE_PAYMENT => [
                'unpaid' => 'غير مدفوع (المبلغ المدفوع صفر)',
                'partially_paid' => 'مدفوع جزئيًا (يتطلب مبلغًا وطريقة دفع)',
                'paid_without_shipping' => 'مدفوع بدون الشحن',
                'paid_in_full' => 'مدفوع بالكامل',
            ],
            self::TYPE_PRINTING => [
                'not_required' => 'لا يحتاج طباعة',
                'not_started' => 'لم تبدأ الطباعة',
                'ready' => 'جاهز للطباعة',
                'in_progress' => 'الطباعة جارية',
                'completed' => 'الطباعة مكتملة',
                'on_hold' => 'الطباعة متوقفة',
            ],
            self::TYPE_SHIPPING => [
                'not_required' => 'لا يحتاج شحن',
                'not_ready' => 'غير جاهز للشحن',
                'ready' => 'جاهز للشحن',
                'shipment_created' => 'تم إنشاء شحنة',
                'shipped' => 'تم الشحن',
                'delivered' => 'تم التسليم',
                'returned' => 'مرتجع',
                'cancelled' => 'الشحن ملغي',
            ],
            default => [],
        };
    }

    public static function definitions(string $type, bool $activeOnly = true): Collection
    {
        $definitions = self::all()->where('type', $type);

        return ($activeOnly ? $definitions->where('is_active', true) : $definitions)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values();
    }

    public static function keys(string $type, bool $activeOnly = true): array
    {
        return self::definitions($type, $activeOnly)->pluck('key')->all();
    }

    public static function labels(string $type, bool $activeOnly = true): array
    {
        return self::definitions($type, $activeOnly)->pluck('label_ar', 'key')->all();
    }

    public static function colors(string $type, bool $activeOnly = false): array
    {
        return self::definitions($type, $activeOnly)
            ->mapWithKeys(fn (mixed $definition): array => [
                $definition->key => self::colorClass($definition->color),
            ])->all();
    }

    public static function label(string $type, ?string $key, ?string $fallback = null): string
    {
        $definition = self::definition($type, $key);

        return $definition?->label_ar ?? $fallback ?? (string) $key;
    }

    public static function color(string $type, ?string $key): string
    {
        return self::colorClass(self::definition($type, $key)?->color);
    }

    public static function behavior(string $type, ?string $key): ?string
    {
        return self::definition($type, $key)?->behavior;
    }

    public static function keysForBehavior(string $type, string $behavior, bool $activeOnly = false): array
    {
        return self::definitions($type, $activeOnly)
            ->where('behavior', $behavior)
            ->pluck('key')
            ->all();
    }

    public static function isValid(string $type, ?string $key, bool $activeOnly = true): bool
    {
        return filled($key) && in_array($key, self::keys($type, $activeOnly), true);
    }

    public static function clearCache(): void
    {
        self::$runtimeDefinitions = null;
        Cache::forget('order-status-definitions.v1');
    }

    public static function fallbackDefinitions(): array
    {
        return [
            [self::TYPE_ORDER, 'new', 'طلب جديد', 'blue', 'standard'],
            [self::TYPE_ORDER, 'under_review', 'قيد المراجعة', 'amber', 'standard'],
            [self::TYPE_ORDER, 'generating', 'جاري التوليد', 'violet', 'standard'],
            [self::TYPE_ORDER, 'ready_preview', 'جاهز للمعاينة', 'purple', 'standard'],
            [self::TYPE_ORDER, 'preview_uploaded', 'انتظار الموافقة', 'orange', 'standard'],
            [self::TYPE_ORDER, 'revision_requested', 'طلب تعديلات', 'rose', 'standard'],
            [self::TYPE_ORDER, 'approved_for_print', 'موافق للطباعة', 'teal', 'standard'],
            [self::TYPE_ORDER, 'printing', 'جاري الطباعة', 'indigo', 'standard'],
            [self::TYPE_ORDER, 'shipped', 'تم الشحن', 'cyan', 'shipped'],
            [self::TYPE_ORDER, 'delivered', 'تم التوصيل', 'emerald', 'delivered'],
            [self::TYPE_ORDER, 'cancelled', 'ملغي', 'red', 'cancelled'],
            [self::TYPE_PAYMENT, 'unpaid', 'غير مدفوع', 'slate', 'unpaid'],
            [self::TYPE_PAYMENT, 'partially_paid', 'مدفوع جزئياً', 'amber', 'partially_paid'],
            [self::TYPE_PAYMENT, 'paid_without_shipping', 'مدفوع بدون شحن', 'blue', 'paid_without_shipping'],
            [self::TYPE_PAYMENT, 'paid_in_full', 'مدفوع كلياً', 'emerald', 'paid_in_full'],
            [self::TYPE_PRINTING, 'not_required', 'لا يحتاج طباعة', 'gray', 'not_required'],
            [self::TYPE_PRINTING, 'not_started', 'لم تبدأ الطباعة', 'slate', 'not_started'],
            [self::TYPE_PRINTING, 'ready', 'جاهز للطباعة', 'teal', 'ready'],
            [self::TYPE_PRINTING, 'in_progress', 'جاري الطباعة', 'indigo', 'in_progress'],
            [self::TYPE_PRINTING, 'completed', 'اكتملت الطباعة', 'emerald', 'completed'],
            [self::TYPE_PRINTING, 'on_hold', 'الطباعة متوقفة', 'amber', 'on_hold'],
            [self::TYPE_SHIPPING, 'not_required', 'لا يحتاج شحن', 'gray', 'not_required'],
            [self::TYPE_SHIPPING, 'not_ready', 'غير جاهز للشحن', 'slate', 'not_ready'],
            [self::TYPE_SHIPPING, 'ready', 'جاهز للشحن', 'blue', 'ready'],
            [self::TYPE_SHIPPING, 'shipment_created', 'تم إنشاء شحنة', 'indigo', 'shipment_created'],
            [self::TYPE_SHIPPING, 'shipped', 'تم الشحن', 'cyan', 'shipped'],
            [self::TYPE_SHIPPING, 'delivered', 'تم التسليم', 'emerald', 'delivered'],
            [self::TYPE_SHIPPING, 'returned', 'مرتجع', 'orange', 'returned'],
            [self::TYPE_SHIPPING, 'cancelled', 'الشحن ملغي', 'red', 'cancelled'],
        ];
    }

    private static function definition(string $type, ?string $key): mixed
    {
        return self::all()->first(fn (mixed $definition): bool => $definition->type === $type && $definition->key === $key);
    }

    private static function all(): Collection
    {
        if (self::$runtimeDefinitions instanceof Collection) {
            return self::$runtimeDefinitions;
        }

        try {
            if (Schema::hasTable('order_status_definitions')) {
                return self::$runtimeDefinitions = Cache::remember('order-status-definitions.v1', 3600, fn (): Collection => OrderStatusDefinition::query()
                    ->orderBy('type')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get());
            }
        } catch (Throwable) {
            // Migrations, tests, and emergency maintenance must keep known statuses available.
        }

        return self::$runtimeDefinitions = collect(self::fallbackDefinitions())->map(function (array $row, int $index): object {
            return (object) [
                'id' => $index + 1,
                'type' => $row[0],
                'key' => $row[1],
                'label_ar' => $row[2],
                'color' => $row[3],
                'behavior' => $row[4],
                'description' => null,
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
                'is_system' => true,
            ];
        });
    }

    private static function colorClass(?string $color): string
    {
        return self::COLOR_CLASSES[$color ?? 'slate'] ?? self::COLOR_CLASSES['slate'];
    }
}
