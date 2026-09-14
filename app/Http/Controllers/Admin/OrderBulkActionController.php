<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Orders\OrderBulkActionService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderBulkActionController extends Controller
{
    public function __invoke(Request $request, OrderBulkActionService $actions): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in([
                OrderBulkActionService::ACTION_UPDATE_STATUS,
                OrderBulkActionService::ACTION_RELEASE_ASSIGNMENTS,
                OrderBulkActionService::ACTION_UPDATE_STATUS_AND_RELEASE,
            ])],
            'representative_ids' => ['required', 'array', 'min:1', 'max:100'],
            'representative_ids.*' => ['required', 'integer', 'distinct', 'exists:orders,id'],
            'status' => ['nullable', Rule::in(OrderStatusService::statuses())],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'representative_ids.required' => 'حدد طلبًا واحدًا على الأقل.',
            'representative_ids.min' => 'حدد طلبًا واحدًا على الأقل.',
            'representative_ids.max' => 'يمكن تنفيذ الإجراء على 100 عملية شراء كحد أقصى في المرة الواحدة.',
        ]);

        $changesStatus = in_array($validated['action'], [
            OrderBulkActionService::ACTION_UPDATE_STATUS,
            OrderBulkActionService::ACTION_UPDATE_STATUS_AND_RELEASE,
        ], true);
        $releasesAssignments = in_array($validated['action'], [
            OrderBulkActionService::ACTION_RELEASE_ASSIGNMENTS,
            OrderBulkActionService::ACTION_UPDATE_STATUS_AND_RELEASE,
        ], true);

        abort_if($changesStatus && ! $request->user()->hasPermission('orders.update'), 403);
        abort_if($releasesAssignments && ! $request->user()->hasPermission('orders.assignment.manage'), 403);

        if ($changesStatus && blank($validated['status'] ?? null)) {
            return back()->withErrors(['status' => 'اختر الحالة الجديدة للطلبات المحددة.'])->withInput();
        }

        $result = $actions->execute(
            representativeIds: $validated['representative_ids'],
            action: $validated['action'],
            status: $validated['status'] ?? null,
            notes: $validated['admin_notes'] ?? null,
            admin: $request->user(),
            request: $request,
        );

        $parts = [];
        if ($result['status_updated_count'] > 0) {
            $parts[] = 'تم تغيير حالة '.$result['status_updated_count'].' عملية شراء';
        }
        if ($releasesAssignments) {
            $parts[] = 'تم إلغاء الاستحواذ عن '.$result['released_assignment_count'].' عملية شراء مستلمة';
        }

        return back()->with('success', implode('، ', $parts).'.');
    }
}
