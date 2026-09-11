<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderGroupTagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderGroupTagController extends Controller
{
    public function __invoke(
        Request $request,
        int $representative,
        OrderGroupTagService $tags,
    ): RedirectResponse {
        $validated = $request->validate([
            'tags' => ['nullable', 'string', 'max:600'],
        ]);
        $order = Order::query()->findOrFail($representative);

        $tags->update(
            $order,
            $validated['tags'] ?? null,
            $request->user(),
            $request,
        );

        return back()->with('success', 'تم تحديث علامات عملية الشراء.');
    }
}
