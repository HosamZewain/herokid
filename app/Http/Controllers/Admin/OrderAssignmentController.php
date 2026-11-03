<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\AutomationControl;
use App\Services\Orders\AutomationHandoff;
use Illuminate\Http\Request;

class OrderAssignmentController extends Controller
{
    public function acquire(Request $request, int $representative)
    {
        abort(409, 'استخدم اختيار الموظف المسؤول مع تحديث الصفحة أولًا.');
    }

    public function takeover(Request $request, int $representative)
    {
        abort(409, 'استخدم اختيار الموظف المسؤول مع تحديث الصفحة أولًا.');
    }

    public function automation(Request $request, int $representative, AutomationHandoff $handoff)
    {
        $data = $request->validate(['action' => 'required|in:take,resume']);
        $order = Order::query()->findOrFail($representative);
        if ($data['action'] === 'take') {
            abort_if(AutomationControl::blocked($order->checkoutGroupKey()), 409, 'طلب الإيقاف مسجل بالفعل؛ تابع حالته في لوحة التحكم.');
            $handoff->take($order, $request->user(), $request);
        } else {
            abort_if(AutomationControl::blocked($order->checkoutGroupKey()), 409, 'استخدم استئناف الأوتوميشن من لوحة التحكم الجديدة.');
            $handoff->resume($order, $request->user(), $request);
        }

        return back()->with('success', $data['action'] === 'take' ? 'تم استلام الطلب وإيقاف متابعته الآلية؛ يمكنك تعديله أو إلغاء الطلب. أي نتيجة توليد غير مؤكدة تظل محفوظة للمراجعة ولا يُعاد تنفيذها.' : 'تمت إعادة متابعة الطلب إلى الأوتوميشن.');
    }

    public function release(Request $request, int $representative)
    {
        abort(409, 'استخدم إزالة الإسناد من اختيار الموظف المسؤول.');
    }
}
