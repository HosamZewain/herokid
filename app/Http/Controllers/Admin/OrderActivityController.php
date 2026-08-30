<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\AdminActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderActivityController extends Controller
{
    public function promptCopied(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'prompt_type' => ['required', Rule::in(['story_production', 'child_identity', 'product_production'])],
            'order_item_id' => ['nullable', 'integer'],
        ]);

        $item = null;
        if (! empty($validated['order_item_id'])) {
            $item = OrderItem::query()->findOrFail((int) $validated['order_item_id']);
            abort_unless(
                $item->item_type === 'product'
                    && $item->order?->checkoutGroupKey() === $order->checkoutGroupKey(),
                404,
            );
        }

        $labels = [
            'story_production' => 'برومبت إنتاج القصة',
            'child_identity' => 'برومبت هوية الطفل',
            'product_production' => 'برومبت إنتاج المنتج',
        ];
        $promptLabel = $labels[$validated['prompt_type']];

        AdminActivityLogger::log(
            action: 'order.prompt_copied',
            description: 'تم نسخ '.$promptLabel.' للطلب '.$order->order_number.'.',
            subject: $order,
            properties: [
                'checkout_group_key' => $order->checkoutGroupKey(),
                'order_number' => $order->order_number,
                'prompt_type' => $validated['prompt_type'],
                'prompt_type_label' => $promptLabel,
                'order_item_id' => $item?->id,
                'product_title' => $item?->title,
                'prompt_content_logged' => false,
            ],
            request: $request,
        );

        return response()->json(['recorded' => true]);
    }
}
