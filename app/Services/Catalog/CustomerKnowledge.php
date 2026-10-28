<?php

namespace App\Services\Catalog;

use App\Models\PricingPackage;
use App\Models\Setting;

/** Customer-facing CMS placeholders; never evaluates code or arbitrary setting keys. */
class CustomerKnowledge
{
    public static function text(?string $value, int $limit = 1000): string
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/<\s*(script|style)\b[^>]*>.*?<\/\1>/isu', '', $value);
        $value = strip_tags($value);
        $value = strtr($value, array_combine(mb_str_split('٠١٢٣٤٥٦٧٨٩'), str_split('0123456789')));

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value)), 0, $limit);
    }

    public static function money(float|int $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public function policy(string $key): string
    {
        if (! in_array($key, ['payment', 'delivery', 'privacy', 'photos', 'preview', 'tracking', 'library', 'identity'], true)) {
            return '';
        }

        $text = (string) Setting::query()->where('key', 'customer_policy_'.$key)->value('value');
        $formats = collect(config('photo_uploads.allowed_mimes', []))->map(fn ($mime) => strtoupper(str_replace('-sequence', '', substr($mime, strpos($mime, '/') + 1))))->unique()->implode('، ');

        return self::text(strtr($text, [
            '{{photo_min}}' => (string) config('photo_uploads.min_files', 2),
            '{{photo_max}}' => (string) config('photo_uploads.max_files', 3),
            '{{photo_mb}}' => (string) config('photo_uploads.max_size_mb', 15),
            '{{photo_formats}}' => $formats,
        ]), 3000);
    }

    public function render(string $text): string
    {
        return preg_replace_callback('/\{\{(policy|package_price):([a-z0-9_-]+)\}\}/', function ($match) {
            if ($match[1] === 'policy') {
                return $this->policy($match[2]);
            }
            $package = PricingPackage::active()->where('show_in_store', true)->where('slug', $match[2])->first();

            return $package ? self::money((float) $package->price).' ج.م' : 'راجع الباقات المتاحة حاليًا';
        }, $text);
    }
}
