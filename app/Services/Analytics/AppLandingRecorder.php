<?php

namespace App\Services\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AppLandingRecorder
{
    public const COOKIE = 'hk_app_visitor';

    public function record(Request $request, ?string $platform = null): void
    {
        if (! $this->eligible($request)) {
            return;
        }
        $rateKey = 'app-landing:'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        if (RateLimiter::tooManyAttempts($rateKey, 120)) {
            return;
        }
        RateLimiter::hit($rateKey, 60);

        $visitor = $request->cookie(self::COOKIE) ?: $request->session()->get(self::COOKIE);
        if (! is_string($visitor) || ! preg_match('/^[a-f0-9]{64}$/', $visitor)) {
            // Stable across racing first-visit and click requests in the same
            // existing web session, without storing the actual session ID.
            $visitor = hash_hmac('sha256', 'app-landing:'.$request->session()->getId(), (string) config('app.key'));
        }
        $request->session()->put(self::COOKIE, $visitor);
        if (! $request->cookie(self::COOKIE)) {
            $secure = $request->isSecure() || parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https';
            Cookie::queue(cookie(self::COOKIE, $visitor, 90 * 24 * 60, '/', null, $secure, true, false, 'lax'));
        }

        $view = $request->query('v');
        if (! is_string($view) || ! Str::isUuid($view)) {
            // JS-free clicks: suppress repeated taps within one short time bucket.
            $view = 'fallback:'.intdiv(now()->timestamp, 10);
        }
        $hash = hash_hmac('sha256', $visitor, (string) config('app.key'));
        $now = now('UTC');
        $types = $platform === null ? ['view'] : ['view', $platform.'_click'];
        $rows = [];
        foreach ($types as $type) {
            $rows[] = [
                'dedup_key' => hash('sha256', $hash.'|'.$view.'|'.$type),
                'visitor_hash' => $hash,
                'event_type' => $type,
                'occurred_on' => $now->copy()->timezone('Africa/Cairo')->toDateString(),
                'occurred_at' => $now,
            ];
        }
        // One atomic statement; concurrent retries cannot double count a page view.
        DB::table('app_landing_events')->insertOrIgnore($rows);
    }

    private function eligible(Request $request): bool
    {
        if ($request->method() !== 'GET' || $request->user()?->role === 'admin'
            || $request->header('DNT') === '1' || $request->header('Sec-GPC') === '1') {
            return false;
        }
        if (preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|facebookexternalhit|whatsapp|curl|wget|python|uptime|monitor/i', (string) $request->userAgent())
            || preg_match('/prefetch|prerender/i', $request->header('Purpose', '').' '.$request->header('Sec-Purpose', ''))
            || $request->header('Sec-Fetch-Site') === 'cross-site') {
            return false;
        }
        // Only interactions from this page count, not link scanners or direct URLs.
        $referrer = parse_url((string) $request->header('referer'));

        return is_array($referrer)
            && strtolower((string) ($referrer['host'] ?? '')) === strtolower($request->getHost())
            && rtrim((string) ($referrer['path'] ?? ''), '/') === '/app';
    }
}
