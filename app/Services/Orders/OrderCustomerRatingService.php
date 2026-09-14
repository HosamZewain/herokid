<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderCustomerReview;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class OrderCustomerRatingService
{
    public function ratingForOrder(Order $order): ?OrderCustomerReview
    {
        $orderIds = Order::withTrashed()
            ->where('checkout_group_key', $order->checkoutGroupKey())
            ->pluck('id');

        return OrderCustomerReview::query()
            ->whereIn('order_id', $orderIds)
            ->where('review_type', OrderCustomerReview::TYPE_SERVICE_RATING)
            ->where('version_reference', OrderCustomerReview::VERSION_CHECKOUT)
            ->where('decision', OrderCustomerReview::DECISION_SUBMITTED)
            ->latest('decided_at')
            ->latest('id')
            ->first();
    }

    public function ratingForGroup(array $group): ?OrderCustomerReview
    {
        $orderIds = collect($group['orders'] ?? [])->pluck('id');

        return OrderCustomerReview::query()
            ->whereIn('order_id', $orderIds)
            ->where('review_type', OrderCustomerReview::TYPE_SERVICE_RATING)
            ->where('version_reference', OrderCustomerReview::VERSION_CHECKOUT)
            ->where('decision', OrderCustomerReview::DECISION_SUBMITTED)
            ->latest('decided_at')
            ->latest('id')
            ->first();
    }

    /** @return array{review: OrderCustomerReview, created: bool} */
    public function submit(Order $order, int $qualityRating, ?string $comment): array
    {
        return DB::transaction(function () use ($order, $qualityRating, $comment): array {
            $canonicalOrder = Order::query()
                ->where('checkout_group_key', $order->checkoutGroupKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->ratingForOrder($canonicalOrder);

            if ($existing) {
                return ['review' => $existing, 'created' => false];
            }

            $review = OrderCustomerReview::query()->create([
                'order_id' => $canonicalOrder->id,
                'review_type' => OrderCustomerReview::TYPE_SERVICE_RATING,
                'version_reference' => OrderCustomerReview::VERSION_CHECKOUT,
                'decision' => OrderCustomerReview::DECISION_SUBMITTED,
                'customer_comment' => filled($comment) ? trim((string) $comment) : null,
                'source' => OrderCustomerReview::SOURCE_PUBLIC_LINK,
                'decided_at' => now(),
                'metadata' => [
                    'quality_rating' => $qualityRating,
                    'checkout_group_key' => $canonicalOrder->checkoutGroupKey(),
                ],
            ]);

            return ['review' => $review, 'created' => true];
        });
    }

    public function publicUrl(Order $order): string
    {
        return URL::signedRoute('order-ratings.show', ['order' => $order->id]);
    }

    /** @return array{title: string, body: string, url: string}|null */
    public function whatsappActionForGroup(array $group): ?array
    {
        if ($group['trashed'] ?? false) {
            return null;
        }

        $phone = Phone::forWhatsApp($group['phone'] ?? null);
        $order = collect($group['active_orders'] ?? [])->sortBy('id')->first();

        if (! $phone || ! $order instanceof Order) {
            return null;
        }

        $reference = (string) ($group['short_reference'] ?: $group['key']);
        $customerName = trim((string) ($group['customer_name'] ?? ''));
        $greeting = $customerName !== '' ? 'مرحباً '.$customerName.'،' : 'مرحباً،';
        $body = $greeting."\nيسعدنا معرفة رأيك في طلب HeroKid رقم ".$reference.'.'."\nقيّم تجربتك من خلال الرابط التالي:\n".$this->publicUrl($order);

        return [
            'title' => 'إرسال رابط التقييم',
            'body' => $body,
            'url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($body),
        ];
    }
}
