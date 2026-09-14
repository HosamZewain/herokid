<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProductionComponent;
use App\Models\ProductProductionComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ProductProductionPrompt
{
    public const MAX_TEMPLATE_LENGTH = 65000;

    private const NOT_AVAILABLE = '[MISSING — CONFIRM BEFORE PRODUCTION]';

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function forOrder(Order $order): Collection
    {
        $order->loadMissing([
            'items.product.productionComponents',
            'items.productionComponents',
        ]);

        return $order->items
            ->flatMap(fn (OrderItem $item): Collection => self::forItem($item))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public static function forItem(OrderItem $item): Collection
    {
        $definitions = self::componentDefinitionsForItem($item);
        $componentCount = $definitions->count();

        return $definitions->map(function (array $definition) use ($item, $componentCount): array {
            $componentKey = $definition['stable_key'];
            $unitKey = $componentCount === 1
                ? 'product:'.$item->id
                : 'product:'.$item->id.':component:'.$componentKey;

            return [
                'item' => $item,
                'component' => $definition['component'],
                'component_key' => $componentKey,
                'component_name' => $definition['name'],
                'component_count' => $componentCount,
                'quantity_per_item' => $definition['quantity_per_item'],
                'quantity' => max(1, (int) $item->quantity) * $definition['quantity_per_item'],
                'unit_key' => $unitKey,
                'prompt' => self::renderTemplate($item, $definition),
                'prompt_source' => $definition['source'],
                'uses_live_template' => str_starts_with($definition['source'], 'live_'),
                'uses_snapshot' => $definition['source'] === 'order_component_snapshot',
                'source_label' => match ($definition['source']) {
                    'order_component_snapshot' => 'نسخة محفوظة مع الطلب',
                    'live_component_template' => 'قالب جزء المنتج الحالي',
                    'live_product_template' => 'قالب المنتج الحالي — يتحدّث تلقائيًا',
                    default => 'نسخة تاريخية احتياطية',
                },
            ];
        })->values();
    }

    public static function renderForItem(
        OrderItem $item,
        OrderItemProductionComponent|ProductProductionComponent|string|null $component = null,
    ): string {
        $definitions = self::componentDefinitionsForItem($item);
        $componentKey = is_string($component) ? $component : $component?->stable_key;
        $definition = $componentKey
            ? $definitions->firstWhere('stable_key', $componentKey)
            : $definitions->first();

        return $definition ? self::renderTemplate($item, $definition) : '';
    }

    private static function renderTemplate(OrderItem $item, array $definition): string
    {
        $item->loadMissing(['order.checkoutReference', 'product']);
        $template = $definition['prompt_template'];
        $values = self::variablesForItem($item, $definition);

        return preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', function (array $matches) use ($values): string {
            return array_key_exists($matches[1], $values) ? $values[$matches[1]] : $matches[0];
        }, $template) ?? $template;
    }

    public static function templateForItem(OrderItem $item): ?string
    {
        return self::componentDefinitionsForItem($item)->first()['prompt_template'] ?? null;
    }

    public static function usesLiveTemplate(OrderItem $item): bool
    {
        $source = self::componentDefinitionsForItem($item)->first()['source'] ?? null;

        return is_string($source) && str_starts_with($source, 'live_');
    }

    /** @return array<string, array{label: string, example: string}> */
    public static function supportedVariables(): array
    {
        return [
            'order_number' => ['label' => 'رقم الطلب المختصر', 'example' => 'HK08-151'],
            'product_name' => ['label' => 'اسم المنتج', 'example' => 'ستيكر مخصص باسم وصورة طفلك'],
            'component_name' => ['label' => 'اسم جزء الإنتاج', 'example' => 'الاستيكرات الكبيرة'],
            'component_quantity' => ['label' => 'الكمية المطلوبة من هذا الجزء', 'example' => '2'],
            'product_quantity' => ['label' => 'عدد وحدات المنتج المشتراة', 'example' => '1'],
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
    private static function variablesForItem(OrderItem $item, array $definition): array
    {
        $order = $item->order;
        $snapshot = $item->personalization_snapshot ?? [];
        $childName = self::snapshotValue($snapshot, 'child_name') ?? $order->child_name;
        $notes = self::snapshotValue($snapshot, 'parent_notes') ?? $order->parent_notes;

        return [
            'order_number' => self::value($order->checkoutReference?->short_reference ?: $order->order_number),
            'product_name' => self::value($item->title),
            'component_name' => self::value($definition['name']),
            'component_quantity' => (string) (max(1, (int) $item->quantity) * $definition['quantity_per_item']),
            'product_quantity' => (string) max(1, (int) $item->quantity),
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

    /** @return Collection<int, array<string, mixed>> */
    private static function componentDefinitionsForItem(OrderItem $item): Collection
    {
        $item->loadMissing([
            'product.productionComponents',
            'productionComponents',
        ]);

        if ($item->productionComponents->isNotEmpty()) {
            return $item->productionComponents->map(fn (OrderItemProductionComponent $component): array => [
                'component' => $component,
                'stable_key' => $component->stable_key,
                'name' => $component->name,
                'prompt_template' => $component->prompt_template,
                'quantity_per_item' => max(1, (int) $component->quantity_per_item),
                'source' => 'order_component_snapshot',
            ])->values();
        }

        if ($item->product) {
            $components = $item->product->productionComponents
                ->where('is_active', true)
                ->filter(fn (ProductProductionComponent $component): bool => filled($component->prompt_template))
                ->values();

            if ($components->isNotEmpty()) {
                return $components->map(fn (ProductProductionComponent $component): array => [
                    'component' => $component,
                    'stable_key' => $component->stable_key,
                    'name' => $component->name,
                    'prompt_template' => $component->prompt_template,
                    'quantity_per_item' => max(1, (int) $component->quantity_per_item),
                    'source' => 'live_component_template',
                ])->values();
            }

            $template = $item->product->production_prompt_template;

            return is_string($template) && trim($template) !== ''
                ? collect([[
                    'component' => null,
                    'stable_key' => 'main',
                    'name' => $item->title ?: $item->product->name_ar,
                    'prompt_template' => $template,
                    'quantity_per_item' => 1,
                    'source' => 'live_product_template',
                ]])
                : collect();
        }

        $template = data_get($item->item_snapshot, 'production_prompt_template');

        return is_string($template) && trim($template) !== ''
            ? collect([[
                'component' => null,
                'stable_key' => 'main',
                'name' => $item->title ?: 'منتج تاريخي',
                'prompt_template' => $template,
                'quantity_per_item' => 1,
                'source' => 'historical_snapshot',
            ]])
            : collect();
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
