<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class StoryProductionPrompt
{
    public const SETTING_KEY = 'story_production_prompt_template';

    public const MAX_TEMPLATE_LENGTH = 65000;

    private const NOT_AVAILABLE = 'Not available';

    private const CHILD_IMAGES_START = '<!-- HERO_KID_CHILD_IMAGES_START -->';

    private const CHILD_IMAGES_END = '<!-- HERO_KID_CHILD_IMAGES_END -->';

    private const APPROVED_IDENTITY_START = '<!-- HERO_KID_APPROVED_IDENTITY_START -->';

    private const APPROVED_IDENTITY_END = '<!-- HERO_KID_APPROVED_IDENTITY_END -->';

    private const ORDER_DETAILS_START = '<!-- HERO_KID_ORDER_DETAILS_START -->';

    private const ORDER_DETAILS_END = '<!-- HERO_KID_ORDER_DETAILS_END -->';

    public static function forOrder(Order $order, bool $useOverride = true): string
    {
        $order->loadMissing(['story', 'productionPromptOverride', 'childIdentityApprovedAttempt', 'childIdentityRequest']);

        if ($useOverride && $order->productionPromptOverride) {
            return self::withCurrentIdentityReferences(
                self::withCurrentChildImageReferences($order->productionPromptOverride->prompt_text, $order),
                $order,
            );
        }

        return self::withCurrentIdentityReferences(
            self::withCurrentChildImageReferences(
                self::renderForOrder($order, self::activeTemplate()),
                $order,
            ),
            $order,
        );
    }

    public static function renderForOrder(Order $order, string $template): string
    {
        $values = self::variablesForOrder($order);

        return preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', function (array $matches) use ($values): string {
            $name = $matches[1];

            return array_key_exists($name, $values) ? $values[$name] : $matches[0];
        }, $template) ?? $template;
    }

    public static function activeTemplate(): string
    {
        $template = Setting::where('key', self::SETTING_KEY)->value('value');

        return is_string($template) && trim($template) !== ''
            ? $template
            : self::defaultTemplate();
    }

    public static function templateSetting(): ?Setting
    {
        return Setting::with('editor')->where('key', self::SETTING_KEY)->first();
    }

    public static function defaultTemplate(): string
    {
        return DefaultStoryProductionPromptTemplate::text();
    }

    public static function supportedVariables(): array
    {
        return [
            'order_number' => ['label' => 'رقم الطلب', 'example' => 'HK-2026-ABC123'],
            'order_url' => ['label' => 'رابط صفحة الطلب داخل الإدارة', 'example' => 'https://hero-kid.com/admin/orders/1'],
            'child_name' => ['label' => 'اسم الطفل', 'example' => 'رينا'],
            'child_age' => ['label' => 'عمر الطفل الفعلي', 'example' => '6'],
            'child_gender' => ['label' => 'جنس الطفل', 'example' => 'Girl'],
            'child_interests' => ['label' => 'اهتمامات الطفل كما كتبها ولي الأمر', 'example' => 'الرسم والفضاء'],
            'child_image_references' => ['label' => 'روابط صور الطفل الآمنة للإنتاج', 'example' => '1. https://example.com/orders/1/production-photos/0?...'],
            'approved_child_identity_reference' => ['label' => 'رابط هوية الطفل المعتمدة', 'example' => 'https://example.com/orders/1/approved-identity?...'],
            'story_title' => ['label' => 'عنوان القصة المختارة', 'example' => 'رحلة القمر قبل النوم'],
            'story_age_range' => ['label' => 'الفئة العمرية للقصة', 'example' => '6-9 سنوات'],
            'story_short_description' => ['label' => 'وصف القصة القصير', 'example' => 'قصة هادئة قبل النوم'],
            'story_full_content' => ['label' => 'وصف القصة الكامل أو محتوى القصة', 'example' => 'مغامرة كاملة...'],
            'story_educational_value' => ['label' => 'القيمة أو الدرس التربوي', 'example' => 'الشجاعة'],
            'dedication' => ['label' => 'نص الإهداء', 'example' => 'إلى بطلتنا الجميلة'],
            'customer_notes' => ['label' => 'ملاحظات ولي الأمر', 'example' => 'يفضل الألوان الهادئة'],
            'production_language' => ['label' => 'لغة إنتاج القصة', 'example' => 'Arabic'],
            'current_date' => ['label' => 'تاريخ اليوم', 'example' => now()->toDateString()],
        ];
    }

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

    public static function templateUpdatedAt(): ?Carbon
    {
        return self::templateSetting()?->updated_at;
    }

    private static function variablesForOrder(Order $order): array
    {
        $order->loadMissing(['story', 'childIdentityApprovedAttempt', 'childIdentityRequest']);
        $story = $order->story;

        return [
            'order_number' => self::value($order->order_number),
            'order_url' => route('admin.orders.groups.show', $order),
            'child_name' => self::value($order->child_name),
            'child_age' => self::value($order->child_age ?? $order->childIdentityRequest?->age_range),
            'child_gender' => self::gender($order->child_gender),
            'child_interests' => self::rawValue($order->interests),
            'child_image_references' => self::childImageReferences($order),
            'approved_child_identity_reference' => self::approvedIdentityReference($order),
            'story_title' => self::value($story?->title),
            'story_age_range' => self::value($story?->age_range),
            'story_short_description' => self::value($story?->short_desc),
            'story_full_content' => self::storyContent($story?->full_story ?? $story?->full_desc),
            'story_educational_value' => self::value($order->lesson ?? $story?->lesson_value),
            'dedication' => self::value($order->gift_note),
            'customer_notes' => self::value($order->parent_notes),
            'production_language' => self::language($order->language ?? $story?->language),
            'current_date' => now()->toDateString(),
        ];
    }

    private static function value(mixed $value): string
    {
        if ($value === null) {
            return self::NOT_AVAILABLE;
        }

        $cleaned = self::cleanText((string) $value);

        return $cleaned === '' ? self::NOT_AVAILABLE : $cleaned;
    }

    private static function rawValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::NOT_AVAILABLE;
        }

        return (string) $value;
    }

    private static function storyContent(?string $value): string
    {
        if ($value === null) {
            return self::NOT_AVAILABLE;
        }

        $cleaned = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $cleaned = preg_replace("/[ \t]+\n/", "\n", $cleaned) ?? '';
        $cleaned = preg_replace("/\n{3,}/", "\n\n", $cleaned) ?? '';

        return $cleaned === '' ? self::NOT_AVAILABLE : $cleaned;
    }

    private static function cleanText(string $value): string
    {
        return Str::squish(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function language(?string $language): string
    {
        return match ($language) {
            'ar' => 'Arabic',
            'en' => 'English',
            default => self::value($language),
        };
    }

    private static function gender(?string $gender): string
    {
        return match ($gender) {
            'boy' => 'Boy',
            'girl' => 'Girl',
            default => self::value($gender),
        };
    }

    private static function childImageReferences(Order $order): string
    {
        $photos = array_values(array_filter($order->uploaded_photos ?? [], 'is_string'));

        if ($photos === []) {
            return 'No child images were attached to this order.';
        }

        $references = collect($photos)
            ->map(fn (string $photo, int $index): string => ($index + 1).'. '.URL::signedRoute('orders.production-photo', [
                'order' => $order,
                'index' => $index,
            ]))
            ->implode("\n");

        return self::CHILD_IMAGES_START."\n".$references."\n".self::CHILD_IMAGES_END;
    }

    private static function withCurrentChildImageReferences(string $prompt, Order $order): string
    {
        $photos = array_values(array_filter($order->uploaded_photos ?? [], 'is_string'));

        if ($photos === []) {
            return $prompt;
        }

        $references = self::childImageReferences($order);
        $pattern = '/'.preg_quote(self::CHILD_IMAGES_START, '/').'.*?'.preg_quote(self::CHILD_IMAGES_END, '/').'/s';

        if (preg_match($pattern, $prompt) === 1) {
            return preg_replace($pattern, $references, $prompt, 1) ?? $prompt;
        }

        return rtrim($prompt)."\n\n## Current Child Image References\n"
            .'This managed list is updated automatically from the order photos.'
            ."\n\n".$references;
    }

    /**
     * A manual prompt override is preserved verbatim, while this managed block
     * keeps its operational personalization data synchronized with the order.
     */
    public static function withCurrentOrderDetails(string $prompt, Order $order): string
    {
        $values = self::variablesForOrder($order);
        $details = self::ORDER_DETAILS_START."\n"
            ."## Current Order Personalization (managed automatically)\n"
            ."Use these current values if any older wording elsewhere in this override conflicts with them.\n"
            .'- Child name: '.$values['child_name']."\n"
            .'- Child age: '.$values['child_age']."\n"
            .'- Child gender: '.$values['child_gender']."\n"
            .'- Child interests: '.$values['child_interests']."\n"
            .'- Dedication: '.$values['dedication']."\n"
            .'- Customer notes: '.$values['customer_notes']."\n"
            .self::ORDER_DETAILS_END;
        $pattern = '/'.preg_quote(self::ORDER_DETAILS_START, '/').'.*?'.preg_quote(self::ORDER_DETAILS_END, '/').'/s';

        if (preg_match($pattern, $prompt) === 1) {
            return preg_replace($pattern, $details, $prompt, 1) ?? $prompt;
        }

        return rtrim($prompt)."\n\n".$details;
    }

    private static function approvedIdentityReference(Order $order): string
    {
        $attempt = $order->childIdentityApprovedAttempt;

        if (! $attempt || $attempt->status !== 'succeeded' || ! $attempt->output_storage_path) {
            return 'No approved child identity is linked to this order.';
        }

        return self::APPROVED_IDENTITY_START."\n"
            .URL::signedRoute('orders.approved-child-identity', ['order' => $order])
            ."\n".self::APPROVED_IDENTITY_END;
    }

    private static function withCurrentIdentityReferences(string $prompt, Order $order): string
    {
        $attempt = $order->childIdentityApprovedAttempt;

        if (! $attempt || $attempt->status !== 'succeeded' || ! $attempt->output_storage_path) {
            return $prompt;
        }

        $reference = self::approvedIdentityReference($order);
        $pattern = '/'.preg_quote(self::APPROVED_IDENTITY_START, '/').'.*?'.preg_quote(self::APPROVED_IDENTITY_END, '/').'/s';

        if (preg_match($pattern, $prompt) === 1) {
            return preg_replace($pattern, $reference, $prompt, 1) ?? $prompt;
        }

        return rtrim($prompt)."\n\n## Approved Child Identity Reference\n"
            .'Use this approved identity as a separate secure visual reference while retaining every original child photo above.'
            ."\n\n".$reference;
    }
}
