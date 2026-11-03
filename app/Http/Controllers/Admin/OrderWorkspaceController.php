<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\OrderItem;
use App\Models\OrderReviewManifest;
use App\Models\Setting;
use App\Models\User;
use App\Services\CodexScenes\BookPageService;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\AutomationControl;
use App\Services\Orders\OrderAccess;
use App\Services\Orders\OrderAssignmentService;
use App\Services\Orders\OrderAutomationAdmission;
use App\Services\Orders\OrderAutomationSummary;
use App\Services\Orders\OrderStatusService;
use App\Services\Orders\StaffPreviewApproval;
use App\Support\AdminActivityLogger;
use App\Support\OrderStatusRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OrderWorkspaceController extends Controller
{
    public function assign(Request $request, OrderAssignmentService $assignments)
    {
        $data = $request->validate(['target_id' => 'nullable|integer|exists:users,id', 'reason' => 'required|string|max:1000',
            'orders' => 'required|array|min:1|max:100', 'orders.*.id' => 'required|integer|distinct', 'orders.*.assignee' => 'nullable|integer', 'orders.*.version' => 'required|integer|min:0']);
        $target = isset($data['target_id']) ? User::findOrFail($data['target_id']) : null;
        DB::transaction(function () use ($data, $target, $request, $assignments) {
            // Stable lock ordering; a conflict rolls back the WHOLE selected group.
            foreach (collect($data['orders'])->sortBy('id') as $row) {
                $order = Order::findOrFail($row['id']);
                $assignments->assign($order, $target, $request->user(), $request, isset($row['assignee']) ? (int) $row['assignee'] : null, (int) $row['version'], $data['reason']);
            }
        }, 3);

        return back()->with('success', 'تم تحديث الموظف المسؤول؛ وضع الأوتوميشن لم يتغير.');
    }

    public function control(Request $request, Order $order, AutomationControl $control)
    {
        $data = $request->validate(['action' => 'required|in:pause,resume', 'version' => 'required|integer|min:1', 'reason' => 'required_if:action,pause|nullable|string|max:1000']);
        if ($data['action'] === 'pause') {
            $control->pause($order, $request->user(), $request, (int) $data['version'], $data['reason']);
        } else {
            $control->resume($order, $request->user(), $request, (int) $data['version']);
        }

        return back()->with('success', $data['action'] === 'pause' ? 'تم تسجيل طلب الإيقاف؛ انتظر تأكيد حفظ الخطوة الجارية قبل التعديل.' : 'تم استئناف المتبقي المؤهل دون تغيير الموظف المسؤول.');
    }

    public function admit(Request $request, Order $order, OrderAutomationAdmission $admission)
    {
        $data = $request->validate(['confirm' => 'accepted', 'version' => 'required|integer|min:1']);
        $admission->start($order, $request->user(), $request, (int) $data['version']);

        return back()->with('success', 'تم إدخال الطلب للأوتوميشن؛ تابع مراحل التجهيز أعلى الطلب.');
    }

    public function approvePreview(Request $request, Order $order, StaffPreviewApproval $approvals)
    {
        $data = $request->validate(['confirm' => 'accepted', 'manifest_id' => 'required|integer', 'fingerprint' => 'required|string|size:64',
            'inputs_fingerprint' => 'required|string|size:64', 'run_version' => 'required|integer|min:1', 'control_version' => 'required|integer|min:1',
            'channel' => 'required|in:phone,whatsapp,in_person,other', 'note' => 'required|string|min:3|max:1000']);
        try {
            $approvals->approve($order, $request->user(), $request, $data);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['preview_approval' => 'تعذر اعتماد النسخة الحالية. راجع الصور وبياناتها؛ لم يُسجل اعتماد جزئي أو يبدأ توليد.']);
        }

        return back()->with('success', 'تم تسجيل موافقة العميل بواسطة الفريق واستئناف المتبقي المؤهل. يبدأ التوليد بعد وجود دفعة فعلية؛ تابع حالته أعلى الطلب.');
    }

    public function startBook(Request $request, Order $order, OrderItem $item, BookPageService $books)
    {
        abort_unless($item->order->checkoutGroupKey() === $order->checkoutGroupKey(), 404);
        abort_unless(app(OrderAccess::class)->controls($order, $request->user()), 403);
        $data = $request->validate(['confirm' => 'accepted', 'expected_updated_at' => 'required|string']);
        abort_unless($item->updated_at->toISOString() === $data['expected_updated_at'], 409, 'تغير المنتج؛ حدّث الصفحة.');
        if ($reason = $books->admissionReason($item)) {
            throw ValidationException::withMessages(['automation' => $reason]);
        }
        $manifest = OrderReviewManifest::where('checkout_key', $order->checkoutGroupKey())->latest('id')->firstOrFail();
        try {
            $run = $books->start($item, $manifest, true);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['automation' => 'تعذر بدء الكتاب؛ ربما تغيرت البيانات أو يوجد تنفيذ جارٍ. حدّث الصفحة وراجع السبب.']);
        }
        AdminActivityLogger::log('order.book_pages_start_requested', 'طلب إدخال كتاب التلوين لطابور Codex.', $order, ['item_id' => $item->id, 'run_id' => $run->id], $request->user(), $request);

        return back()->with('success', 'تم إدخال صفحات الكتاب إلى طابور Codex؛ سيظهر التقدم هنا تلقائيًا.');
    }

    public function visibility(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:all,assigned', 'expected' => 'required|in:all,assigned']);
        // The operator must retain an all-order view so unassigned work remains distributable.
        abort_unless($request->user()->hasPermission('orders.view_all') && $request->user()->hasPermission('orders.assignment.manage'), 403);
        DB::transaction(function () use ($data, $request) {
            DB::table('settings')->insertOrIgnore(['key' => 'orders_visibility', 'value' => 'all', 'created_at' => now(), 'updated_at' => now()]);
            $setting = Setting::where('key', 'orders_visibility')->lockForUpdate()->firstOrFail();
            abort_unless($setting->value === $data['expected'], 409, 'تغير وضع الرؤية؛ حدّث الصفحة.');
            $setting->update(['value' => $data['mode']]);
            AdminActivityLogger::log('orders.visibility_changed', 'تغيير نطاق رؤية طلبات الموظفين.', null, ['from' => $data['expected'], 'to' => $data['mode']], $request->user(), $request);
        }, 3);

        return back()->with('success', 'تم تحديث وضع الرؤية؛ لم يتم توزيع أو تشغيل أي طلبات.');
    }

    public function progress(Request $request, AdminOrderGroupService $groups, OrderAutomationSummary $summaries)
    {
        $data = $request->validate(['visible' => 'sometimes|array|max:100', 'visible.*' => 'integer|distinct']);
        $result = $groups->paginate($request, false);
        $ids = array_map('intval', $data['visible'] ?? []);
        $visible = Order::withTrashed()->whereIn('id', $ids)->get(['id', 'checkout_group_key', 'status']);
        $states = $summaries->forKeys($visible->pluck('checkout_group_key'));
        $assignments = OrderGroupAssignment::with('assignee:id,name')->whereIn('checkout_group_key', $visible->pluck('checkout_group_key'))->get()->keyBy('checkout_group_key');
        $facts = Order::withTrashed()->whereIn('checkout_group_key', $visible->pluck('checkout_group_key'))->get(['checkout_group_key', 'status', 'payment_status', 'printing_status', 'shipping_status'])->groupBy('checkout_group_key');
        $badges = [];
        foreach ($facts as $key => $rows) {
            foreach (['status' => 'order', 'payment_status' => 'payment', 'printing_status' => 'printing', 'shipping_status' => 'shipping'] as $field => $type) {
                $values = $rows->pluck($field)->filter()->unique();
                $value = $values->count() === 1 ? $values->first() : 'mixed';
                $badges[$key][$field] = ['label' => $value === 'mixed' ? 'حالات متعددة' : OrderStatusRegistry::label($type, $value),
                    'color' => OrderStatusRegistry::colors($type)[$value] ?? 'bg-gray-100 text-gray-700'];
            }
        }

        return response()->json(['ids' => $result['groups']->getCollection()->pluck('representative_id')->values(),
            'total' => $result['groups']->total(), 'counts' => $result['workspaceCounts'],
            'revoked' => array_values(array_diff($ids, $visible->pluck('id')->all())),
            'rows' => $visible->map(fn ($o) => ['id' => $o->id, 'summary' => $states[$o->checkout_group_key],
                'assignee' => $assignments->get($o->checkout_group_key)?->assignee?->name ?? 'غير مسند',
                'badges' => $badges[$o->checkout_group_key] ?? [], 'status' => OrderStatusService::labels(false)[$o->status] ?? $o->status])->values()])
            ->header('Cache-Control', 'private, no-store');
    }
}
