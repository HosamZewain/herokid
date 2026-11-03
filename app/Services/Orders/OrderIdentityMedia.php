<?php

namespace App\Services\Orders;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\Order;
use App\Models\OrderAttachment;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OrderIdentityMedia
{
    /** Independent attachment object: deleting an attachment cannot delete the identity. */
    public function attach(Order $order, ChildIdentityGenerationAttempt $attempt): void
    {
        if ($order->child_identity_request_id !== $attempt->child_identity_request_id || $attempt->status !== 'succeeded') {
            throw new RuntimeException('identity_attachment_scope');
        }
        $unit = 'identity:'.$attempt->id;
        if ($order->attachments()->where('production_unit_key', $unit)->exists()) {
            return;
        }
        $bytes = Storage::disk($attempt->output_disk)->get($attempt->output_storage_path);
        if (! hash_equals($attempt->output_checksum, hash('sha256', $bytes))) {
            throw new RuntimeException('identity_attachment_checksum');
        }
        $mime = getimagesizefromstring($bytes)['mime'] ?? '';
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? throw new RuntimeException('identity_attachment_format');
        $disk = config('media.private_disk', 'local');
        $path = 'orders/identity-attachments/'.$order->id.'/attempt-'.$attempt->id.'.'.$ext;
        if (! Storage::disk($disk)->put($path, $bytes)
            || ! hash_equals($attempt->output_checksum, hash('sha256', Storage::disk($disk)->get($path)))) {
            throw new RuntimeException('identity_attachment_storage');
        }
        OrderAttachment::create(['order_id' => $order->id, 'production_unit_key' => $unit, 'disk' => $disk, 'path' => $path,
            'original_name' => 'identity-'.$order->id.'-attempt-'.$attempt->attempt_number.'.'.$ext,
            'mime_type' => $mime, 'size' => strlen($bytes), 'note' => 'هوية متولدة — الإرفاق لا يعني الاعتماد؛ حالة الاعتماد في قسم هوية الطفل.']);
        AutomationActivity::record($order, 'identity_attached', 'إرفاق نسخة الهوية المتولدة بالطلب دون اعتمادها.', ['attempt_id' => $attempt->id]);
    }
}
