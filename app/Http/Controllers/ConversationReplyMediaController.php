<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppConversationReply;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationReplyMediaController extends Controller
{
    public function __invoke(string $reply): StreamedResponse
    {
        $record = WhatsAppConversationReply::where('request_id', $reply)->firstOrFail();
        abort_unless($record->attachment_expires_at?->isFuture() && $record->state !== 'failed'
            && $record->attachment_path && str_starts_with($record->attachment_path, 'robodesk/replies/'), 404);
        $disk = Storage::disk($record->attachment_disk);
        abort_unless($disk->exists($record->attachment_path), 404);
        $size = $disk->size($record->attachment_path);
        $stream = $disk->readStream($record->attachment_path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => $record->attachment_mime, 'Content-Length' => $size,
            'Cache-Control' => 'no-store, private', 'Content-Disposition' => 'inline; filename="image"']);
    }
}
