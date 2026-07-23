<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Models\ChildIdentityShareEvent;
use App\Models\Order;
use Illuminate\Support\Carbon;

class ChildIdentityShareReportService
{
    public function build(?string $from = null, ?string $to = null): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : now()->subDays(29)->startOfDay();
        $end = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        $shares = ChildIdentityShare::query()
            ->whereBetween('created_at', [$start, $end])
            ->withCount([
                'events as referred_identities_count' => fn ($query) => $query
                    ->where('event_type', 'share.identity_started')
                    ->whereNotNull('referred_child_identity_request_id'),
                'events as referred_orders_count' => fn ($query) => $query
                    ->where('event_type', 'share.order_created')
                    ->whereNotNull('referred_order_id'),
            ])
            ->get();
        $events = ChildIdentityShareEvent::query()
            ->whereBetween('occurred_at', [$start, $end])
            ->get();
        $orderIds = $events->where('event_type', 'share.order_created')->pluck('referred_order_id')->filter()->unique();
        $referredCheckouts = Order::query()
            ->whereIn('id', $orderIds)
            ->get()
            ->groupBy(fn (Order $order): string => $order->checkoutGroupKey());
        $revenue = $referredCheckouts->sum(
            fn ($checkout): float => (float) data_get($checkout->first()?->delivery_details, 'total', 0),
        );
        $approved = ChildIdentityRequest::query()
            ->whereNotNull('approved_attempt_id')
            ->whereBetween('created_at', [$start, $end])
            ->count();
        $views = $events->where('event_type', 'share.page_viewed')->count();
        $clicks = $events->where('event_type', 'share.cta_clicked')->count();
        $starts = $events->where('event_type', 'share.identity_started')->unique('referred_child_identity_request_id')->count();
        $completed = $events->where('event_type', 'share.identity_generated')->unique('referred_child_identity_request_id')->count();
        $orders = $referredCheckouts->count();
        $channelEvents = $events->filter(fn ($event) => in_array($event->event_type, [
            'share.native_opened',
            'share.whatsapp_clicked',
            'share.facebook_clicked',
            'share.instagram_clicked',
            'share.link_copied',
            'share.caption_copied',
            'share.image_saved',
        ], true));
        $channels = $channelEvents->groupBy(fn ($event) => $event->channel ?: 'unknown')
            ->map(fn ($items, string $channel): array => ['channel' => $channel, 'events' => $items->count()])
            ->sortByDesc('events')
            ->values();
        $topShares = $shares->sortByDesc(fn (ChildIdentityShare $share): int => (
            $share->total_orders * 1000
            + $share->total_identity_starts * 100
            + $share->total_cta_clicks * 10
            + $share->total_views
        ))->take(20)->values();

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'summary' => [
                'shares' => $shares->count(),
                'share_rate' => $approved > 0 ? round(($shares->count() / $approved) * 100, 1) : 0,
                'views' => $views,
                'cta_clicks' => $clicks,
                'ctr' => $views > 0 ? round(($clicks / $views) * 100, 1) : 0,
                'identity_starts' => $starts,
                'identity_completions' => $completed,
                'orders' => $orders,
                'revenue' => $revenue,
                'viral_conversion_rate' => $views > 0 ? round(($orders / $views) * 100, 2) : 0,
                'average_referred_identities' => $shares->count() > 0 ? round($starts / $shares->count(), 2) : 0,
                'best_channel' => data_get($channels->first(), 'channel', '—'),
            ],
            'channels' => $channels,
            'top_shares' => $topShares,
        ];
    }
}
