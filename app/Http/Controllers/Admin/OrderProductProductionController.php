<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\AdminActivityLogger;
use App\Support\ProductProductionPrompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderProductProductionController extends Controller
{
    public function __invoke(Order $order, OrderItem $item): View
    {
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
        $productPrompt = [
            'item' => $item,
            'prompt' => ProductProductionPrompt::renderForItem($item),
            'uses_snapshot' => filled(data_get($item->item_snapshot, 'production_prompt_template')),
        ];
        $promptTemplate = ProductProductionPrompt::templateForItem($item) ?? '';

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

        return view('admin.orders.product-production', compact(
            'order',
            'item',
            'photos',
            'productPrompt',
            'promptTemplate',
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

        $snapshot = $item->item_snapshot ?? [];
        $snapshot['production_prompt_template'] = $template;
        $item->update(['item_snapshot' => $snapshot]);

        AdminActivityLogger::log(
            action: 'order.product_production_prompt.updated',
            description: 'تحديث برومبت إنتاج المنتج داخل الطلب: '.$order->order_number,
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
            ],
            request: $request,
        );

        return back()->with('success', 'تم حفظ برومبت هذا الطلب وتحديث النسخة الجاهزة للنسخ.');
    }

    public function useCurrentPrompt(Request $request, Order $order, OrderItem $item): RedirectResponse
    {
        $this->assertItemBelongsToOrder($order, $item);
        $item->loadMissing('product');
        $template = trim((string) $item->product?->production_prompt_template);

        if ($template === '') {
            return back()->withErrors([
                'production_prompt_template' => 'لا يوجد قالب برومبت حالي محفوظ على المنتج.',
            ]);
        }

        $snapshot = $item->item_snapshot ?? [];
        $snapshot['production_prompt_template'] = $template;
        $item->update(['item_snapshot' => $snapshot]);

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

        return back()->with('success', 'تم تطبيق أحدث قالب للمنتج على هذا الطلب.');
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
