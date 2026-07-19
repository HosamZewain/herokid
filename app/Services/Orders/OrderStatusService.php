<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Support\AdminActivityLogger;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderStatusService
{
    public const STATUSES = [
        'new',
        'under_review',
        'generating',
        'preview_uploaded',
        'approved_for_print',
        'printing',
        'shipped',
        'delivered',
        'cancelled',
    ];

    public function update(Order $order, string $status, ?string $notes, Request $request): Order
    {
        return DB::transaction(function () use ($order, $status, $notes, $request): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $oldStatus = $locked->status;
            $oldNotes = $locked->notes;
            $statusChanged = $oldStatus !== $status;

            $locked->update([
                'status' => $status,
                'notes' => $notes ?? $locked->notes,
            ]);

            if ($statusChanged) {
                $locked->statusLogs()->create([
                    'status' => $status,
                    'notes' => $notes ?: 'تم تحديث الحالة من لوحة الإدارة.',
                ]);

                if (in_array($status, ['generating', 'approved_for_print', 'printing'], true)
                    && ! $locked->productionPromptSnapshots()->exists()) {
                    $locked->productionPromptSnapshots()->create([
                        'prompt_text' => StoryProductionPrompt::forOrder($locked->fresh(['story', 'productionPromptOverride'])),
                        'template_updated_at' => StoryProductionPrompt::templateUpdatedAt(),
                        'snapshot_reason' => 'status:'.$status,
                        'created_by' => $request->user()?->id,
                    ]);
                }
            }

            AdminActivityLogger::log(
                action: $statusChanged ? 'order.status_updated' : 'order.updated',
                description: 'تحديث الطلب: '.$locked->order_number,
                subject: $locked,
                properties: [
                    'order_number' => $locked->order_number,
                    'status' => ['old' => $oldStatus, 'new' => $status, 'changed' => $statusChanged],
                    'notes_changed' => $oldNotes !== $locked->notes,
                    'admin_notes' => $notes,
                ],
                request: $request,
            );

            return $locked->fresh();
        });
    }

    public function updateGroup(Collection $orders, string $status, ?string $notes, Request $request): Collection
    {
        return DB::transaction(function () use ($orders, $status, $notes, $request): Collection {
            return $orders
                ->reject->trashed()
                ->map(fn (Order $order): Order => $this->update($order, $status, $notes, $request))
                ->values();
        });
    }
}
