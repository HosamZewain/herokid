<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\OrderReviewManifest;
use App\Services\OrderAutomation\Process\ProcessProductReview;
use App\Services\Orders\CustomerPreviewPage;
use App\Services\Orders\OrderProductPreviewImageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class OrderReviewManifestController extends Controller
{
    private function check(OrderReviewManifest $manifest): void
    {
        abort_unless(app(CustomerPreviewPage::class)->available($manifest), 404);
    }

    public function legacy(Request $request, OrderReviewManifest $manifest)
    {
        $this->check($manifest);
        // Keep the old signed link valid, without extending its lifetime or changing its snapshot.
        $expires = $request->query('expires');
        $url = $expires
            ? URL::temporarySignedRoute('order-review.show', CarbonImmutable::createFromTimestamp((int) $expires), ['manifest' => $manifest->id])
            : URL::signedRoute('order-review.show', ['manifest' => $manifest->id]);

        return redirect($url)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function show(OrderReviewManifest $manifest)
    {
        $this->check($manifest);
        $images = collect($manifest->images)->map(fn ($image, $index) => ['label' => $image['label'], 'url' => URL::temporarySignedRoute('order-review.image', now()->addMinutes(15), ['manifest' => $manifest->id, 'index' => $index])]);

        return response()->view('front.order-product-previews.show', ['images' => $images, 'orderReference' => app(CustomerPreviewPage::class)->reference($manifest->checkout_key)])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function image(Request $request, OrderReviewManifest $manifest, int $index, ProcessProductReview $service, OrderProductPreviewImageService $protection)
    {
        $this->check($manifest);
        abort_if($request->header('Sec-Fetch-Dest') === 'document', 404);
        $image = $manifest->images[$index] ?? null;
        abort_unless($image, 404);
        try {
            $bytes = $service->bytes($image);
        } catch (\Throwable) {
            abort(404);
        }
        $size = @getimagesizefromstring($bytes);
        abort_unless($size && in_array($size['mime'], ['image/png', 'image/jpeg', 'image/webp']), 404);

        return response($protection->protectedBytes($bytes))->header('Cross-Origin-Resource-Policy', 'same-origin')->header('Content-Type', 'image/jpeg')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
