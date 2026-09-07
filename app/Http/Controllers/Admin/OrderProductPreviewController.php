<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPreview;
use App\Services\Orders\OrderProductPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderProductPreviewController extends Controller
{
    public function store(
        Request $request,
        Order $order,
        OrderProductPreviewService $previews,
    ) {
        $validated = $request->validate([
            'preview_images' => ['required', 'array', 'min:1', 'max:10'],
            'preview_images.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
            'preview_note' => ['nullable', 'string', 'max:1000'],
        ], [
            'preview_images.required' => 'اختر صورة معاينة واحدة على الأقل.',
            'preview_images.min' => 'اختر صورة معاينة واحدة على الأقل.',
            'preview_images.max' => 'يمكن رفع 10 صور في المرة الواحدة كحد أقصى.',
            'preview_images.*.mimes' => 'صور المعاينة تقبل JPG أو PNG أو WebP فقط.',
            'preview_images.*.max' => 'حجم صورة المعاينة الواحدة يجب ألا يتجاوز 20 ميجابايت.',
        ]);

        $previews->upload(
            $order,
            $request->file('preview_images', []),
            $validated['preview_note'] ?? null,
            $request->user(),
        );

        return back()->with('success', 'تم رفع صور المعاينة وتجهيز رابط العميل بنجاح.');
    }

    public function destroy(
        Request $request,
        Order $order,
        OrderPreview $preview,
        OrderProductPreviewService $previews,
    ): JsonResponse|RedirectResponse {
        abort_unless($preview->product_gallery_id && $preview->order_id === $order->id, 404);
        $previewId = $preview->id;
        $previews->delete($preview, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم حذف صورة المعاينة.',
                'deleted_preview_id' => $previewId,
            ]);
        }

        return back()->with('success', 'تم حذف صورة المعاينة.');
    }

    public function destroyMany(
        Request $request,
        Order $representative,
        OrderProductPreviewService $previews,
    ): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'preview_ids' => ['required', 'array', 'min:1', 'max:100'],
            'preview_ids.*' => ['required', 'integer', 'distinct'],
        ], [
            'preview_ids.required' => 'حدد صورة معاينة واحدة على الأقل.',
            'preview_ids.min' => 'حدد صورة معاينة واحدة على الأقل.',
            'preview_ids.max' => 'يمكن حذف 100 صورة كحد أقصى في المرة الواحدة.',
        ]);

        $ids = collect($validated['preview_ids'])->map(fn ($id): int => (int) $id)->values();
        $selected = OrderPreview::query()
            ->with(['order', 'productGallery'])
            ->whereNotNull('product_gallery_id')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($selected->count() !== $ids->count()
            || $selected->contains(fn (OrderPreview $preview): bool => $preview->productGallery?->checkout_group_key !== $representative->checkoutGroupKey())) {
            throw ValidationException::withMessages([
                'preview_ids' => 'بعض صور المعاينة المحددة لا تنتمي إلى عملية الشراء الحالية.',
            ]);
        }

        $deletedIds = $selected->map(function (OrderPreview $preview) use ($previews, $request): int {
            $previewId = (int) $preview->id;
            $previews->delete($preview, $request->user());

            return $previewId;
        })->values()->all();
        $message = 'تم حذف '.count($deletedIds).' صورة معاينة.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'deleted_preview_ids' => $deletedIds,
            ]);
        }

        return back()->with('success', $message);
    }
}
