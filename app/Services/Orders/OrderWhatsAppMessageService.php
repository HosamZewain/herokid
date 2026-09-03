<?php

namespace App\Services\Orders;

use App\Models\Setting;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OrderWhatsAppMessageService
{
    public const SETTING_KEY = 'order_whatsapp_message_templates';

    /** @var array<string, string> */
    public const VARIABLES = [
        'order_reference' => 'مرجع الطلب المختصر',
        'checkout_reference' => 'مرجع عملية الشراء الكامل',
        'order_numbers' => 'أرقام الطلبات الداخلية',
        'parent_name' => 'اسم ولي الأمر',
        'phone' => 'رقم الهاتف',
        'child_names' => 'أسماء الأطفال',
        'story_names' => 'أسماء القصص',
        'product_names' => 'أسماء المنتجات والإضافات',
        'order_items' => 'كل محتويات الطلب',
        'order_total' => 'إجمالي الطلب',
        'paid_amount' => 'المبلغ المدفوع',
        'remaining_amount' => 'المبلغ المتبقي',
        'payment_status' => 'حالة الدفع',
        'order_status' => 'حالة الطلب',
        'preview_url' => 'رابط معاينة الطلب (كتاب أو منتجات)',
        'preview_scenes_url' => 'رابط معاينة المشاهد',
        'product_preview_url' => 'رابط معرض معاينة المنتجات',
        'customer_preview_url' => 'رابط المعاينة المناسب للعميل',
        'payment_url' => 'رابط الدفع المجهز في إعداد RoboDesk',
        'shipping_address' => 'عنوان التوصيل',
    ];

    /**
     * @return array<int, array{id: string, title: string, message: string, is_active: bool, sort_order: int}>
     */
    public function templates(bool $activeOnly = false): array
    {
        $stored = Setting::query()->where('key', self::SETTING_KEY)->value('value');
        $decoded = $stored === null ? $this->defaults() : json_decode((string) $stored, true);
        $templates = is_array($decoded) ? $decoded : $this->defaults();

        return collect($templates)
            ->map(fn (mixed $template, int $index): array => $this->normalizeTemplate($template, $index))
            ->when($activeOnly, fn (Collection $items): Collection => $items->where('is_active', true))
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, title: string, body: string, url: string}>
     */
    public function messagesForGroup(array $group): array
    {
        $phone = Phone::forWhatsApp($group['phone'] ?? null);

        if (! $phone || ($group['trashed'] ?? false)) {
            return [];
        }

        $variables = $this->variablesForGroup($group);

        return collect($this->templates(true))
            ->map(function (array $template) use ($phone, $variables): array {
                $body = $this->render($template['message'], $variables);

                if (
                    $template['id'] === 'preview'
                    && $variables['customer_preview_url'] !== ''
                    && ! Str::contains($template['message'], ['{{preview_url}}', '{{customer_preview_url}}', '{{product_preview_url}}'])
                ) {
                    $body .= "\n\nرابط المعاينة:\n".$variables['customer_preview_url'];
                }

                return [
                    'id' => $template['id'],
                    'title' => $template['title'],
                    'body' => $body,
                    'url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($body),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    public function variablesForGroup(array $group): array
    {
        $orders = collect($group['active_orders'] ?? $group['orders'] ?? []);
        $preview = $orders
            ->pluck('bookletPreview')
            ->filter(fn ($item): bool => $item && $item->status === 'active' && $item->current_version_id)
            ->first();
        $productPreviewGallery = $orders
            ->pluck('productPreviewGallery')
            ->filter(fn ($item): bool => $item && $item->status === 'active')
            ->first();
        $productPreviewUrl = $productPreviewGallery?->previews->isNotEmpty()
            ? $productPreviewGallery->publicUrl()
            : null;
        $customerPreviewUrl = $preview?->publicUrl() ?: $productPreviewUrl;
        $delivery = $group['delivery'] ?? [];
        $address = collect([
            data_get($delivery, 'country'),
            data_get($delivery, 'governorate'),
            data_get($delivery, 'city'),
            data_get($delivery, 'street'),
            data_get($delivery, 'address_details', data_get($delivery, 'address')),
        ])->filter()->implode('، ');
        $stories = collect($group['story_titles'] ?? [])->filter()->values();
        $products = collect(array_merge($group['product_titles'] ?? [], $group['add_on_titles'] ?? []))->filter()->values();

        return [
            'order_reference' => (string) ($group['short_reference'] ?: $group['key']),
            'checkout_reference' => (string) ($group['key'] ?? ''),
            'order_numbers' => collect($group['order_numbers'] ?? [])->filter()->implode('، '),
            'parent_name' => (string) ($group['customer_name'] ?? ''),
            'phone' => (string) ($group['phone'] ?? ''),
            'child_names' => collect($group['child_names'] ?? [])->filter()->implode('، '),
            'story_names' => $stories->implode('، '),
            'product_names' => $products->implode('، '),
            'order_items' => $stories->merge($products)->implode('، '),
            'order_total' => format_money(((int) ($group['total_cents'] ?? 0)) / 100),
            'paid_amount' => format_money(((int) ($group['paid_amount_cents'] ?? 0)) / 100),
            'remaining_amount' => format_money(((int) ($group['remaining_amount_cents'] ?? 0)) / 100),
            'payment_status' => (string) ($group['payment_status_label'] ?? ''),
            'order_status' => (string) ($group['status_label'] ?? ''),
            'preview_url' => (string) ($customerPreviewUrl ?? ''),
            'preview_scenes_url' => (string) ($preview?->publicScenesUrl() ?? ''),
            'product_preview_url' => (string) ($productPreviewUrl ?? ''),
            'customer_preview_url' => (string) ($customerPreviewUrl ?? ''),
            'payment_url' => (string) config('robodesk.instapay_url', ''),
            'shipping_address' => $address,
        ];
    }

    /** @param array<string, string> $variables */
    public function render(string $template, array $variables): string
    {
        $replace = collect($variables)->mapWithKeys(fn (string $value, string $key): array => [
            '{{'.$key.'}}' => $value,
        ])->all();

        return trim(strtr($template, $replace));
    }

    /** @return array<int, string> */
    public function unknownVariables(string $template): array
    {
        preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i', $template, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $key): string => strtolower($key))
            ->unique()
            ->reject(fn (string $key): bool => array_key_exists($key, self::VARIABLES))
            ->values()
            ->all();
    }

    /** @return array<int, array{id: string, title: string, message: string, is_active: bool, sort_order: int}> */
    private function defaults(): array
    {
        return [
            [
                'id' => 'general',
                'title' => 'مراسلة عامة',
                'message' => 'مرحباً، بخصوص طلبك {{order_reference}}',
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'id' => 'preview',
                'title' => 'إرسال معاينة للعميل',
                'message' => "مرحباً {{parent_name}}، دي معاينة لطلبك {{order_reference}} من HeroKid:\n{{preview_url}}\nيرجى مراجعتها وتأكيدها.",
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'id' => 'payment',
                'title' => 'إرسال بيانات الدفع',
                'message' => "مرحباً {{parent_name}}، بيانات دفع طلبك {{order_reference}} من HeroKid.\nالمبلغ المطلوب: {{remaining_amount}}\nرابط الدفع: {{payment_url}}\nبعد الدفع يرجى إرسال صورة التحويل هنا.",
                'is_active' => true,
                'sort_order' => 30,
            ],
        ];
    }

    /** @return array{id: string, title: string, message: string, is_active: bool, sort_order: int} */
    private function normalizeTemplate(mixed $template, int $index): array
    {
        $template = is_array($template) ? $template : [];

        return [
            'id' => (string) ($template['id'] ?? 'message_'.Str::lower(Str::random(8))),
            'title' => trim((string) ($template['title'] ?? 'رسالة واتساب')),
            'message' => trim((string) ($template['message'] ?? '')),
            'is_active' => filter_var($template['is_active'] ?? true, FILTER_VALIDATE_BOOL),
            'sort_order' => (int) ($template['sort_order'] ?? (($index + 1) * 10)),
        ];
    }
}
