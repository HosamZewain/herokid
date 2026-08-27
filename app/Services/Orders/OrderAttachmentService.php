<?php

namespace App\Services\Orders;

use App\Models\OrderAttachment;
use Illuminate\Support\Facades\Storage;

class OrderAttachmentService
{
    /**
     * Permanently delete expired private files and their metadata.
     *
     * @return array{expired: int, deleted_files: int}
     */
    public function cleanupExpired(int $limit = 100): array
    {
        $attachments = OrderAttachment::query()
            ->where('expires_at', '<=', now())
            ->oldest('id')
            ->limit(max(1, $limit))
            ->get();

        $deletedFiles = 0;

        foreach ($attachments as $attachment) {
            $disk = Storage::disk($attachment->disk ?: 'local');

            if ($disk->exists($attachment->path)) {
                $deletedFiles++;
            }

            $attachment->delete();
        }

        return [
            'expired' => $attachments->count(),
            'deleted_files' => $deletedFiles,
        ];
    }
}
