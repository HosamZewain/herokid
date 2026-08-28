<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ProductProductionPrompt
{
    public const MAX_TEMPLATE_LENGTH = 65000;

    private const NOT_AVAILABLE = '[MISSING — CONFIRM BEFORE PRODUCTION]';

    /**
     * @return Collection<int, array{item: OrderItem, prompt: string, uses_live_template: bool, uses_snapshot: bool}>
     */
    public static function forOrder(Order $order): Collection
    {
        $order->loadMissing(['items.product']);

        return $order->items
            ->filter(fn (OrderItem $item): bool => self::templateForItem($item) !== null)
            ->map(fn (OrderItem $item): array => [
                'item' => $item,
                'prompt' => self::renderForItem($item),
                'uses_live_template' => self::usesLiveTemplate($item),
                'uses_snapshot' => ! self::usesLiveTemplate($item),
            ])
            ->values();
    }

    public static function renderForItem(OrderItem $item): string
    {
        $item->loadMissing(['order', 'product']);
        $template = self::templateForItem($item) ?? '';
        $values = self::variablesForItem($item);

        return preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', function (array $matches) use ($values): string {
            return array_key_exists($matches[1], $values) ? $values[$matches[1]] : $matches[0];
        }, $template) ?? $template;
    }

    public static function templateForItem(OrderItem $item): ?string
    {
        $item->loadMissing('product');

        if ($item->product !== null) {
            $currentTemplate = $item->product->production_prompt_template;

            return is_string($currentTemplate) && trim($currentTemplate) !== ''
                ? $currentTemplate
                : null;
        }

        // Historical orders may outlive their linked product. Preserve the old
        // snapshot only for that orphaned case; every linked item always reads
        // the current product template, including when an admin clears it.
        $template = data_get($item->item_snapshot, 'production_prompt_template');

        return is_string($template) && trim($template) !== '' ? $template : null;
    }

    public static function usesLiveTemplate(OrderItem $item): bool
    {
        $item->loadMissing('product');
        $template = $item->product?->production_prompt_template;

        return is_string($template) && trim($template) !== '';
    }

    /** @return array<string, array{label: string, example: string}> */
    public static function supportedVariables(): array
    {
        return [
            'order_number' => ['label' => 'رقم الطلب', 'example' => 'HK-2026-ABC123'],
            'product_name' => ['label' => 'اسم المنتج', 'example' => 'ستيكر مخصص باسم وصورة طفلك'],
            'child_full_name' => ['label' => 'اسم الطفل كاملًا', 'example' => 'Roqaya Ahmed Ali'],
            'sticker_name' => ['label' => 'الاسم كما سيظهر على الاستيكر', 'example' => 'Roqaya Ahmed Ali'],
            'name_language' => ['label' => 'لغة الاسم', 'example' => 'ENGLISH'],
            'school_name' => ['label' => 'اسم المدرسة', 'example' => 'HeroKid School'],
            'class_name' => ['label' => 'الفصل أو المرحلة', 'example' => 'Class 3A'],
            'child_age' => ['label' => 'عمر الطفل', 'example' => '8'],
            'child_gender' => ['label' => 'جنس الطفل', 'example' => 'GIRL'],
            'special_notes' => ['label' => 'ملاحظات ولي الأمر', 'example' => 'Use the blue uniform'],
            'photos_count' => ['label' => 'عدد الصور المرفقة', 'example' => '3'],
            'preferred_photo' => ['label' => 'الصورة الأساسية المفضلة', 'example' => 'Choose the clearest photo'],
            'child_image_references' => ['label' => 'روابط صور الطفل الآمنة', 'example' => '1. https://hero-kid.com/orders/...'],
        ];
    }

    /** @return array<int, string> */
    public static function unsupportedVariables(string $template): array
    {
        preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $template, $matches);

        return collect($matches[1] ?? [])
            ->unique()
            ->reject(fn (string $variable): bool => array_key_exists($variable, self::supportedVariables()))
            ->map(fn (string $variable): string => '{{'.$variable.'}}')
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private static function variablesForItem(OrderItem $item): array
    {
        $order = $item->order;
        $snapshot = $item->personalization_snapshot ?? [];
        $childName = self::snapshotValue($snapshot, 'child_name') ?? $order->child_name;
        $notes = self::snapshotValue($snapshot, 'parent_notes') ?? $order->parent_notes;

        return [
            'order_number' => self::value($order->order_number),
            'product_name' => self::value($item->title),
            'child_full_name' => self::value($childName),
            'sticker_name' => self::value($childName),
            'name_language' => self::nameLanguage($childName),
            'school_name' => self::value(self::snapshotValue($snapshot, 'school_name')),
            'class_name' => self::value(self::snapshotValue($snapshot, 'class_name')),
            'child_age' => self::value(self::snapshotValue($snapshot, 'child_age') ?? $order->child_age),
            'child_gender' => self::gender(self::snapshotValue($snapshot, 'child_gender') ?? $order->child_gender),
            'special_notes' => self::value($notes),
            'photos_count' => (string) count(array_values(array_filter($order->uploaded_photos ?? [], 'is_string'))),
            'preferred_photo' => 'Choose the clearest attached photo unless the order notes explicitly identify another photo.',
            'child_image_references' => self::childImageReferences($order),
        ];
    }

    private static function snapshotValue(array $snapshot, string $key): mixed
    {
        $field = data_get($snapshot, 'fields.'.$key);

        if (is_array($field) && array_key_exists('value', $field)) {
            return $field['value'];
        }

        return $snapshot[$key] ?? null;
    }

    private static function childImageReferences(Order $order): string
    {
        $photos = array_values(array_filter($order->uploaded_photos ?? [], 'is_string'));

        if ($photos === []) {
            return 'No child images are attached. Stop and request the required photos before production.';
        }

        return collect($photos)
            ->map(fn (string $photo, int $index): string => ($index + 1).'. '.URL::signedRoute('orders.production-photo', [
                'order' => $order,
                'index' => $index,
            ]))
            ->implode("\n");
    }

    private static function value(mixed $value): string
    {
        $cleaned = Str::squish(strip_tags((string) ($value ?? '')));

        return $cleaned !== '' ? $cleaned : self::NOT_AVAILABLE;
    }

    private static function gender(mixed $gender): string
    {
        return match ($gender) {
            'boy' => 'BOY',
            'girl' => 'GIRL',
            default => self::NOT_AVAILABLE,
        };
    }

    private static function nameLanguage(mixed $name): string
    {
        $name = (string) ($name ?? '');

        if ($name === '') {
            return self::NOT_AVAILABLE;
        }

        return preg_match('/\p{Arabic}/u', $name) === 1 ? 'ARABIC' : 'ENGLISH';
    }
}
