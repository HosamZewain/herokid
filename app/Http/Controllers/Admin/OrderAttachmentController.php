<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Services\Orders\OrderAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderAttachmentController extends Controller
{
    public function store(Request $request, Order $order, OrderAttachmentService $attachments)
    {
        $validated = $request->validate([
            'attachments' => ['required', 'array', 'min:1', 'max:10'],
            'attachments.*' => OrderAttachmentService::fileRules(),
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'attachments.required' => 'اختر ملف PDF أو صورة واحدة على الأقل.',
            'attachments.*.mimes' => 'الملفات المسموحة: PDF، JPG، PNG، WEBP، HEIC.',
            'attachments.*.max' => 'حد حجم الملف الواحد 50 ميجا.',
        ]);

        $attachments->upload($order, $request->file('attachments', []), $validated['note'] ?? null, $request->user(), $request);

        return back()->with('success', 'تم رفع '.count($request->file('attachments', [])).' مرفق بنجاح. سيتم حذفه تلقائيًا بعد '.OrderAttachmentService::VALIDITY_DAYS.' يومًا.');
    }

    public function show(OrderAttachment $attachment, OrderAttachmentService $attachments)
    {
        return $attachments->response($attachment);
    }

    public function download(OrderAttachment $attachment, OrderAttachmentService $attachments)
    {
        return $attachments->response($attachment, 'attachment');
    }

    public function destroy(
        Request $request,
        OrderAttachment $attachment,
        OrderAttachmentService $attachments,
    ): JsonResponse|RedirectResponse
    {
        $attachmentId = $attachments->delete($attachment, $request->user(), $request);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم حذف المرفق نهائيًا.',
                'deleted_attachment_id' => $attachmentId,
            ]);
        }

        return back()->with('success', 'تم حذف المرفق نهائيًا.');
    }

    public function destroyMany(
        Request $request,
        Order $representative,
        OrderAttachmentService $attachments,
    ): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'attachment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'attachment_ids.*' => ['required', 'integer', 'distinct'],
        ], [
            'attachment_ids.required' => 'حدد مرفقًا واحدًا على الأقل.',
            'attachment_ids.min' => 'حدد مرفقًا واحدًا على الأقل.',
            'attachment_ids.max' => 'يمكن حذف 100 مرفق كحد أقصى في المرة الواحدة.',
        ]);

        $ids = collect($validated['attachment_ids'])->map(fn ($id): int => (int) $id)->values();
        $selected = OrderAttachment::query()
            ->with('order')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($selected->count() !== $ids->count()
            || $selected->contains(fn (OrderAttachment $attachment): bool => $attachment->order?->checkoutGroupKey() !== $representative->checkoutGroupKey())) {
            throw ValidationException::withMessages([
                'attachment_ids' => 'بعض المرفقات المحددة لا تنتمي إلى عملية الشراء الحالية.',
            ]);
        }

        $deletedIds = $selected
            ->map(fn (OrderAttachment $attachment): int => $attachments->delete($attachment, $request->user(), $request))
            ->values()
            ->all();

        $message = 'تم حذف '.count($deletedIds).' مرفق نهائيًا.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'deleted_attachment_ids' => $deletedIds,
            ]);
        }

        return back()->with('success', $message);
    }
}
