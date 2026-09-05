<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPreview;
use App\Services\Orders\OrderProductPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
}
