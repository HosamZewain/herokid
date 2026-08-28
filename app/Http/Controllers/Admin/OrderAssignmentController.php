<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderAssignmentService;
use Illuminate\Http\Request;

class OrderAssignmentController extends Controller
{
    public function acquire(Request $request, int $representative, OrderAssignmentService $assignments)
    {
        $order = Order::query()->findOrFail($representative);
        $assignments->acquire($order, $request->user(), $request);

        return back()->with('success', 'تم استلام الطلب وإسناده إليك.');
    }

    public function takeover(Request $request, int $representative, OrderAssignmentService $assignments)
    {
        $order = Order::query()->findOrFail($representative);
        $assignments->acquire($order, $request->user(), $request, true);

        return back()->with('success', 'تم نقل مسؤولية الطلب إليك.');
    }

    public function release(Request $request, int $representative, OrderAssignmentService $assignments)
    {
        $order = Order::query()->findOrFail($representative);
        $force = $request->user()->hasPermission('orders.assignment.manage');
        $assignments->release($order, $request->user(), $request, $force);

        return back()->with('success', 'تم ترك الطلب وأصبح متاحًا لباقي الفريق.');
    }
}
