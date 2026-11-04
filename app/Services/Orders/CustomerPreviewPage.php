<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderCheckoutReference;
use App\Models\OrderPreview;
use App\Models\OrderProductPreviewGallery;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/** Public presentation only: never include notes, prompts, contact details or private paths. */
class CustomerPreviewPage
{
    public function reference(string $checkoutKey): ?string
    {
        return OrderCheckoutReference::where('checkout_group_key', $checkoutKey)->value('short_reference');
    }

    public function available(OrderReviewManifest $manifest): bool
    {
        $run = $manifest->run;
        if (! $run || in_array($run->status, ['cancelled', 'blocked', 'uncertain'], true) || ! $run->order || $run->order->trashed()) {
            return false;
        }
        $images = collect($manifest->images);
        $orders = Order::whereIn('id', $images->pluck('order_id'))->where('checkout_group_key', $manifest->checkout_key)->pluck('id');
        $previews = OrderPreview::with('productGallery')->whereIn('id', $images->pluck('preview_id')->filter())->get()->keyBy('id');
        foreach ($images as $image) {
            if (! $orders->contains($image['order_id'])) {
                return false;
            }
            if (isset($image['preview_id'])) {
                $preview = $previews->get($image['preview_id']);
                if (! $preview || (int) $preview->order_id !== (int) $image['order_id']
                    || $preview->checksum !== $image['sha256'] || $preview->productGallery?->status !== 'active'
                    || $preview->productGallery->checkout_group_key !== $manifest->checkout_key) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Only unify a gallery with an immutable snapshot when the exact product image set matches. */
    public function matchingReviewUrl(OrderProductPreviewGallery $gallery): ?string
    {
        if ($gallery->status !== 'active') {
            return null;
        }
        $manifest = OrderReviewManifest::where('checkout_key', $gallery->checkout_group_key)
            ->whereHas('run', fn ($query) => $query->whereNotIn('status', ['cancelled', 'blocked', 'uncertain'])->whereHas('order'))
            ->latest('id')->first();
        if (! $manifest || ! $this->available($manifest)) {
            return null;
        }
        $snapshot = collect($manifest->images)->filter(fn ($image) => isset($image['preview_id']))
            ->mapWithKeys(fn ($image) => [$image['preview_id'] => $image['sha256']])->sortKeys()->all();
        $actual = $gallery->previews->pluck('checksum', 'id')->sortKeys()->all();
        if (! $actual || $snapshot !== $actual) {
            return null;
        }

        return URL::temporarySignedRoute('order-review.show', now()->addDays(35), ['manifest' => $manifest->id]);
    }

    public function galleryImages(OrderProductPreviewGallery $gallery, string $token): Collection
    {
        $gallery->loadMissing('previews.order.items');
        $generations = PuzzleGeneration::whereIn('preview_id', $gallery->previews->modelKeys())
            ->orderBy('id')->get()->keyBy('preview_id');

        return $gallery->previews->map(function ($preview) use ($generations, $token) {
            $context = $generations->get($preview->id)?->context;
            // Use the generation's saved names, not a different child's later edits.
            $title = $context['item_identity']['title'] ?? null;
            $child = $context['child_identity']['child_name'] ?? $preview->order?->child_name;
            if (! $title) {
                $items = $preview->order?->items ?? collect();
                if (preg_match('/^product:(\d+)(?::component:.+)?$/', $preview->production_unit_key ?? '', $match)) {
                    $title = $items->firstWhere('id', (int) $match[1])?->title;
                } elseif ($items->count() === 1) {
                    $title = $items->first()->title;
                }
            }

            return [
                'label' => implode(' — ', array_filter([$title ?: 'معاينة منتجات الطلب', $child])),
                'url' => route('order-product-previews.image', ['token' => $token, 'preview' => $preview]),
            ];
        });
    }
}
