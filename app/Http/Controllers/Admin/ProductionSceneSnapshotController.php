<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\ProductionSceneSnapshotRefreshService;
use Illuminate\Http\Request;

class ProductionSceneSnapshotController extends Controller
{
    public function __invoke(Request $request, Order $order, ProductionSceneSnapshotRefreshService $service)
    {
        $data = $request->validate(['story_id' => 'required|integer', 'reason' => 'required|string|max:500',
            'confirm_refresh' => 'accepted', 'allow_completed' => 'sometimes|boolean']);
        $service->refresh($order->id, (int) $data['story_id'], $request->user(), $data['reason'], true, $request->boolean('allow_completed'));

        return redirect()->route('admin.orders.show', $order)->with('success', 'تم تحديث نصوص مشاهد هذه القصة فقط من القالب الحالي، مع الحفاظ على معرفات المشاهد والمرفقات.');
    }
}
