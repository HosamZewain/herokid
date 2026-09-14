<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderActivityTimelineService;
use App\Services\Orders\ProductProductionComponentSnapshotService;
use App\Support\AdminActivityLogger;
use App\Support\ProductProductionPrompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderProductProductionController extends Controller
{
    public function __invoke(
        Order $order,
        OrderItem $item,
        AdminOrderGroupService $groups,
        OrderActivityTimelineService $activityTimeline,
    ): View {
        abort_unless(
            (int) $item->order_id === (int) $order->id
                && $item->item_type === 'product',
            404,
        );

        $item->loadMissing(['product', 'order']);
        abort_unless(ProductProductionPrompt::templateForItem($item) !== null, 404);

        $order->loadMissing(['user', 'createdByAdmin']);
        $photos = array_values(array_filter(
            $order->uploaded_photos ?? [],
            fn (mixed $photo): bool => is_string($photo) && trim($photo) !== '',
        ));
        $productProductionPrompts = ProductProductionPrompt::forItem($item);
        $productPrompt = $productProductionPrompts->first();
        $promptTemplate = ProductProductionPrompt::templateForItem($item) ?? '';
        $hasConfiguredComponents = $item->productionComponents->isNotEmpty()
            || ($item->product?->productionComponents?->isNotEmpty() ?? false);

        AdminActivityLogger::log(
            action: 'order.product_production.viewed',
            description: 'عرض صفحة إنتاج المنتج للطلب: '.$order->order_number,
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_title' => $item->title,
            ],
            request: request(),
        );

        $checkoutGroup = $groups->findByRepresentative($order->id);
        $orderActivity = $activityTimeline->forGroup($checkoutGroup);

        return view('admin.orders.product-production', compact(
            'order',
            'item',
            'photos',
            'productPrompt',
            'productProductionPrompts',
            'promptTemplate',
            'hasConfiguredComponents',
            'checkoutGroup',
            'orderActivity',
        ));
    }

    public function updatePrompt(Request $request, Order $order, OrderItem $item): RedirectResponse
    {
        $this->assertItemBelongsToOrder($order, $item);

        $validated = $request->validate([
            'production_prompt_template' => 'required|string|max:'.ProductProductionPrompt::MAX_TEMPLATE_LENGTH,
        ], [
            'production_prompt_template.required' => 'برومبت إنتاج المنتج مطلوب.',
            'production_prompt_template.max' => 'برومبت إنتاج المنتج طويل جدًا.',
        ]);

        $template = trim($validated['production_prompt_template']);
        $unsupportedVariables = ProductProductionPrompt::unsupportedVariables($template);

        if ($unsupportedVariables !== []) {
            throw ValidationException::withMessages([
                'production_prompt_template' => 'متغيرات غير مدعومة في برومبت المنتج: '.implode('، ', $unsupportedVariables),
            ]);
        }

        $item->loadMissing('product');
        $product = $item->product;

        if (! $product) {
            return back()->withErrors([
                'production_prompt_template' => 'لا يمكن تحديث القالب العام لأن المنتج لم يعد موجودًا.',
            ]);
        }

        if ($product->productionComponents()->exists() || $item->productionComponents()->exists()) {
            return back()->withErrors([
                'production_prompt_template' => 'تُدار برومبتات هذا المنتج من قسم أجزاء الإنتاج داخل صفحة تعديل المنتج.',
            ]);
        }

        $oldTemplate = $product->production_prompt_template;
        $product->update(['production_prompt_template' => $template]);

        AdminActivityLogger::log(
            action: 'product.production_prompt_template.updated',
            description: 'تحديث قالب برومبت إنتاج المنتج من طلب: '.$order->order_number,
            subject: $product,
            properties: [
                'order_number' => $order->order_number,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'template_changed' => $oldTemplate !== $template,
                'applies_to_all_product_orders' => true,
            ],
            request: $request,
        );

        AdminActivityLogger::log(
            action: 'order.product_production_prompt.updated',
            description: 'تم تحديث قالب برومبت '.$item->title.' من داخل الطلب '.$order->order_number.'.',
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_title' => $item->title,
                'template_changed' => $oldTemplate !== $template,
                'prompt_content_logged' => false,
            ],
            request: $request,
            markRequestLogged: false,
        );

        return back()->with('success', 'تم حفظ قالب المنتج، وسيظهر فورًا في كل الطلبات الحالية والجديدة لهذا المنتج.');
    }

    public function useCurrentPrompt(
        Request $request,
        Order $order,
        OrderItem $item,
        ProductProductionComponentSnapshotService $snapshots,
    ): RedirectResponse {
        $this->assertItemBelongsToOrder($order, $item);
        $item->loadMissing('product');
        $hasComponents = $item->product?->productionComponents()->where('is_active', true)->exists() ?? false;
        $template = trim((string) $item->product?->production_prompt_template);

        if (! $hasComponents && $template === '') {
            return back()->withErrors([
                'production_prompt_template' => 'لا يوجد قالب برومبت حالي محفوظ على المنتج.',
            ]);
        }

        if ($hasComponents) {
            $snapshots->refreshForItem($item);
        } else {
            $snapshot = $item->item_snapshot ?? [];
            unset($snapshot['production_prompt_template']);
            $item->update(['item_snapshot' => $snapshot]);
        }

        AdminActivityLogger::log(
            action: 'order.product_production_prompt.synced',
            description: 'تحديث برومبت الطلب من قالب المنتج الحالي: '.$order->order_number,
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
            ],
            request: $request,
        );

        return back()->with('success', $hasComponents
            ? 'تم تحديث أجزاء إنتاج هذا الطلب من إعدادات المنتج الحالية.'
            : 'هذا الطلب يقرأ الآن قالب المنتج الحالي تلقائيًا.');
    }

    private function assertItemBelongsToOrder(Order $order, OrderItem $item): void
    {
        abort_unless(
            (int) $item->order_id === (int) $order->id
                && $item->item_type === 'product',
            404,
        );
    }
}
