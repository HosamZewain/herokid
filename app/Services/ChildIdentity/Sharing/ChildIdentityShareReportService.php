<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Models\ChildIdentityShareEvent;
use App\Models\Order;
use App\Services\Orders\OrderFinancialStatistics;
use App\Support\AppDateTime;

class ChildIdentityShareReportService
{
    public function build(?string $from = null, ?string $to = null): array
    {
        $from = $from ?: now(AppDateTime::timezone())->subDays(29)->toDateString();
        $to = $to ?: now(AppDateTime::timezone())->toDateString();
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $start = AppDateTime::utcStartOfDay($from);
        $end = AppDateTime::utcEndOfDay($to);
        $newShares = ChildIdentityShare::withTrashed()->whereBetween('created_at', [$start, $end])->count();
        $events = ChildIdentityShareEvent::query()
            ->whereBetween('occurred_at', [$start, $end])
            ->get();
        $orderIds = $events->where('event_type', 'share.order_created')->pluck('referred_order_id')->filter()->unique();
        $referredOrders = Order::withTrashed()
            ->whereIn('id', $orderIds)
            ->get(['id', 'checkout_group_key'])->keyBy('id');
        $financial = app(OrderFinancialStatistics::class)->checkouts($referredOrders->pluck('checkout_group_key')->unique(), true)->get();
        $approvedIds = ChildIdentityRequest::withTrashed()
            ->whereNotNull('approved_attempt_id')
            ->whereBetween('created_at', [$start, $end])
            ->pluck('id');
        $sharedApproved = ChildIdentityShare::withTrashed()->whereIn('child_identity_request_id', $approvedIds)
            ->where('created_at', '<=', $end)->distinct()->count('child_identity_request_id');
        $views = $events->where('event_type', 'share.page_viewed')->count();
        $clicks = $events->where('event_type', 'share.cta_clicked')->count();
        $starts = $events->where('event_type', 'share.identity_started')->pluck('referred_child_identity_request_id')->filter()->unique()->count();
        $completed = $events->where('event_type', 'share.identity_generated')->pluck('referred_child_identity_request_id')->filter()->unique()->count();
        $orders = $financial->count();
        // CTR is a subset of the same known visitor/share pairs, not raw repeated clicks.
        $pairs = fn ($type) => $events->where('event_type', $type)->filter(fn ($event) => filled($event->anonymous_visitor_id))
            ->map(fn ($event) => $event->child_identity_share_id.':'.$event->anonymous_visitor_id)->unique();
        $viewers = $pairs('share.page_viewed');
        $clickedViewers = $viewers->intersect($pairs('share.cta_clicked'))->count();
        // Explain raw clicks without changing the existing distinct visitor/share CTR.
        $knownViews = array_fill_keys($viewers->all(), true);
        $clickEvents = $events->where('event_type', 'share.cta_clicked');
        $anonymousClicks = $clickEvents->filter(fn ($event) => blank($event->anonymous_visitor_id))->count();
        $unmatchedClicks = $clickEvents->filter(fn ($event) => filled($event->anonymous_visitor_id)
            && ! isset($knownViews[$event->child_identity_share_id.':'.$event->anonymous_visitor_id]))->count();
        $eligibleClicks = $clicks - $anonymousClicks - $unmatchedClicks;
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
        $byShare = $events->groupBy('child_identity_share_id');
        $shares = ChildIdentityShare::withTrashed()->whereIn('id', $byShare->keys())->get();
        $topShares = $shares->map(function (ChildIdentityShare $share) use ($byShare, $referredOrders, $financial) {
            $activity = $byShare->get($share->id, collect());
            // Display-only attributes. Never overwrite persisted lifetime counters.
            $share->period_views = $activity->where('event_type', 'share.page_viewed')->count();
            $share->period_clicks = $activity->where('event_type', 'share.cta_clicked')->count();
            $share->period_identities = $activity->where('event_type', 'share.identity_started')->pluck('referred_child_identity_request_id')->filter()->unique()->count();
            $share->period_orders = $activity->where('event_type', 'share.order_created')->pluck('referred_order_id')
                ->map(fn ($id) => $referredOrders->get($id)?->checkout_group_key)->filter()->unique()->intersect($financial->pluck('checkout_group_key'))->count();

            return $share;
        })->sortBy([['period_orders', 'desc'], ['period_identities', 'desc'], ['period_clicks', 'desc'], ['period_views', 'desc'], ['id', 'asc']])->take(20)->values();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'summary' => [
                'shares' => $newShares,
                'active_shares' => $byShare->count(),
                'share_rate' => $approvedIds->isNotEmpty() ? round(($sharedApproved / $approvedIds->count()) * 100, 1) : null,
                'views' => $views,
                'cta_clicks' => $clicks,
                'ctr' => $viewers->isNotEmpty() ? round(($clickedViewers / $viewers->count()) * 100, 1) : null,
                'identified_viewers' => $viewers->count(),
                'clicked_viewers' => $clickedViewers,
                'eligible_click_events' => $eligibleClicks,
                'anonymous_click_events' => $anonymousClicks,
                'unmatched_click_events' => $unmatchedClicks,
                'repeated_eligible_click_events' => $eligibleClicks - $clickedViewers,
                'identity_starts' => $starts,
                'identity_completions' => $completed,
                'orders' => $orders,
                'revenue' => round($financial->sum('total_cents') / 100, 2),
                'paid_balance' => round($financial->sum('paid_amount_cents') / 100, 2),
                'viral_conversion_rate' => $views > 0 ? round(($orders / $views) * 100, 2) : null,
                'average_referred_identities' => $byShare->count() > 0 ? round($starts / $byShare->count(), 2) : 0,
                'best_channel' => data_get($channels->first(), 'channel', '—'),
            ],
            'channels' => $channels,
            'top_shares' => $topShares,
        ];
    }
}
