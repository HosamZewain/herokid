<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Uploads\OrderPhotoUploadService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderPhotoController extends Controller
{
    public function destroy(
        Request $request,
        Order $order,
        int $index,
        OrderPhotoUploadService $photos,
    ): JsonResponse {
        $result = $photos->removeAt($order, $index);

        AdminActivityLogger::log(
            action: 'order.child_photo_deleted',
            description: 'حذف صورة طفل من الطلب: '.$order->order_number,
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'photo_index' => $index,
                'remaining_count' => $result['total_count'],
            ],
            request: $request,
        );

        return response()->json([
            'success' => true,
            'deleted_index' => $index,
            'remaining_count' => $result['total_count'],
        ]);
    }
}
