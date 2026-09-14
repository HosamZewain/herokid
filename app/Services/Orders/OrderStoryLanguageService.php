<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderStoryLanguageService
{
    public function change(Order $order, string $language, User $actor, string $reason): array
    {
        abort_unless($actor->isAdmin() && $actor->hasPermission('orders.update'), 403);
        if (! in_array($language, ['ar', 'en'], true)) {
            throw ValidationException::withMessages(['language' => 'اختر العربية أو الإنجليزية.']);
        }

        return DB::transaction(function () use ($order, $language, $actor, $reason) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($locked->story_id, 404);
            $previous = $locked->language ?? 'ar';
            $locked->forceFill(['language' => $language])->save();
            // Explicit language change authorizes a reprint, never a status or asset change.
            $result = app(ProductionSceneSnapshotRefreshService::class)->refresh($locked->id, $locked->story_id, $actor, $reason, true, true);
            AdminActivityLogger::log('order.story_language_changed', 'Story production language changed', $locked,
                ['previous_language' => $previous, 'language' => $language, 'reason' => $reason], $actor);

            return $result;
        });
    }
}
