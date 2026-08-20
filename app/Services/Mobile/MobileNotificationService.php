<?php

namespace App\Services\Mobile;

use App\Jobs\SendMobilePushNotification;
use App\Models\MobileNotification;
use App\Models\Order;
use App\Models\User;

class MobileNotificationService
{
    public function notifyUser(User $user, string $eventKey, string $title, string $body, array $data = [], string $category = 'operational'): MobileNotification
    {
        $notification = $user->mobileNotifications()->create([
            'event_key' => $eventKey,
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'data' => $this->safeData($data),
        ]);

        if (! app()->runningUnitTests()) {
            SendMobilePushNotification::dispatch($notification->id)->afterCommit();
        }

        return $notification;
    }

    public function notifyOrder(Order $order, string $eventKey, string $title, string $body): ?MobileNotification
    {
        $user = $order->user;
        if (! $user) {
            return null;
        }

        return $this->notifyUser($user, $eventKey, $title, $body, [
            'screen' => 'order',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
        ]);
    }

    private function safeData(array $data): array
    {
        return collect($data)->except(['child_name', 'image', 'image_url', 'photo', 'photo_url', 'storage_path', 'token'])->all();
    }
}
