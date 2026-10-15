<?php

namespace App\Jobs;

use App\Models\MobilePushCampaign;
use App\Services\Mobile\MobilePushAudience;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class DispatchMobilePushCampaign implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $campaignId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('mobile-campaign-'.$this->campaignId))->releaseAfter(30)->expireAfter(250)];
    }

    public function handle(MobilePushAudience $audience): void
    {
        $campaign = MobilePushCampaign::find($this->campaignId);
        if (! $campaign || $campaign->status === 'enqueued') {
            return;
        }
        // Never turn a deleted single-customer target into a broadcast.
        if ($campaign->audience === 'customer' && ! $campaign->target_user_id) {
            $campaign->update(['status' => 'failed']);

            return;
        }
        $campaign->update(['status' => 'dispatching']);
        $audience->query($campaign->category, $campaign->audience === 'customer' ? $campaign->target_user_id : null)
            ->chunkById(100, function ($users) use ($campaign): void {
                foreach ($users as $user) {
                    $notification = $campaign->notifications()->firstOrCreate(['user_id' => $user->id], [
                        'event_key' => 'admin_campaign', 'category' => $campaign->category,
                        'title' => $campaign->title, 'body' => $campaign->body, 'data' => $campaign->data,
                    ]);
                    SendMobilePushNotification::dispatch($notification->id);
                }
            });
        $campaign->update(['status' => 'enqueued']);
    }

    public function failed(?\Throwable $exception): void
    {
        MobilePushCampaign::whereKey($this->campaignId)->update(['status' => 'failed']);
    }
}
