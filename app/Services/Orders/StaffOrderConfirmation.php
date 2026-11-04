<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderProcessRun;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Staff attest to order confirmation, never preview consent or payment. */
class StaffOrderConfirmation
{
    public function confirm(Order $order, User $actor, Request $request, int $version): OrderProcessRun
    {
        abort_unless(app(OrderAccess::class)->controls($order, $actor) && $actor->hasPermission('orders.update')
            && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage'), 403);

        return DB::transaction(function () use ($order, $actor, $request, $version) {
            $key = $order->checkoutGroupKey();
            DB::table('order_automation_controls')->insertOrIgnore(['checkout_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
            $control = DB::table('order_automation_controls')->where('checkout_key', $key)->lockForUpdate()->first();
            abort_unless($control->version === $version && in_array($control->state, ['running', 'paused'], true), 409, 'تغير وضع التنفيذ أو لم يتأكد توقفه؛ حدّث الصفحة.');
            $events = RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->orderBy('id')->lockForUpdate()->get();
            $rows = Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            if ($rows->isEmpty() || $rows->contains(fn ($row) => ! in_array($row->status, ['new', 'new_confirmed'], true)
                || $row->printing_status !== 'not_started' || $row->shipping_status !== 'not_ready')
                || $events->contains(fn ($event) => in_array($event->status, ['processing', 'unknown'], true))
                || OrderProcessRun::where('checkout_key', $key)->exists()) {
                throw ValidationException::withMessages(['automation' => 'يوجد تنفيذ سابق أو إرسال غير محسوم أو بدأت الطباعة والشحن. راجع التنفيذ الحالي؛ لم يُسجل تأكيد جديد.']);
            }
            $hold = DB::table('order_automation_holds')->where('checkout_key', $key)->lockForUpdate()->first();
            if ($hold && ($control->state !== 'paused' || json_decode($hold->runs, true) !== [])) {
                throw ValidationException::withMessages(['automation' => 'الإيقاف يحتوي مسارًا سابقًا؛ استخدم فحص واستكمال الناقص بدل تأكيد الطلب من جديد.']);
            }
            $oldStatuses = $rows->mapWithKeys(fn ($row) => [$row->id => $row->status])->all();
            // Preserve all send receipts. This explicit confirmation retires only the old wait.
            foreach ($events->whereIn('status', ['pending', 'succeeded', 'held']) as $event) {
                $event->update(['status' => 'cancelled']);
            }
            foreach ($rows as $row) {
                if ($row->status !== 'new_confirmed') {
                    $row->update(['status' => 'new_confirmed']);
                    $row->statusLogs()->create(['status_type' => 'order', 'status' => 'new_confirmed', 'notes' => 'تأكيد الطلب خارج زر واتساب؛ مسجل بواسطة الفريق.']);
                }
            }
            DB::table('order_automation_holds')->where('checkout_key', $key)->delete();
            DB::table('order_automation_controls')->where('checkout_key', $key)->update(['state' => 'running', 'error_code' => null, 'updated_at' => now()]);
            $run = app(OrderAutomationAdmission::class)->start($order->fresh(), $actor, $request, $version);
            // All native provider/media/identity checks finish before the transaction commits.
            app(MixedPreviewRecovery::class)->plan($run);
            AdminActivityLogger::log('order.confirmed_by_staff', 'تسجيل تأكيد الطلب بواسطة الفريق من خارج زر واتساب واستكمال الأوتوميشن؛ لا اعتماد للمعاينة أو دفع.',
                $order, ['order_ids' => $rows->modelKeys(), 'old_statuses' => $oldStatuses, 'run_id' => $run->id,
                    'retired_confirmation_ids' => $events->whereIn('status', ['cancelled'])->modelKeys(), 'source' => 'staff'], $actor, $request);

            return $run;
        }, 3);
    }
}
