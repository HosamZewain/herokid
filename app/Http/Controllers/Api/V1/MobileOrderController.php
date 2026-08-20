<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Mobile\MobileOrderPresenter;
use App\Services\Mobile\MobileOrderReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileOrderController extends Controller
{
    public function index(Request $request, MobileOrderPresenter $presenter): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['items'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => collect($orders->items())->map(fn (Order $order): array => $presenter->summary($order)),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Order $order, MobileOrderPresenter $presenter): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $order->load(['items', 'statusLogs', 'productionProject']);

        return response()->json(['data' => $presenter->detail($order)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function reorder(Request $request, Order $order, MobileOrderReorderService $reorder): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $validated = $request->validate(['idempotency_key' => ['required', 'uuid']]);

        return response()->json(['data' => $reorder->reorder($request->user(), $order, $validated['idempotency_key'])], 201);
    }
}
