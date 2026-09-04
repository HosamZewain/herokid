<?php

namespace App\Services\Notifications;

use App\Models\Order;
use App\Services\Orders\AdminOrderGroupService;
use App\Support\OrderSource;
use Illuminate\Support\Collection;

class OrderCreatedNotificationMessage
{
    public const TEMPLATE_SETTING = 'notification_order_created_template';

    /** @var array<string, string> */
    public const VARIABLE_LABELS = [
        'order_reference' => 'رقم عملية الشراء المختصر',
        'customer_name' => 'اسم العميل',
        'phone' => 'رقم الهاتف',
        'children' => 'أسماء الأطفال',
        'stories' => 'القصص المختارة',
        'products' => 'المنتجات والإضافات مع الكميات',
        'stories_count' => 'عدد القصص',
        'products_count' => 'عدد المنتجات والإضافات',
        'items_count' => 'إجمالي عدد العناصر',
        'subtotal' => 'قيمة العناصر قبل التوصيل والخصم',
        'delivery_fee' => 'قيمة التوصيل',
        'discount' => 'قيمة الخصم',
        'total' => 'الإجمالي النهائي',
        'governorate' => 'المحافظة',
        'city' => 'المدينة أو المنطقة',
        'payment_method' => 'طريقة الدفع',
        'status' => 'حالة الطلب',
        'source' => 'مصدر الطلب',
        'admin_url' => 'رابط الطلب في لوحة الإدارة',
    ];

    public function __construct(
        private readonly AdminOrderGroupService $groups,
        private readonly NotificationSettings $settings,
    ) {}

    public function build(?Order $order): string
    {
        if (! $order) {
            return '🧾 طلب جديد على HeroKid';
        }

        $orders = $this->groups->ordersForGroup($order)
            ->loadMissing([
                'user:id,name,role',
                'checkoutReference:id,checkout_group_key,short_reference,reference_month,monthly_sequence',
                'story:id,title,price',
                'items.product:id,name_ar',
            ]);
        $group = $this->groups->present($orders);

        if ($group === []) {
            return '🧾 طلب جديد على HeroKid';
        }

        $template = (string) $this->settings->get(
            self::TEMPLATE_SETTING,
            config('admin_notifications.settings.'.self::TEMPLATE_SETTING, '')
        );

        if (blank($template)) {
            $template = (string) config('admin_notifications.settings.'.self::TEMPLATE_SETTING, '🧾 طلب جديد على HeroKid');
        }

        return strtr($template, $this->replacementMap($group));
    }

    /** @return array<int, string> */
    public function unknownVariables(string $template): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', $template, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $variable): string => strtolower($variable))
            ->reject(fn (string $variable): bool => array_key_exists($variable, self::VARIABLE_LABELS))
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $group
     * @return array<string, string>
     */
    private function replacementMap(array $group): array
    {
        $orders = collect($group['active_orders'] ?? $group['orders'] ?? []);
        $products = collect($group['direct_products'] ?? [])->concat($group['add_ons'] ?? [])->values();
        $children = collect($group['child_names'] ?? [])
            ->concat($this->productChildNames($products))
            ->filter()
            ->unique()
            ->values();
        $storyLines = $orders
            ->filter(fn (Order $item): bool => filled($item->story_id) || $item->items->contains('item_type', 'story'))
            ->map(function (Order $item): string {
                $title = $item->items->firstWhere('item_type', 'story')?->title ?: $item->story?->title ?: 'قصة مخصصة';

                return filled($item->child_name) ? $title.' — '.$item->child_name : $title;
            })
            ->values();
        $productLines = $products
            ->groupBy(fn ($item): string => (string) ($item->title ?: $item->product?->name_ar ?: 'منتج'))
            ->map(fn (Collection $items, string $title): string => $title.' × '.$items->sum('quantity'))
            ->values();
        $delivery = $group['delivery'] ?? [];
        $reference = $group['short_reference'] ?: ($group['order_numbers'][0] ?? $group['key']);
        $representativeId = (int) $group['representative_id'];
        $paymentMethod = $this->paymentMethodLabel($group['payment_method'] ?: data_get($delivery, 'payment_method'));

        $values = [
            'order_reference' => (string) $reference,
            'customer_name' => (string) $group['customer_name'],
            'phone' => (string) ($group['phone'] ?: 'غير محدد'),
            'children' => $children->isNotEmpty() ? $children->implode('، ') : 'غير محدد',
            'stories' => $storyLines->isNotEmpty() ? $storyLines->implode("\n") : 'لا توجد قصص',
            'products' => $productLines->isNotEmpty() ? $productLines->implode("\n") : 'لا توجد منتجات',
            'stories_count' => (string) ((int) ($group['story_count'] ?? 0)),
            'products_count' => (string) ((int) ($group['product_quantity'] ?? 0) + (int) ($group['add_on_quantity'] ?? 0)),
            'items_count' => (string) ((int) ($group['story_count'] ?? 0) + (int) ($group['product_quantity'] ?? 0) + (int) ($group['add_on_quantity'] ?? 0)),
            'subtotal' => $this->money((int) ($group['items_cents'] ?? 0)),
            'delivery_fee' => $this->money((int) ($group['delivery_cents'] ?? 0)),
            'discount' => $this->money((int) ($group['discount_cents'] ?? 0)),
            'total' => $this->money((int) ($group['total_cents'] ?? 0)),
            'governorate' => (string) (data_get($delivery, 'governorate') ?: 'غير محدد'),
            'city' => (string) (data_get($delivery, 'city') ?: data_get($delivery, 'area') ?: 'غير محدد'),
            'payment_method' => $paymentMethod,
            'status' => (string) ($group['status_label'] ?? 'غير محدد'),
            'source' => OrderSource::label($group['order_source'] ?? null),
            'admin_url' => route('admin.orders.groups.show', $representativeId),
        ];

        return collect($values)
            ->mapWithKeys(fn (string $value, string $key): array => ['{{'.$key.'}}' => $value])
            ->all();
    }

    private function productChildNames(Collection $items): Collection
    {
        return $items->flatMap(function ($item): array {
            $snapshot = $item->personalization_snapshot ?? [];
            $names = [];
            array_walk_recursive($snapshot, function ($value, $key) use (&$names): void {
                if ($key === 'child_name' && filled($value)) {
                    $names[] = (string) $value;
                }
            });

            return $names;
        });
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2).' ج.م';
    }

    private function paymentMethodLabel(?string $method): string
    {
        return [
            'cash_on_delivery' => 'الدفع عند الاستلام',
            'cash' => 'نقدي',
            'instapay' => 'InstaPay',
            'vodafone_cash' => 'فودافون كاش',
            'bank_transfer' => 'تحويل بنكي',
            'card' => 'كارت',
        ][$method ?: 'cash_on_delivery'] ?? ($method ?: 'الدفع عند الاستلام');
    }
}
