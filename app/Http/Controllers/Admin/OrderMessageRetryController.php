<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderAutomation\Process\ProcessMessageRetry;
use App\Services\RoboDesk\OrderMessageRetry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderMessageRetryController extends Controller
{
    public function __invoke(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['source' => 'required|in:confirmation,process', 'source_id' => 'required|integer|min:1',
            'version' => 'required|integer|min:0', 'reason' => 'required|string|min:3|max:300', 'confirm' => 'accepted']);
        if ($data['source'] === 'confirmation') {
            app(OrderMessageRetry::class)->retry($order, $data['source_id'], $request->user(), $data['reason']);
        } else {
            app(ProcessMessageRetry::class)->retry($order, $data['source_id'], $request->user(), $data['version'], $data['reason']);
        }

        return back()->with('success', 'تم تسجيل إعادة المحاولة. تابع حالة الإرسال هنا؛ قبول RoboDesk لا يعني وصول الرسالة.');
    }
}
