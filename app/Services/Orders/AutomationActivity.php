<?php

namespace App\Services\Orders;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Support\AdminActivityLogger;

class AutomationActivity
{
    public static function record(Order|string $subject, string $action, string $description, array $properties = []): void
    {
        $order = $subject instanceof Order ? $subject : Order::withTrashed()->where('checkout_group_key', $subject)->orderBy('id')->first();
        if (! $order) {
            return;
        }
        AdminActivityLog::create([
            'user_id' => null, 'action' => 'automation.'.$action, 'description' => $description,
            'subject_type' => Order::class, 'subject_id' => $order->id,
            'properties' => AdminActivityLogger::sanitize($properties + ['actor_name' => 'Automation']),
        ]);
    }
}
