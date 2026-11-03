<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderAdminNote;
use App\Models\OrderShippingMergeRequest;
use App\Support\AdminActivityLogger;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShippingMergeReview
{
    public const MESSAGE = 'تم قبول الطلب الجديد وتسجيل طلب ضم الشحن للفريق. الطلبان منفصلان لحين المراجعة؛ لم تُخصم مصاريف الشحن بعد، وسيؤكد الفريق إمكانية الضم والمبلغ النهائي قبل الشحن.';

    public function request(Order $previous, Order $new, string $phone, Request $request): void
    {
        DB::transaction(function () use ($previous, $new, $phone, $request) {
            $rows = Order::whereIn('checkout_group_key', [$previous->checkoutGroupKey(), $new->checkoutGroupKey()])->orderBy('id')->lockForUpdate()->get();
            $old = $rows->firstWhere('id', $previous->id);
            $target = $rows->firstWhere('id', $new->id);
            $normalized = Phone::forWhatsApp($phone);
            if (! $old || ! $target || ! $normalized || $old->checkoutGroupKey() === $target->checkoutGroupKey()
                || $rows->contains(fn ($row) => Phone::forWhatsApp((string) data_get($row->delivery_details, 'phone')) !== $normalized)) {
                throw ValidationException::withMessages(['previous_order_action' => 'تعذر التحقق من الطلب السابق. تابع بالطلب الجديد منفصلًا أو راجع رقم الطلب والهاتف.']);
            }
            $review = OrderShippingMergeRequest::firstOrCreate(['target_checkout_key' => $target->checkoutGroupKey()], [
                'source_checkout_key' => $old->checkoutGroupKey(), 'source_order_id' => $old->id, 'target_order_id' => $target->id,
            ]);
            if (! $review->wasRecentlyCreated) {
                return;
            }
            foreach ([$old, $target] as $order) {
                OrderAdminNote::create(['checkout_group_key' => $order->checkoutGroupKey(), 'representative_order_id' => $order->id,
                    'author_name' => 'العميل — الموقع', 'body' => "طلب ضم الشحن للمراجعة #{$review->id} مع طلب آخر للعميل. تفاصيل الطلبين متاحة للمدير في تنبيه ضم الشحن. لم يتم دمج الطلبات أو خصم رسوم الشحن. راجع إمكانية الشحن معًا والمبلغ النهائي قبل إنشاء البوليصة."]);
                AdminActivityLogger::log('order.shipping_merge_requested', 'طلب العميل ضم الشحن؛ بانتظار مراجعة الفريق دون تعديل الإنتاج أو المدفوعات.', $order,
                    ['shipping_merge_request_id' => $review->id], null, $request);
            }
        }, 3);
    }

    public function forKey(string $key)
    {
        return OrderShippingMergeRequest::where('status', 'pending')->where(fn ($q) => $q->whereHas('sourceOrder', fn ($q) => $q->where('checkout_group_key', $key))->orWhereHas('targetOrder', fn ($q) => $q->where('checkout_group_key', $key)))->get();
    }

    public function resolve(Order $order, int $id, string $resolution, Request $request): void
    {
        $actor = $request->user();
        abort_unless($actor?->is_active && ! $actor->agent_api_enabled && $actor->hasPermission('orders.update') && $actor->hasPermission('orders.view_all'), 403);
        app(OrderAccess::class)->assert($order);
        DB::transaction(function () use ($order, $id, $resolution, $request, $actor) {
            $review = OrderShippingMergeRequest::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($order->checkoutGroupKey(), [$review->sourceOrder?->checkoutGroupKey(), $review->targetOrder?->checkoutGroupKey()], true), 404);
            if ($review->status !== 'pending') {
                return;
            }
            $review->update(['status' => 'resolved', 'resolution' => $resolution, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
            foreach ([$review->sourceOrder, $review->targetOrder] as $related) {
                OrderAdminNote::create(['checkout_group_key' => $related->checkoutGroupKey(), 'representative_order_id' => $related->id,
                    'author_user_id' => $actor->id, 'author_name' => $actor->name,
                    'body' => 'نتيجة مراجعة ضم الشحن #'.$review->id.': '.$resolution]);
            }
            AdminActivityLogger::log('order.shipping_merge_reviewed', 'إنهاء مراجعة طلب ضم الشحن بواسطة الفريق؛ لا يغيّر هذا الإجراء الشحن أو المبالغ.', $order,
                ['shipping_merge_request_id' => $review->id, 'resolution' => $resolution], $actor, $request);
        });
    }
}
