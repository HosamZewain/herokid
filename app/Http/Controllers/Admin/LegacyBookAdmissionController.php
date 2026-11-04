<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\LegacyBookAdmission;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class LegacyBookAdmissionController extends Controller
{
    public function show(Request $request, Order $order, OrderItem $item, LegacyBookAdmission $admission)
    {
        abort_unless($item->order->checkoutGroupKey() === $order->checkoutGroupKey(), 404);
        $admission->authorize($item, $request->user());
        $error = null;
        try {
            $offers = $admission->offers($item);
        } catch (RuntimeException $e) {
            $offers = [];
            $error = LegacyBookAdmission::message($e);
        }

        return view('admin.orders.legacy-book-admission', compact('order', 'item', 'offers', 'error'));
    }

    public function store(Request $request, Order $order, OrderItem $item, LegacyBookAdmission $admission)
    {
        abort_unless($item->order->checkoutGroupKey() === $order->checkoutGroupKey(), 404);
        $admission->authorize($item, $request->user());
        $data = $request->validate(['confirm' => 'accepted', 'preview_token' => 'required|string|max:10000']);
        try {
            $admission->approve($item, $request->user(), $request, $data['preview_token']);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['legacy_book' => LegacyBookAdmission::message($e)]);
        }

        return redirect()->route('admin.orders.groups.show', $order)->with('success', 'تم تجهيز الكتاب واعتماد الكفر المحدد. استخدم «فحص وبدء صفحات التلوين» أعلى الطلب؛ ستظهر أي شروط ناقصة دون إعادة توليد الكفر.');
    }
}
