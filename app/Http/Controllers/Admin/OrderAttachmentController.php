<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrderAttachmentController extends Controller
{
    private const VALIDITY_DAYS = 30;

    public function store(Request $request, Order $order)
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

        $created = collect();
        $validityDays = self::VALIDITY_DAYS;

        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store("order-attachments/{$order->id}", 'local');

            $created->push($order->attachments()->create([
                'uploaded_by_user_id' => $request->user()?->id,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'note' => $validated['note'] ?? null,
                'validity_days' => $validityDays,
                'expires_at' => now()->addDays($validityDays),
            ]));
        }

        AdminActivityLogger::log(
            action: 'order.attachments_uploaded',
            description: 'تم رفع مرفقات خاصة للطلب '.$order->order_number,
            subject: $order,
            properties: [
                'attachment_ids' => $created->pluck('id')->all(),
                'file_names' => $created->pluck('original_name')->all(),
                'validity_days' => $validityDays,
                'expires_at' => $created->first()?->expires_at?->toIso8601String(),
            ],
            request: $request,
        );

        return back()->with('success', 'تم رفع '.count($request->file('attachments', []))." مرفق بنجاح. سيتم حذفه تلقائيًا بعد {$validityDays} يومًا.");
    }

    public function show(OrderAttachment $attachment)
    {
        return $this->fileResponse($attachment, 'inline');
    }

    public function download(OrderAttachment $attachment)
    {
        return $this->fileResponse($attachment, 'attachment');
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

    private function fileResponse(OrderAttachment $attachment, string $disposition)
    {
        if ($attachment->isExpired()) {
            abort(410, 'انتهت صلاحية المرفق.');
        }

        $disk = Storage::disk($attachment->disk ?: 'local');

        if (! $disk->exists($attachment->path)) {
            abort(404);
        }

        return $disk->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $disposition,
        );
    }
}
