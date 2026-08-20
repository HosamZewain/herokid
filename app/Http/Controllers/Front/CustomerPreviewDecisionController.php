<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\BookletPreviews\CustomerPreviewDecisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerPreviewDecisionController extends Controller
{
    public function approve(Request $request, Order $order, CustomerPreviewDecisionService $decisions): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);
        $decisions->approve($order, $request->user(), $request);

        return back()->with('success', 'تمت الموافقة على النسخة الحالية من التصميم! سنبدأ الطباعة قريباً.');
    }
}
