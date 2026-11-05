@php
    $workflowStatusLabels = \App\Services\Orders\OrderStatusService::labels();
    $workflowPaymentLabels = \App\Support\OrderPaymentStatus::labels();
    $workflowPaymentMethods = \App\Support\OrderPaymentStatus::paymentMethods();
    $workflowPrintingLabels = \App\Support\OrderWorkflowStatus::printingLabels();
    $workflowShippingLabels = \App\Support\OrderWorkflowStatus::shippingLabels();
    $workflowPaymentBehavior = \App\Support\OrderPaymentStatus::behavior($group['payment_status']);
    if ($group['status'] !== 'mixed' && !array_key_exists($group['status'], $workflowStatusLabels)) $workflowStatusLabels[$group['status']] = \App\Support\OrderStatusRegistry::label(\App\Support\OrderStatusRegistry::TYPE_ORDER, $group['status']);
    if (!array_key_exists($group['payment_status'], $workflowPaymentLabels)) $workflowPaymentLabels[$group['payment_status']] = \App\Support\OrderStatusRegistry::label(\App\Support\OrderStatusRegistry::TYPE_PAYMENT, $group['payment_status']);
    if ($group['printing_status'] !== 'mixed' && !array_key_exists($group['printing_status'], $workflowPrintingLabels)) $workflowPrintingLabels[$group['printing_status']] = \App\Support\OrderStatusRegistry::label(\App\Support\OrderStatusRegistry::TYPE_PRINTING, $group['printing_status']);
    if ($group['shipping_status'] !== 'mixed' && !array_key_exists($group['shipping_status'], $workflowShippingLabels)) $workflowShippingLabels[$group['shipping_status']] = \App\Support\OrderStatusRegistry::label(\App\Support\OrderStatusRegistry::TYPE_SHIPPING, $group['shipping_status']);
@endphp

<div class="rounded-2xl border border-indigo-100 bg-indigo-50/50 p-4 sm:p-5" data-workflow-panel="{{ $group['representative_id'] }}">
    <div class="mb-4 flex flex-col gap-1 text-right sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="font-black text-gray-950">حالات عملية الشراء</h3>
            <p class="mt-1 text-xs font-bold text-gray-500">عدّل الطلب والدفع والطباعة والشحن من مكان واحد.</p>
        </div>
        <p class="text-[11px] font-bold text-indigo-600" aria-live="polite" data-workflow-message></p>
    </div>

    <form
        method="POST"
        action="{{ route('admin.orders.groups.workflow-statuses', $group['representative_id']) }}"
        class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        data-workflow-status-form
        data-total-cents="{{ $group['total_cents'] }}"
        data-delivery-cents="{{ $group['delivery_cents'] }}"
    >
        @csrf
        @method('PATCH')
        <div>
            <label class="mb-1 block text-xs font-black text-gray-600">حالة الطلب</label>
            <select name="status" class="w-full rounded-xl border-gray-200 text-right text-sm">
                @if($group['status'] === 'mixed')<option value="" selected>حالات متعددة — بدون تغيير</option>@endif
                @foreach($workflowStatusLabels as $value => $label)
                    <option value="{{ $value }}" @selected($group['status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-black text-gray-600">حالة الدفع</label>
            <select name="payment_status" required class="w-full rounded-xl border-gray-200 text-right text-sm" data-workflow-payment-status>
                @foreach($workflowPaymentLabels as $value => $label)
                    <option value="{{ $value }}" data-behavior="{{ \App\Support\OrderPaymentStatus::behavior($value) }}" @selected($group['payment_status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-black text-gray-600">حالة الطباعة</label>
            <select name="printing_status" required class="w-full rounded-xl border-gray-200 text-right text-sm">
                @foreach($workflowPrintingLabels as $value => $label)
                    <option value="{{ $value }}" @selected($group['printing_status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-black text-gray-600">حالة الشحن</label>
            <select name="shipping_status" required class="w-full rounded-xl border-gray-200 text-right text-sm">
                @foreach($workflowShippingLabels as $value => $label)
                    <option value="{{ $value }}" @selected($group['shipping_status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div data-workflow-partial-field @if($workflowPaymentBehavior !== 'partially_paid') hidden @endif>
            <label class="mb-1 block text-xs font-black text-gray-600">المبلغ المدفوع جزئيًا</label>
            <input name="paid_amount" type="number" min="0.01" step="0.01" value="{{ $group['paid_amount_cents'] / 100 }}" class="w-full rounded-xl border-gray-200 text-left text-sm" dir="ltr" data-workflow-paid-amount @required($workflowPaymentBehavior === 'partially_paid') @disabled($workflowPaymentBehavior !== 'partially_paid')>
        </div>
        <div data-workflow-method-field @if($workflowPaymentBehavior === 'unpaid') hidden @endif>
            <label class="mb-1 block text-xs font-black text-gray-600">طريقة الدفع</label>
            <select name="payment_method" class="w-full rounded-xl border-gray-200 text-right text-sm" data-workflow-payment-method @required($workflowPaymentBehavior !== 'unpaid') @disabled($workflowPaymentBehavior === 'unpaid')>
                <option value="">اختر الطريقة</option>
                @foreach($workflowPaymentMethods as $method)<option value="{{ $method }}" @selected($group['payment_method'] === $method)>{{ $method }}</option>@endforeach
            </select>
        </div>
        <div class="sm:col-span-2 xl:col-span-2">
            <label class="mb-1 block text-xs font-black text-gray-600">ملاحظة داخلية (اختياري)</label>
            <input name="admin_notes" class="w-full rounded-xl border-gray-200 text-right text-sm" placeholder="تُضاف إلى سجل الحالات">
        </div>
        <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700 sm:col-span-2 xl:col-span-4" data-workflow-submit>حفظ كل الحالات</button>
        <div class="hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-bold text-red-700 sm:col-span-2 xl:col-span-4" role="alert" data-workflow-errors></div>
    </form>
</div>
