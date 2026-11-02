<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPreview;
use App\Models\OrderReviewManifest;
use App\Services\OrderAutomation\Process\ProcessProductReview;
use Illuminate\Support\Facades\URL;

class OrderReviewManifestController extends Controller
{
    private function check(OrderReviewManifest $manifest): void
    {
        $run = $manifest->run;
        abort_unless($run && ! in_array($run->status, ['cancelled', 'blocked', 'uncertain'], true) && $run->order && ! $run->order->trashed(), 404);
        foreach ($manifest->images as $image) {
            abort_unless(Order::whereKey($image['order_id'])->where('checkout_group_key', $manifest->checkout_key)->exists(), 404);
            if (isset($image['preview_id'])) {
                $preview = OrderPreview::find($image['preview_id']);
                abort_unless($preview && $preview->checksum === $image['sha256'] && $preview->productGallery?->status === 'active', 404);
            }
        }
    }

    public function show(OrderReviewManifest $manifest)
    {
        $this->check($manifest);
        $images = collect($manifest->images)->map(fn ($image, $index) => ['label' => $image['label'], 'url' => URL::temporarySignedRoute('order-process.review-image', now()->addMinutes(15), ['manifest' => $manifest->id, 'index' => $index])]);

        return response()->view('front.order-review-manifest', compact('images'))->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function image(OrderReviewManifest $manifest, int $index, ProcessProductReview $service)
    {
        $this->check($manifest);
        $image = $manifest->images[$index] ?? null;
        abort_unless($image, 404);
        try {
            $bytes = $service->bytes($image);
        } catch (\Throwable) {
            abort(404);
        }
        $size = @getimagesizefromstring($bytes);
        abort_unless($size && in_array($size['mime'], ['image/png', 'image/jpeg', 'image/webp']), 404);

        return response($bytes)->header('Content-Type', $size['mime'])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
