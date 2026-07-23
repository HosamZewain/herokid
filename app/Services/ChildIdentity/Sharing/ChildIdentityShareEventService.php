<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Models\ChildIdentityShareEvent;
use App\Models\Order;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChildIdentityShareEventService
{
    private const COUNTERS = [
        'share.page_viewed' => 'total_views',
        'share.cta_clicked' => 'total_cta_clicks',
        'share.identity_started' => 'total_identity_starts',
        'share.identity_generated' => 'total_identity_completions',
        'share.order_created' => 'total_orders',
    ];

    public function record(
        ChildIdentityShare $share,
        string $eventType,
        ?Request $request = null,
        ?string $channel = null,
        ?ChildIdentityRequest $referredIdentity = null,
        ?Order $referredOrder = null,
        array $metadata = [],
    ): ChildIdentityShareEvent {
        $event = $share->events()->create([
            'event_type' => $eventType,
            'channel' => $channel,
            'anonymous_visitor_id' => $request ? $this->anonymousVisitorHash($request) : null,
            'referred_child_identity_request_id' => $referredIdentity?->id,
            'referred_order_id' => $referredOrder?->id,
            'utm_source' => $this->queryValue($request, 'utm_source'),
            'utm_medium' => $this->queryValue($request, 'utm_medium'),
            'utm_campaign' => $this->queryValue($request, 'utm_campaign'),
            'referrer_host' => $this->referrerHost($request),
            'metadata' => AdminActivityLogger::sanitize($metadata),
            'occurred_at' => now(),
        ]);

        if ($column = self::COUNTERS[$eventType] ?? null) {
            $share->increment($column);
        }

        $updates = [];
        if ($eventType === 'share.page_viewed') {
            $updates['last_viewed_at'] = now();
        }
        if (str_ends_with($eventType, '_clicked') || $eventType === 'share.native_opened') {
            $updates['last_shared_at'] = now();
        }
        if ($updates !== []) {
            $share->forceFill($updates)->saveQuietly();
        }

        return $event;
    }

    public function recordFunnelOnce(
        ChildIdentityShare $share,
        string $eventType,
        ChildIdentityRequest $identity,
        ?Order $order = null,
    ): void {
        DB::transaction(function () use ($share, $eventType, $identity, $order): void {
            $lockedShare = ChildIdentityShare::withTrashed()->lockForUpdate()->find($share->id);
            if (! $lockedShare) {
                return;
            }
            $exists = $lockedShare->events()
                ->where('event_type', $eventType)
                ->where('referred_child_identity_request_id', $identity->id)
                ->when($order, fn ($query) => $query->where('referred_order_id', $order->id))
                ->exists();

            if (! $exists) {
                $this->record($lockedShare, $eventType, referredIdentity: $identity, referredOrder: $order);
            }
        });
    }

    private function anonymousVisitorHash(Request $request): string
    {
        $visitor = (string) ($request->cookie('herokid_share_visitor') ?: $request->session()->get('herokid_share_visitor'));

        if (! Str::isUuid($visitor)) {
            $visitor = (string) Str::uuid();
            $request->session()->put('herokid_share_visitor', $visitor);
            Cookie::queue('herokid_share_visitor', $visitor, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'Lax');
        }

        return hash_hmac('sha256', $visitor, (string) config('app.key'));
    }

    private function referrerHost(?Request $request): ?string
    {
        $host = $request ? parse_url((string) $request->headers->get('referer'), PHP_URL_HOST) : null;

        return is_string($host) ? Str::limit(mb_strtolower($host), 255, '') : null;
    }

    private function queryValue(?Request $request, string $key): ?string
    {
        $value = $request?->query($key);

        return is_string($value) && $value !== '' ? Str::limit($value, 255, '') : null;
    }
}
