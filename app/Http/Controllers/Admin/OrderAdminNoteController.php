<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderAdminNote;
use App\Services\Orders\OrderAdminNoteService;
use App\Services\Orders\OrderAttachmentService;
use Illuminate\Http\Request;

class OrderAdminNoteController extends Controller
{
    public function store(Request $request, int $order, OrderAdminNoteService $notes)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => OrderAttachmentService::fileRules(required: false),
        ], [
            'body.required' => 'اكتب الملاحظة قبل الحفظ.',
            'body.max' => 'الملاحظة يجب ألا تزيد عن 5000 حرف.',
            'attachment.mimes' => 'المرفق المسموح: PDF، JPG، PNG، WEBP، HEIC.',
            'attachment.max' => 'حد حجم المرفق 50 ميجا.',
        ]);

        $target = Order::query()->withTrashed()->findOrFail($order);
        $notes->add($target, $request->user(), $validated['body'], $request, $request->file('attachment'));

        return back()->with('success', 'تمت إضافة الملاحظة إلى سجل الطلب.');
    }

    public function update(Request $request, int $order, int $note, OrderAdminNoteService $notes)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => OrderAttachmentService::fileRules(required: false),
            'remove_attachment' => ['nullable', 'boolean'],
        ], [
            'body.required' => 'اكتب الملاحظة قبل الحفظ.',
            'body.max' => 'الملاحظة يجب ألا تزيد عن 5000 حرف.',
            'attachment.mimes' => 'المرفق المسموح: PDF، JPG، PNG، WEBP، HEIC.',
            'attachment.max' => 'حد حجم المرفق 50 ميجا.',
        ]);

        [$target, $targetNote] = $this->resolveNote($order, $note);
        $notes->update(
            $target,
            $targetNote,
            $request->user(),
            $validated['body'],
            $request,
            $request->file('attachment'),
            $request->boolean('remove_attachment'),
        );

        return back()->with('success', 'تم تعديل الملاحظة وحفظ التغيير في سجل النشاط.');
    }

    public function destroy(Request $request, int $order, int $note, OrderAdminNoteService $notes)
    {
        [$target, $targetNote] = $this->resolveNote($order, $note);
        $notes->delete($target, $targetNote, $request->user(), $request);

        return back()->with('success', 'تم حذف الملاحظة. ما زال أثر الحذف محفوظًا في سجل النشاط.');
    }

    /** @return array{Order, OrderAdminNote} */
    private function resolveNote(int $orderId, int $noteId): array
    {
        $order = Order::query()->withTrashed()->findOrFail($orderId);
        $note = OrderAdminNote::query()
            ->whereKey($noteId)
            ->where('checkout_group_key', $order->checkoutGroupKey())
            ->firstOrFail();

        return [$order, $note];
    }
}
