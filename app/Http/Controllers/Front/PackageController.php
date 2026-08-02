<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\PricingPackage;
use App\Models\Story;
use App\Services\Pricing\StoryPricingService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PackageController extends Controller
{
    public function show(Request $request, PricingPackage $pricingPackage, StoryPricingService $storyPricing, TemporaryPhotoUploadService $uploads)
    {
        abort_unless($pricingPackage->active && $pricingPackage->show_in_store, 404);
        $pricingPackage->load(['items.product.category', 'items.variant']);
        abort_unless($pricingPackage->availableForPurchase(), 404);

        $stories = Story::query()
            ->where('active', true)
            ->with('categories')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        $referenceStory = $stories->first();
        abort_if($pricingPackage->story_count > 0 && ! $referenceStory, 404);
        $regularTotal = ($pricingPackage->story_count * ($referenceStory ? (int) round($storyPricing->effectivePrice($referenceStory) * 100) : 0))
            + $pricingPackage->items->sum(fn ($item): int => $item->product
                ? $item->product->effectivePriceCents($item->variant) * $item->quantity
                : 0);

        $uploadSession = $uploads->ensureSession($request);
        $photoUploadConfig = [
            'sessionToken' => $uploadSession['token'],
            'uploadUrl' => route('photo-uploads.store'),
            'deleteUrlTemplate' => route('photo-uploads.destroy', ['publicId' => '__ID__']),
            'batchTokens' => collect(range(1, max(1, $pricingPackage->story_count)))->map(fn () => Str::random(48))->all(),
            'maxSizeMb' => (int) config('photo_uploads.max_size_mb', 15),
            'maxLongEdge' => (int) config('photo_uploads.max_long_edge', 2560),
            'jpegQuality' => (int) config('photo_uploads.jpeg_quality', 90),
        ];

        return view('front.packages.show', compact('pricingPackage', 'stories', 'regularTotal', 'photoUploadConfig'));
    }
}
