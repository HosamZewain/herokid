<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CodexScenes\BookPageService;
use App\Services\Orders\LegacyBookAdmission;
use App\Services\Orders\ManualBookCoverApproval;
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
        $replacement = $request->boolean('replacement');
        $error = null;
        try {
            $offers = $replacement ? app(ManualBookCoverApproval::class)->offers($item) : $admission->offers($item);
        } catch (RuntimeException $e) {
            $offers = [];
            $error = LegacyBookAdmission::message($e);
        }

        return view('admin.orders.legacy-book-admission', compact('order', 'item', 'offers', 'error', 'replacement'));
    }

    public function store(Request $request, Order $order, OrderItem $item, LegacyBookAdmission $admission)
    {
        abort_unless($item->order->checkoutGroupKey() === $order->checkoutGroupKey(), 404);
        $admission->authorize($item, $request->user());
        $data = $request->validate(['confirm' => 'required|accepted', 'preview_token' => 'required|string|max:10000', 'replacement' => 'sometimes|boolean']);
        try {
            if ($request->boolean('replacement')) {
                app(ManualBookCoverApproval::class)->approve($item, $request->user(), $request, $data['preview_token']);
            } else {
                $admission->approve($item, $request->user(), $request, $data['preview_token']);
            }
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['legacy_book' => LegacyBookAdmission::message($e)]);
        }

        if ($request->boolean('replacement')) {
            $books = app(BookPageService::class);
            $reason = $books->admissionReason($item->fresh());
            if (! $reason) {
                try {
                    $books->start($item->fresh(), null, true);

                    return redirect()->route('admin.orders.groups.show', $order)->with('success', 'تم اعتماد الكفر المحدد وإدخال صفحات الكتاب لطابور Codex، دون إعادة توليد الكفر أو إرسال معاينة أخرى.');
                } catch (RuntimeException) {
                    $reason = $books->admissionReason($item->fresh()) ?: 'تغيرت حالة التنفيذ؛ حدّث الصفحة وافحص بدء الصفحات.';
                }
            }

            return redirect()->route('admin.orders.groups.show', $order)->with('success', 'تم تسجيل موافقة العميل على الكفر المحدد. الصفحات لم تبدأ: '.$reason);
        }

        return redirect()->route('admin.orders.groups.show', $order)->with('success', 'تم تجهيز الكتاب واعتماد الكفر المحدد. استخدم «فحص وبدء صفحات التلوين» أعلى الطلب؛ ستظهر أي شروط ناقصة دون إعادة توليد الكفر.');
    }
}
