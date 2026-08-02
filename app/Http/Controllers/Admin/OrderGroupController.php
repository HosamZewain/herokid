<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderDeletionService;
use App\Services\Orders\OrderPaymentService;
use App\Services\Orders\OrderStatusService;
use App\Support\OrderPaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderGroupController extends Controller
{
    public function show(int $representative, AdminOrderGroupService $groups)
    {
        return view('admin.orders.group-show', [
            'group' => $groups->findByRepresentative($representative),
            'statuses' => OrderStatusService::STATUSES,
            'paymentStatuses' => OrderPaymentStatus::labels(),
            'paymentMethods' => OrderPaymentStatus::paymentMethods(),
        ]);
    }

    public function updateStatus(Request $request, int $representative, AdminOrderGroupService $groups, OrderStatusService $statuses)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(OrderStatusService::STATUSES)],
            'admin_notes' => 'nullable|string|max:2000',
        ]);
        $order = Order::query()->findOrFail($representative);
        $groupOrders = $groups->ordersForGroup($order);
        $storyOrders = $groupOrders->whereNotNull('story_id')->values();
        $statuses->updateGroup($storyOrders->isNotEmpty() ? $storyOrders : $groupOrders, $validated['status'], $validated['admin_notes'] ?? null, $request);

        return back()->with('success', 'تم تحديث حالة جميع قصص عملية الشراء بنجاح.');
    }

    public function updatePayment(Request $request, int $representative, OrderPaymentService $payments)
    {
        $validated = $request->validate([
            'payment_status' => ['required', Rule::in(OrderPaymentStatus::STATUSES)],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'payment_method' => ['nullable', Rule::in(OrderPaymentStatus::paymentMethods())],
        ]);
        $order = Order::query()->findOrFail($representative);
        $payments->updateGroup(
            $order,
            $validated['payment_status'],
            $validated['paid_amount'] ?? null,
            $validated['payment_method'] ?? null,
            $request->user(),
            $request,
        );

        return back()->with('success', 'تم تحديث حالة الدفع وحساب المبلغ المتبقي بنجاح.');
    }

    public function destroy(Request $request, int $representative, OrderDeletionService $deletions)
    {
        $order = Order::query()->findOrFail($representative);
        $validated = $request->validate([
            'deletion_reason' => 'required|string|min:5|max:1000',
            'confirmation' => 'required|string',
        ]);

        if (! hash_equals($order->checkoutGroupKey(), trim($validated['confirmation']))) {
            throw ValidationException::withMessages(['confirmation' => 'اكتب مرجع عملية الشراء كما هو لتأكيد الحذف.']);
        }

        $count = $deletions->deleteGroup($order, $validated['deletion_reason'], $request->user(), $request);

        return redirect()->route('admin.orders.index', ['view' => 'trash'])
            ->with('success', 'تم نقل عملية الشراء وعدد '.$count.' طلبات إلى سلة المحذوفات.');
    }

    public function restore(Request $request, int $representative, OrderDeletionService $deletions)
    {
        $order = Order::onlyTrashed()->findOrFail($representative);
        $count = $deletions->restoreGroup($order, $request->user(), $request);

        return redirect()->route('admin.orders.groups.show', $order->id)
            ->with('success', 'تمت استعادة عملية الشراء وعدد '.$count.' طلبات بنجاح.');
    }
}
