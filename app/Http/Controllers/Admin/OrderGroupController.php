<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderDeletionService;
use App\Services\Orders\OrderPaymentService;
use App\Services\Orders\OrderStatusService;
use App\Services\Orders\OrderWhatsAppMessageService;
use App\Services\Orders\OrderWorkflowStatusService;
use App\Support\OrderPaymentStatus;
use App\Support\OrderStatusRegistry;
use App\Support\OrderWorkflowStatus;
use App\Support\ProductProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderGroupController extends Controller
{
    public function show(int $representative, AdminOrderGroupService $groups, OrderWhatsAppMessageService $whatsapp)
    {
        $group = $groups->findByRepresentative($representative);

        if ($group['direct_order_id']) {
            return redirect()->route('admin.orders.show', $group['direct_order_id']);
        }

        $statuses = OrderStatusService::statuses();
        foreach ($group['statuses'] as $currentStatus) {
            if (! in_array($currentStatus, $statuses, true)) {
                $statuses[] = $currentStatus;
            }
        }
        $paymentStatuses = OrderPaymentStatus::labels();
        if (! array_key_exists($group['payment_status'], $paymentStatuses)) {
            $paymentStatuses[$group['payment_status']] = OrderStatusRegistry::label(OrderStatusRegistry::TYPE_PAYMENT, $group['payment_status']);
        }
        $printingStatuses = OrderWorkflowStatus::printingLabels();
        if ($group['printing_status'] !== 'mixed' && ! array_key_exists($group['printing_status'], $printingStatuses)) {
            $printingStatuses[$group['printing_status']] = OrderStatusRegistry::label(OrderStatusRegistry::TYPE_PRINTING, $group['printing_status']);
        }
        $shippingStatuses = OrderWorkflowStatus::shippingLabels();
        if ($group['shipping_status'] !== 'mixed' && ! array_key_exists($group['shipping_status'], $shippingStatuses)) {
            $shippingStatuses[$group['shipping_status']] = OrderStatusRegistry::label(OrderStatusRegistry::TYPE_SHIPPING, $group['shipping_status']);
        }

        $productProductionPrompts = collect();
        if (auth()->user()->hasPermission('orders.production_prompt.manage')) {
            $productProductionPrompts = $group['active_orders']
                ->flatMap(fn (Order $order) => ProductProductionPrompt::forOrder($order))
                ->values();
        }

        $attachmentTarget = $group['active_orders']->first() ?: $group['orders']->first();
        $attachmentOrders = $group['active_orders']->isNotEmpty()
            ? $group['active_orders']
            : $group['orders'];

        return view('admin.orders.group-show', [
            'group' => $group,
            'statuses' => $statuses,
            'paymentStatuses' => $paymentStatuses,
            'paymentMethods' => OrderPaymentStatus::paymentMethods(),
            'printingStatuses' => $printingStatuses,
            'shippingStatuses' => $shippingStatuses,
            'productProductionPrompts' => $productProductionPrompts,
            'attachmentTarget' => $attachmentTarget,
            'attachmentOrders' => $attachmentOrders,
            'whatsappMessages' => $whatsapp->messagesForGroup($group),
        ]);
    }

    public function updateWorkflowStatuses(
        Request $request,
        int $representative,
        OrderWorkflowStatusService $workflow,
    ) {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(OrderStatusService::statuses(false))],
            'payment_status' => ['required', Rule::in(OrderPaymentStatus::statuses(false))],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'payment_method' => ['nullable', Rule::in(OrderPaymentStatus::paymentMethods())],
            'printing_status' => ['required', Rule::in(OrderWorkflowStatus::printingStatuses(false))],
            'shipping_status' => ['required', Rule::in(OrderWorkflowStatus::shippingStatuses(false))],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $order = Order::query()->findOrFail($representative);
        $group = $workflow->updateGroup($order, $validated, $request->user(), $request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'تم تحديث حالات الطلب والدفع والطباعة والشحن.',
                'group' => [
                    'representative_id' => $group['representative_id'],
                    'status' => $group['status'],
                    'status_label' => $group['status_label'],
                    'status_color' => OrderStatusRegistry::color(OrderStatusRegistry::TYPE_ORDER, $group['status']),
                    'payment_status' => $group['payment_status'],
                    'payment_status_label' => $group['payment_status_label'],
                    'payment_status_color' => OrderStatusRegistry::color(OrderStatusRegistry::TYPE_PAYMENT, $group['payment_status']),
                    'paid_amount' => format_money($group['paid_amount_cents'] / 100),
                    'remaining_amount' => format_money($group['remaining_amount_cents'] / 100),
                    'printing_status' => $group['printing_status'],
                    'printing_status_label' => $group['printing_status_label'],
                    'printing_status_color' => OrderStatusRegistry::color(OrderStatusRegistry::TYPE_PRINTING, $group['printing_status']),
                    'shipping_status' => $group['shipping_status'],
                    'shipping_status_label' => $group['shipping_status_label'],
                    'shipping_status_color' => OrderStatusRegistry::color(OrderStatusRegistry::TYPE_SHIPPING, $group['shipping_status']),
                ],
            ]);
        }

        return back()->with('success', 'تم تحديث حالات الطلب والدفع والطباعة والشحن.');
    }

    public function updateStatus(Request $request, int $representative, AdminOrderGroupService $groups, OrderStatusService $statuses)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(OrderStatusService::statuses(false))],
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
            'payment_status' => ['required', Rule::in(OrderPaymentStatus::statuses(false))],
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

        $confirmation = trim($validated['confirmation']);
        $acceptedReferences = array_filter([
            $order->checkoutGroupKey(),
            $order->checkoutReference?->short_reference,
        ]);

        if (! collect($acceptedReferences)->contains(
            fn (string $reference): bool => hash_equals($reference, $confirmation)
        )) {
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
