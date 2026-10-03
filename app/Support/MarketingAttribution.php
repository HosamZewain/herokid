<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Str;

/** Marketing evidence is separate from the channel used to create an order. */
class MarketingAttribution
{
    public const QUERY_KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'campaign_id', 'adset_id', 'ad_id', 'campaign_name', 'adset_name', 'ad_name', 'fbclid',
    ];

    public const KEYS = [...self::QUERY_KEYS, 'landing_url', 'referrer'];

    public static function sanitize(array $values): array
    {
        $result = [];
        foreach (self::KEYS as $key) {
            $value = $values[$key] ?? null;
            if (! is_string($value) || trim($value) === '' || str_contains($value, '{{')) {
                continue;
            }
            $limit = in_array($key, ['landing_url', 'referrer'], true) ? 2000 : ($key === 'fbclid' ? 512 : 255);
            $result[$key] = Str::limit(trim($value), $limit, '');
        }

        return $result;
    }

    public static function forOrder(Order $order): array
    {
        $snapshot = data_get($order->delivery_details, 'marketing_attribution', []);
        $snapshot = is_array($snapshot) ? self::sanitize($snapshot) : [];
        // Older orders may have evidence only in the converted cart. Never rewrite them on reads.
        if ($snapshot === []) {
            $snapshot = self::sanitize($order->marketingCart?->only(self::KEYS) ?? []);
        }

        return self::present($snapshot, $order->order_source);
    }

    public static function present(array $snapshot, ?string $channel = 'website'): array
    {
        $data = self::sanitize($snapshot);
        $source = strtolower($data['utm_source'] ?? '');
        $medium = strtolower($data['utm_medium'] ?? '');
        $referrerHost = self::host($data['referrer'] ?? '');
        $landingHost = self::host($data['landing_url'] ?? '');
        $meta = in_array($source, ['meta', 'facebook', 'instagram', 'fb', 'ig', 'an', 'msg', 'messenger', 'facebook_ads', 'instagram_ads'], true)
            || ($source === '' && (isset($data['fbclid']) || self::isMetaHost($referrerHost)));
        $paid = in_array($medium, ['paid_social', 'paid-social', 'paidsocial', 'paid', 'cpc', 'ppc', 'cpm', 'social_ads', 'facebook_display_ad'], true);

        $type = 'unknown';
        $label = 'غير معروف — لا توجد بيانات تتبع';
        if ($meta) {
            $type = $paid || isset($data['ad_id']) ? 'meta_ad' : 'meta';
            $label = $type === 'meta_ad' ? 'إعلان ميتا' : 'ميتا — نوع الزيارة غير مؤكد';
        } elseif ($source !== '') {
            $type = 'campaign';
            $label = $data['utm_source'].($paid ? ' — إعلان' : '');
        } elseif ($referrerHost !== '' && $referrerHost !== $landingHost) {
            $type = 'referral';
            $label = 'إحالة من '.$referrerHost;
        } elseif ($landingHost !== '' && $referrerHost === '') {
            $type = 'direct';
            $label = 'مباشر / بدون تتبع';
        } elseif ($channel && $channel !== 'website') {
            $type = 'channel';
            $label = OrderSource::label($channel);
        }

        return [
            'type' => $type,
            'label' => $label,
            'ad_name' => $data['ad_name'] ?? null,
            'ad_id' => $data['ad_id'] ?? null,
            'campaign_name' => $data['campaign_name'] ?? null,
            'campaign_id' => $data['campaign_id'] ?? null,
            'adset_name' => $data['adset_name'] ?? null,
            'adset_id' => $data['adset_id'] ?? null,
            'campaign' => $data['utm_campaign'] ?? null,
            'source' => $data['utm_source'] ?? null,
            'medium' => $data['utm_medium'] ?? null,
            'content' => $data['utm_content'] ?? null,
            'term' => $data['utm_term'] ?? null,
            'meta_click' => isset($data['fbclid']),
            'referrer_host' => $referrerHost ?: null,
            // Raw URLs can contain private checkout/reset tokens. Show domains only.
            'landing_site' => $landingHost ?: null,
        ];
    }

    private static function host(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    private static function isMetaHost(string $host): bool
    {
        foreach (['facebook.com', 'instagram.com', 'messenger.com'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
