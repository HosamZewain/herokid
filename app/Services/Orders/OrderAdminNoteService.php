<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderAdminNote;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrderAdminNoteService
{
    public function __construct(private readonly OrderAttachmentService $attachments) {}

    public function notesFor(Order $order): Collection
    {
        return OrderAdminNote::query()
            ->with(['author:id,name', 'lastEditor:id,name', 'attachment'])
            ->where('checkout_group_key', $order->checkoutGroupKey())
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    public function add(
        Order $order,
        User $author,
        string $body,
        Request $request,
        ?UploadedFile $file = null,
    ): OrderAdminNote {
        $attachment = $file
            ? $this->attachments->upload(
                $order,
                [$file],
                'مرفق ملاحظة فريق العمل',
                $author,
                $request,
            )->first()
            : null;

        try {
            $note = OrderAdminNote::query()->create([
                'checkout_group_key' => $order->checkoutGroupKey(),
                'representative_order_id' => $order->id,
                'author_user_id' => $author->id,
                'author_name' => $author->name,
                'body' => trim($body),
                'attachment_id' => $attachment?->id,
            ]);
        } catch (Throwable $exception) {
            $attachment?->delete();
            throw $exception;
        }

        AdminActivityLogger::log(
            action: 'order.note_added',
            description: 'إضافة ملاحظة دائمة إلى عملية الشراء '.$order->checkoutGroupKey().'.',
            subject: $order,
            properties: [
                'note_id' => $note->id,
                'checkout_group_key' => $note->checkout_group_key,
                'body_length' => mb_strlen($note->body),
                'attachment_id' => $attachment?->id,
                'attachment_name' => $attachment?->original_name,
            ],
            admin: $author,
            request: $request,
        );

        return $note->load(['author:id,name', 'lastEditor:id,name', 'attachment']);
    }

    public function update(
        Order $order,
        OrderAdminNote $note,
        User $editor,
        string $body,
        Request $request,
        ?UploadedFile $replacementFile = null,
        bool $removeAttachment = false,
    ): OrderAdminNote {
        $oldAttachment = $note->attachment;
        $newAttachment = $replacementFile
            ? $this->attachments->upload(
                $order,
                [$replacementFile],
                'مرفق ملاحظة فريق العمل',
                $editor,
                $request,
            )->first()
            : null;
        $oldBody = $note->body;

        try {
            DB::transaction(function () use ($note, $editor, $body, $newAttachment, $removeAttachment): void {
                $note->forceFill([
                    'body' => trim($body),
                    'last_edited_by_user_id' => $editor->id,
                    'attachment_id' => $newAttachment?->id
                        ?? ($removeAttachment ? null : $note->attachment_id),
                ])->save();
            });
        } catch (Throwable $exception) {
            $newAttachment?->delete();
            throw $exception;
        }

        if (($newAttachment || $removeAttachment) && $oldAttachment) {
            $oldAttachment->delete();
        }

        AdminActivityLogger::log(
            action: 'order.note_updated',
            description: 'تم تعديل ملاحظة فريق العمل في عملية الشراء '.$order->checkoutGroupKey().'.',
            subject: $order,
            properties: [
                'note_id' => $note->id,
                'checkout_group_key' => $note->checkout_group_key,
                'old_body_length' => mb_strlen($oldBody),
                'new_body_length' => mb_strlen($note->body),
                'body_changed' => $oldBody !== $note->body,
                'old_attachment_id' => $oldAttachment?->id,
                'new_attachment_id' => $note->attachment_id,
                'attachment_replaced' => (bool) $newAttachment,
                'attachment_removed' => $removeAttachment && ! $newAttachment,
            ],
            admin: $editor,
            request: $request,
        );

        return $note->fresh(['author:id,name', 'lastEditor:id,name', 'attachment']);
    }

    public function delete(Order $order, OrderAdminNote $note, User $actor, Request $request): void
    {
        $attachment = $note->attachment;
        $properties = [
            'note_id' => $note->id,
            'checkout_group_key' => $note->checkout_group_key,
            'body_length' => mb_strlen($note->body),
            'attachment_id' => $attachment?->id,
            'attachment_name' => $attachment?->original_name,
        ];

        DB::transaction(function () use ($note, $actor): void {
            $note->forceFill(['deleted_by_user_id' => $actor->id])->save();
            $note->delete();
        });

        $attachment?->delete();

        AdminActivityLogger::log(
            action: 'order.note_deleted',
            description: 'تم حذف ملاحظة فريق العمل من عملية الشراء '.$order->checkoutGroupKey().'.',
            subject: $order,
            properties: $properties,
            admin: $actor,
            request: $request,
        );
    }
}
