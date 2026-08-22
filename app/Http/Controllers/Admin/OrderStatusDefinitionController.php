<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusDefinition;
use App\Support\AdminActivityLogger;
use App\Support\OrderStatusRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderStatusDefinitionController extends Controller
{
    public function index()
    {
        return view('admin.settings.order-statuses', [
            'groups' => collect(OrderStatusRegistry::typeLabels())->map(fn (string $label, string $type): array => [
                'type' => $type,
                'label' => $label,
                'definitions' => OrderStatusRegistry::definitions($type, false),
                'behaviors' => OrderStatusRegistry::behaviorOptions($type),
            ])->values(),
            'colors' => OrderStatusRegistry::COLORS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateDefinition($request);

        $definition = OrderStatusDefinition::create([
            ...$validated,
            'key' => strtolower($validated['key']),
            'is_active' => $request->boolean('is_active'),
            'is_system' => false,
        ]);

        OrderStatusRegistry::clearCache();
        AdminActivityLogger::log(
            action: 'settings.order_status_created',
            description: 'إضافة حالة جديدة: '.$definition->label_ar,
            subject: $definition,
            properties: ['definition' => $definition->only(['type', 'key', 'label_ar', 'behavior', 'color', 'sort_order', 'is_active'])],
            request: $request,
        );

        return back()->with('success', 'تمت إضافة الحالة وربطها بقوائم الطلبات.');
    }

    public function update(Request $request, OrderStatusDefinition $orderStatusDefinition)
    {
        $validated = $this->validateDefinition($request, $orderStatusDefinition);
        $before = $orderStatusDefinition->only(['label_ar', 'description', 'behavior', 'color', 'sort_order', 'is_active']);
        $active = $request->boolean('is_active');

        if (! $active && $orderStatusDefinition->is_active && OrderStatusDefinition::query()
            ->where('type', $orderStatusDefinition->type)
            ->where('is_active', true)
            ->where('id', '!=', $orderStatusDefinition->id)
            ->doesntExist()) {
            throw ValidationException::withMessages(['is_active' => 'يجب أن تبقى حالة نشطة واحدة على الأقل في هذه المجموعة.']);
        }

        $orderStatusDefinition->update([
            'label_ar' => $validated['label_ar'],
            'description' => $validated['description'] ?? null,
            'behavior' => $validated['behavior'],
            'color' => $validated['color'],
            'sort_order' => $validated['sort_order'],
            'is_active' => $active,
        ]);

        OrderStatusRegistry::clearCache();
        AdminActivityLogger::log(
            action: 'settings.order_status_updated',
            description: 'تعديل حالة: '.$orderStatusDefinition->label_ar,
            subject: $orderStatusDefinition,
            properties: ['changes' => AdminActivityLogger::changedValues($before, $orderStatusDefinition->only(array_keys($before)))],
            request: $request,
        );

        return back()->with('success', 'تم حفظ الحالة وانعكس الاسم واللون والترتيب على الواجهات.');
    }

    public function destroy(Request $request, OrderStatusDefinition $orderStatusDefinition)
    {
        if ($orderStatusDefinition->is_system) {
            throw ValidationException::withMessages([
                'status' => 'هذه حالة نظام مرتبطة بالتشغيل والتقارير؛ يمكنك تعديل عرضها أو تعطيلها ولا يمكن حذف مفتاحها.',
            ]);
        }

        $usage = $this->usageCount($orderStatusDefinition);
        if ($usage > 0) {
            $orderStatusDefinition->update(['is_active' => false]);
            OrderStatusRegistry::clearCache();
            AdminActivityLogger::log(
                action: 'settings.order_status_deactivated',
                description: 'تعطيل حالة مستخدمة بدل حذفها: '.$orderStatusDefinition->label_ar,
                subject: $orderStatusDefinition,
                properties: ['usage_count' => $usage],
                request: $request,
            );

            return back()->with('warning', 'الحالة مستخدمة في '.$usage.' سجل، لذلك عُطلت للاختيارات الجديدة مع الاحتفاظ بتاريخ الطلبات.');
        }

        $snapshot = $orderStatusDefinition->only(['type', 'key', 'label_ar', 'behavior']);
        $orderStatusDefinition->delete();
        OrderStatusRegistry::clearCache();
        AdminActivityLogger::log(
            action: 'settings.order_status_deleted',
            description: 'حذف حالة غير مستخدمة: '.$snapshot['label_ar'],
            properties: ['definition' => $snapshot],
            request: $request,
        );

        return back()->with('success', 'تم حذف الحالة غير المستخدمة.');
    }

    private function validateDefinition(Request $request, ?OrderStatusDefinition $definition = null): array
    {
        $type = $definition?->type ?: (string) $request->input('type');
        $behaviors = array_keys(OrderStatusRegistry::behaviorOptions($type));

        return $request->validate([
            'type' => [$definition ? 'sometimes' : 'required', Rule::in(OrderStatusRegistry::TYPES)],
            'key' => [
                $definition ? 'sometimes' : 'required',
                'string',
                'max:32',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('order_status_definitions', 'key')->where('type', $type)->ignore($definition?->id),
            ],
            'label_ar' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'behavior' => ['required', Rule::in($behaviors)],
            'color' => ['required', Rule::in(array_keys(OrderStatusRegistry::COLORS))],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'key.regex' => 'المفتاح يبدأ بحرف إنجليزي ويحتوي حروفًا صغيرة وأرقامًا وشرطة سفلية فقط.',
            'key.unique' => 'هذا المفتاح مستخدم بالفعل داخل نفس المجموعة.',
            'behavior.required' => 'اختر المعنى التشغيلي للحالة.',
        ]);
    }

    private function usageCount(OrderStatusDefinition $definition): int
    {
        $column = match ($definition->type) {
            OrderStatusRegistry::TYPE_ORDER => 'status',
            OrderStatusRegistry::TYPE_PAYMENT => 'payment_status',
            OrderStatusRegistry::TYPE_PRINTING => 'printing_status',
            OrderStatusRegistry::TYPE_SHIPPING => 'shipping_status',
        };
        $orders = Order::withTrashed()->where($column, $definition->key)->count();

        if ($definition->type === OrderStatusRegistry::TYPE_PAYMENT) {
            return $orders;
        }

        $logType = $definition->type === OrderStatusRegistry::TYPE_ORDER ? 'order' : $definition->type;
        $logs = 0;
        if (Schema::hasTable('order_status_logs')) {
            $logsQuery = DB::table('order_status_logs')->where('status', $definition->key);
            if (Schema::hasColumn('order_status_logs', 'status_type')) {
                $logsQuery->where('status_type', $logType);
            }
            $logs = $logsQuery->count();
        }

        return $orders + $logs;
    }
}
