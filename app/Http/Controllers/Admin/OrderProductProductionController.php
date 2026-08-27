<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\AdminActivityLogger;
use App\Support\ProductProductionPrompt;
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
        ));
    }
}
