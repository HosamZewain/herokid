<?php

namespace App\Services\Analytics;

use App\Support\AppDateTime;
use App\Support\MarketingAttribution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class StorefrontTraffic
{
    public static function publicCommerce(Request $request): bool
    {
        return $request->routeIs('home', 'shop.*', 'stories.index', 'stories.show', 'packages', 'cart.index', 'checkout.success')
            && $request->user()?->role !== 'admin'
            && $request->header('DNT') !== '1' && $request->header('Sec-GPC') !== '1';
    }

    public function record(Request $request, Response $response): void
    {
        if (! self::publicCommerce($request) || ! $request->isMethod('GET') || ! $response->isSuccessful()
            || ! str_contains($response->headers->get('Content-Type', ''), 'text/html')
            || preg_match('/bot|crawler|spider|headless|preview|facebookexternalhit/i', (string) $request->userAgent())
            || str_contains(strtolower($request->header('Sec-Purpose', $request->header('Purpose', ''))), 'prefetch')) {
            return;
        }
        try {
            $current = MarketingAttribution::sanitize($request->query->all() + ['referrer' => $request->header('referer', '')]);
            $touch = array_intersect_key($current, array_flip(MarketingAttribution::QUERY_KEYS));
            $signature = $touch === [] ? null : hash('sha256', json_encode($touch));
            $visit = $request->session()->get('storefront_visit', []);
            $now = now()->timestamp;
            if (is_array($visit) && ($visit['last'] ?? 0) > $now - 1800 && ($signature === null || $signature === ($visit['touch'] ?? null))) {
                $request->session()->put('storefront_visit.last', $now);

                return;
            }
            $data = MarketingAttribution::present($current + ['landing_url' => $request->url()]);
            DB::table('storefront_visits')->insert([
                'visit_key' => hash_hmac('sha256', (string) Str::uuid(), config('app.key')),
                'source_type' => $data['type'], 'source' => $data['source'] ?? (in_array($data['type'], ['meta', 'meta_ad'], true) ? 'meta' : null), 'medium' => $data['medium'],
                'campaign' => $data['campaign'], 'landing_path' => Str::limit('/'.ltrim($request->path(), '/'), 512, ''), 'started_at' => now(),
            ]);
            $request->session()->put('storefront_visit', ['last' => $now, 'touch' => $signature]);
        } catch (\Throwable $e) {
            // Analytics must never prevent browsing or expose request payloads.
            Log::warning('Storefront visit recording unavailable.', ['error_type' => $e::class]);
        }
    }

    public function report(AnalyticsDateRange $range): array
    {
        $rows = DB::table('storefront_visits')
            ->whereBetween('started_at', [AppDateTime::utcStartOfDay($range->startDate), AppDateTime::utcEndOfDay($range->endDate)])
            ->selectRaw('source_type, source, medium, COUNT(*) as visits')->groupBy('source_type', 'source', 'medium')
            ->orderByDesc('visits')->get()->map(fn ($row) => [
                'label' => $row->source_type === 'direct' ? 'مباشر / بدون تتبع' : ($row->source_type === 'referral' ? 'إحالة خارجية' : MarketingAttribution::present(['utm_source' => $row->source, 'utm_medium' => $row->medium])['label']),
                'source' => $row->source, 'medium' => $row->medium, 'visits' => (int) $row->visits,
            ])->all();

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'visits')),
            'started_at' => DB::table('storefront_visits')->min('started_at')];
    }
}
