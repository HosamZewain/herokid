<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\Mobile\MobileNotificationService;
use App\Support\AdminActivityLogger;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderStatusService
{
    public function __construct(private readonly MobileNotificationService $mobileNotifications) {}

    public const STATUSES = [
        'new',
        'under_review',
        'generating',
        'preview_uploaded',
        'revision_requested',
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

            if ($locked->story_id && in_array($status, ['approved_for_print', 'printing'], true)) {
                $currentVersionId = $locked->bookletPreview()->value('current_version_id');
                if (! $currentVersionId || (int) $locked->approved_booklet_preview_version_id !== (int) $currentVersionId) {
                    throw ValidationException::withMessages([
                        'status' => 'لا يمكن اعتماد الطباعة أو بدئها قبل موافقة العميل الصريحة على النسخة الحالية من المعاينة.',
                    ]);
                }
            }

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

            if ($statusChanged) {
                $this->notifyCustomer($locked, $status);
            }

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

    private function notifyCustomer(Order $order, string $status): void
    {
        $message = match ($status) {
            'generating' => ['production.started', 'بدأ إعداد طلبك', 'بدأ فريق HeroKid إعداد محتوى طلبك '.$order->order_number.'.'],
            'preview_uploaded' => ['preview.ready', 'تصميمك جاهز للمراجعة', 'راجع النسخة الحالية من طلبك '.$order->order_number.' واعتمدها أو اطلب تعديلاً.'],
            'approved_for_print' => ['preview.approved', 'تم تسجيل موافقتك', 'تم اعتماد التصميم الحالي للطلب '.$order->order_number.' للطباعة.'],
            'printing' => ['printing.started', 'بدأت الطباعة', 'دخل طلبك '.$order->order_number.' مرحلة الطباعة.'],
            'shipped' => ['order.shipped', 'تم شحن طلبك', 'طلب HeroKid '.$order->order_number.' في طريقه إليك.'],
            'delivered' => ['order.delivered', 'تم توصيل طلبك', 'نتمنى أن تستمتعوا بطلب HeroKid '.$order->order_number.'.'],
            default => null,
        };
        if ($message) {
            $this->mobileNotifications->notifyOrder($order, $message[0], $message[1], $message[2]);
        }
    }
}
