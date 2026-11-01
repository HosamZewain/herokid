<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderAutomation\Process\ProcessPaymentReview;
use Illuminate\Http\Request;

class OrderPaymentReviewController extends Controller
{
    public function __invoke(Request $request, Order $order, ProcessPaymentReview $reviews)
    {
        $data = $request->validate(['review_id' => 'required|integer|min:1', 'seen_signal_id' => 'required|integer|min:1',
            'note' => 'required|string|min:3|max:1000', 'confirm' => 'accepted']);
        $reviews->close($order, $data['review_id'], $request->user(), $data['note'], $data['seen_signal_id']);

        return back()->with('success', 'تم إنهاء مراجعة المرفقات. لم يُسجّل أي مبلغ تلقائيًا.');
    }
}
