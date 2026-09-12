<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderCustomerRatingService;
use Illuminate\Http\Request;

class OrderCustomerRatingController extends Controller
{
    public function show(
        Order $order,
        AdminOrderGroupService $groups,
        OrderCustomerRatingService $ratings,
    ) {
        $group = $groups->findByRepresentative($order->id);

        return response()
            ->view('front.order-ratings.show', [
                'group' => $group,
                'rating' => $ratings->ratingForOrder($order),
                'submitUrl' => $ratings->publicUrl($order),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function store(
        Request $request,
        Order $order,
        OrderCustomerRatingService $ratings,
    ) {
        $validated = $request->validate([
            'quality_rating' => ['required', 'integer', 'between:1,5'],
            'customer_comment' => ['nullable', 'string', 'max:2000'],
        ], [
            'quality_rating.required' => 'اختر تقييم جودة المنتج من نجمة إلى خمس نجوم.',
            'quality_rating.between' => 'تقييم جودة المنتج يجب أن يكون من نجمة إلى خمس نجوم.',
            'customer_comment.max' => 'التعليق أو الاقتراح يجب ألا يتجاوز 2000 حرف.',
        ]);

        $result = $ratings->submit(
            $order,
            (int) $validated['quality_rating'],
            $validated['customer_comment'] ?? null,
        );

        return redirect($ratings->publicUrl($order))->with(
            'success',
            $result['created'] ? 'شكراً لك، تم إرسال تقييمك بنجاح.' : 'تم إرسال تقييم هذا الطلب مسبقاً.',
        );
    }
}
