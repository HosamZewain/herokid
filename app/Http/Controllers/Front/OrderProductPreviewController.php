<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\OrderPreview;
use App\Models\OrderProductPreviewGallery;
use App\Services\Orders\OrderProductPreviewImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrderProductPreviewController extends Controller
{
    public function __construct(private readonly OrderProductPreviewImageService $imageService) {}

    public function show(Request $request, string $token)
    {
        $gallery = $this->findAvailableGallery($token);

        OrderProductPreviewGallery::query()->whereKey($gallery->id)->increment('view_count', 1, [
            'last_viewed_at' => now(),
        ]);

        return response()
            ->view('front.order-product-previews.show', [
                'gallery' => $gallery,
                'token' => $token,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function image(Request $request, string $token, OrderPreview $preview)
    {
        $gallery = $this->findAvailableGallery($token);
        abort_unless((int) $preview->product_gallery_id === (int) $gallery->id, 404);
        abort_if($request->header('Sec-Fetch-Dest') === 'document', 404);

        $customerImage = $this->imageService->customerImage($preview);
        $disk = Storage::disk($customerImage['disk']);

        return response()->file($disk->path($customerImage['path']), [
            'Content-Type' => $customerImage['mime_type'],
            'Content-Disposition' => 'inline; filename="herokid-protected-preview-'.$preview->id.'.jpg"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    private function findAvailableGallery(string $token): OrderProductPreviewGallery
    {
        $gallery = OrderProductPreviewGallery::query()
            ->with('previews')
            ->where('public_token_hash', hash('sha256', $token))
            ->first();

        abort_unless($gallery && $gallery->isPubliclyAvailable(), 410, 'رابط المعاينة غير متاح.');

        return $gallery;
    }
}
