<?php

namespace App\Jobs;

use App\Models\WhatsAppConversation;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\RoboDesk\Conversations\ConversationHistoryProvider;
use App\Services\RoboDesk\Conversations\ConversationPhone;
use App\Services\RoboDesk\Conversations\OrderConversationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class RefreshActiveOrderConversation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public int $timeout = 25;

    public int $tries = 1;

    public function __construct(public int $orderId, public string $account, public string $phoneHash)
    {
        $this->onQueue('robodesk-conversations');
    }

    public function uniqueId(): string
    {
        return hash('sha256', $this->account.':'.$this->phoneHash);
    }

    public function handle(AdminOrderGroupService $orders, ConversationHistoryProvider $provider, OrderConversationService $history): void
    {
        if ($this->account !== config('robodesk.conversations.account_key', 'primary') || ! $provider->configured()) {
            return;
        }
        $namespace = hash('sha256', $this->account);
        if (Cache::has('robodesk-background-paused:'.$namespace)
            || Cache::has('robodesk-background-backoff:'.$this->uniqueId())) {
            return;
        }
        $order = $orders->activeOrdersQuery()->find($this->orderId);
        if (! $order) {
            return;
        }
        $phone = ConversationPhone::canonical(data_get($order->delivery_details, 'phone'));
        if (! $phone || hash('sha256', $phone) !== $this->phoneHash) {
            return;
        }
        // Serialize background requests across workers without occupying web workers.
        $lock = Cache::lock('robodesk-background-worker:'.$namespace, 30);
        if (! $lock->get()) {
            return;
        }
        try {
            $rateKey = 'robodesk-background-rate:'.$namespace;
            $maxRequests = max(1, min(20, (int) config('robodesk.conversations.background_requests_per_minute', 20)));
            if (RateLimiter::tooManyAttempts($rateKey, $maxRequests)) {
                return;
            }
            $conversation = WhatsAppConversation::where('account_key', $this->account)->where('phone_hash', $this->phoneHash)->first();
            $interval = max(600, (int) config('robodesk.conversations.background_interval_seconds', 600));
            if ($conversation?->last_synced_at?->gt(now()->subSeconds($interval))) {
                return;
            }
            RateLimiter::hit($rateKey, 60);
            $history->sync($order); // Reuses validation, encrypted persistence, delta cursor and contact lock.
        } catch (\Throwable $exception) {
            $reason = in_array($exception->getMessage(), ['setup_required', 'provider_authentication', 'invalid_provider_response'], true)
                ? $exception->getMessage() : 'provider_unavailable';
            Cache::put('robodesk-background-backoff:'.$this->uniqueId(), true, now()->addMinutes(10));
            if ($reason === 'provider_authentication') {
                Cache::put('robodesk-background-paused:'.$namespace, true, now()->addMinutes(10));
            }
            Log::warning('RoboDesk background conversation refresh failed.', ['reason' => $reason, 'order_id' => $this->orderId]);
        } finally {
            $lock->release();
        }
    }
}
