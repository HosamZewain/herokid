<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderAdminNoteService;
use Illuminate\Http\Request;

class OrderAdminNoteController extends Controller
{
    public function store(Request $request, int $order, OrderAdminNoteService $notes)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ], [
            'body.required' => 'اكتب الملاحظة قبل الحفظ.',
            'body.max' => 'الملاحظة يجب ألا تزيد عن 5000 حرف.',
        ]);

        $target = Order::query()->withTrashed()->findOrFail($order);
        $notes->add($target, $request->user(), $validated['body'], $request);

        return back()->with('success', 'تمت إضافة الملاحظة إلى السجل الدائم للطلب.');
    }
}
