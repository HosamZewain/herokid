<?php

namespace App\Console\Commands;

use App\Models\MobilePushDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckMobilePushReceipts extends Command
{
    protected $signature = 'mobile-push:check-receipts';

    protected $description = 'Check provider receipts without resending notifications';

    public function handle(): int
    {
        MobilePushDelivery::where('status', 'sent')->where('receipt_status', 'pending')
            ->where('sent_at', '<', now()->subDay())->update(['receipt_status' => 'unknown', 'receipt_checked_at' => now()]);
        $deliveries = MobilePushDelivery::where('status', 'sent')->where('receipt_status', 'pending')
            ->whereNotNull('provider_ticket_id')->where('sent_at', '<=', now()->subMinutes(15))
            ->where('sent_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->whereNull('receipt_checked_at')->orWhere('receipt_checked_at', '<=', now()->subMinutes(15)))
            ->orderBy('id')->limit(100)->get();
        if ($deliveries->isEmpty()) {
            return self::SUCCESS;
        }
        $client = Http::asJson()->timeout(20);
        if (filled(config('services.expo_push.access_token'))) {
            $client->withToken(config('services.expo_push.access_token'));
        }
        $response = $client->post('https://exp.host/--/api/v2/push/getReceipts', ['ids' => $deliveries->pluck('provider_ticket_id')->all()]);
        if (! $response->successful()) {
            $this->error('Push receipt provider unavailable. No sends attempted.');

            return self::FAILURE;
        }
        foreach ($deliveries as $delivery) {
            $receipt = $response->json('data.'.$delivery->provider_ticket_id);
            $status = data_get($receipt, 'status');
            $error = data_get($receipt, 'details.error');
            $safeError = is_string($error) && preg_match('/^[a-zA-Z_]{1,60}$/', $error) ? $error : null;
            $delivery->update(['receipt_checked_at' => now(),
                'receipt_status' => $status === 'ok' ? 'provider_accepted' : ($status === 'error' ? 'failed' : 'pending'),
                'error_code' => $safeError ?: $delivery->error_code,
            ]);
            if ($error === 'DeviceNotRegistered' && $delivery->sent_token_hash) {
                $delivery->device()->where('push_token_hash', $delivery->sent_token_hash)
                    ->update(['revoked_at' => now(), 'push_token' => null, 'push_token_hash' => null]);
            }
        }
        $this->info('Push receipts checked. No notifications resent.');

        return self::SUCCESS;
    }
}
