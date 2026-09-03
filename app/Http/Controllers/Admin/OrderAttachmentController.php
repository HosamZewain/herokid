<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Services\Orders\OrderAttachmentService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;

class OrderAttachmentController extends Controller
{
    public function store(Request $request, Order $order, OrderAttachmentService $attachments)
    {
        $validated = $request->validate([
            'attachments' => ['required', 'array', 'min:1', 'max:10'],
            'attachments.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:51200'],
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

    public function destroy(Request $request, OrderAttachment $attachment)
    {
        $order = $attachment->order;
        $properties = [
            'attachment_id' => $attachment->id,
            'file_name' => $attachment->original_name,
            'expires_at' => $attachment->expires_at?->toIso8601String(),
        ];

        $attachment->delete();

        AdminActivityLogger::log(
            action: 'order.attachment_deleted',
            description: 'تم حذف مرفق من الطلب '.($order?->order_number ?? ''),
            subject: $order,
            properties: $properties,
            request: $request,
        );

        return back()->with('success', 'تم حذف المرفق نهائيًا.');
    }
}
