<?php

namespace App\Services\RoboDesk\Conversations;

use App\Jobs\RefreshActiveOrderConversation;
use App\Models\WhatsAppConversation;
use App\Services\Orders\AdminOrderGroupService;
use Illuminate\Support\Facades\Cache;

class ActiveConversationRefreshService
{
    public function __construct(private ConversationHistoryProvider $provider, private AdminOrderGroupService $orders) {}

    public function dispatchDue(): int
    {
        if (! $this->provider->configured()
            || config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            return 0;
        }
        $account = (string) config('robodesk.conversations.account_key', 'primary');
        $namespace = hash('sha256', $account);
        if (Cache::has('robodesk-background-paused:'.$namespace)) {
            return 0;
        }
        $cursorKey = 'robodesk-background-scan:'.$namespace;
        $cursor = (int) Cache::get($cursorKey, 0);
        $scanSize = max(1, min(500, (int) config('robodesk.conversations.background_scan_size', 100)));
        $batchSize = max(1, min(50, (int) config('robodesk.conversations.background_batch_size', 20)));
        $orders = $this->orders->activeOrdersQuery()->where('id', '>', $cursor)->orderBy('id')
            ->limit($scanSize)->get(['id', 'delivery_details']);
        if ($orders->isEmpty() && $cursor > 0) {
            $orders = $this->orders->activeOrdersQuery()->orderBy('id')->limit($scanSize)->get(['id', 'delivery_details']);
        }
        if ($orders->isEmpty()) {
            Cache::forget($cursorKey);

            return 0;
        }
        $contacts = [];
        foreach ($orders as $order) {
            $phone = ConversationPhone::canonical(data_get($order->delivery_details, 'phone'));
            if ($phone) {
                $contacts[$order->id] = hash('sha256', $phone);
            }
        }
        $lastSync = WhatsAppConversation::where('account_key', $account)->whereIn('phone_hash', array_values($contacts))
            ->get(['phone_hash', 'last_synced_at'])->keyBy('phone_hash');
        $cutoff = now()->subSeconds(max(600, (int) config('robodesk.conversations.background_interval_seconds', 600)));
        $seen = [];
        $queued = 0;
        foreach ($orders as $order) {
            $cursor = $order->id;
            $hash = $contacts[$order->id] ?? null;
            if (! $hash || isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            if ($lastSync->get($hash)?->last_synced_at?->gt($cutoff)
                || Cache::has('robodesk-background-backoff:'.hash('sha256', $account.':'.$hash))) {
                continue;
            }
            RefreshActiveOrderConversation::dispatch($order->id, $account, $hash)->delay(now()->addSeconds($queued * 3));
            if (++$queued >= $batchSize) {
                break;
            }
        }
        Cache::put($cursorKey, $cursor, now()->addDay());

        return $queued;
    }
}
