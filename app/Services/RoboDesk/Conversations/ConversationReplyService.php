<?php

namespace App\Services\RoboDesk\Conversations;

use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppConversationMessage;
use App\Models\WhatsAppConversationReply;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class ConversationReplyService
{
    public function __construct(private OrderConversationService $history, private RoboDeskConversationHistoryProvider $provider) {}

    public function send(Order $order, User $user, string $requestId, string $text, ?UploadedFile $image): array
    {
        [$phone, $account, $hash] = $this->history->contact($order);
        $fingerprint = hash('sha256', json_encode([$text, $image ? hash_file('sha256', $image->getRealPath()) : null, $order->id, $account, $hash]));
        $previous = WhatsAppConversationReply::where('request_id', $requestId)->first();
        if ($previous) {
            if ($previous->user_id !== $user->id || $previous->fingerprint !== $fingerprint) {
                throw new ConversationReplyException('REQUEST_CONFLICT', 'طلب الإرسال مستخدم لرسالة مختلفة.');
            }
            if ($previous->state === 'accepted') {
                return $this->history->history($order) + ['reply_state' => 'accepted'];
            }
            throw new ConversationReplyException($previous->error_code ?? 'SEND_UNCERTAIN', $previous->error_message ?? 'الإرسال قيد التحقق. حدّث المحادثة قبل إرسال الرسالة مرة أخرى.', $previous->error_status ?? 409);
        }
        if (! $this->provider->configured()) {
            throw new ConversationReplyException('SETUP_REQUIRED', 'فعّل ربط المحادثات من إعدادات RoboDesk أولاً.', 422);
        }
        $conversation = WhatsAppConversation::where('account_key', $account)->where('phone_hash', $hash)->first();
        $last = $conversation?->messages()->where('direction', 'inbound')->where('kind', '!=', 'reaction')->max('sent_at');
        if (! $last || CarbonImmutable::parse($last, 'UTC')->lte(now()->subDay()) || CarbonImmutable::parse($last, 'UTC')->gt(now())) {
            throw new ConversationReplyException('WINDOW_CLOSED', 'انتهت مهلة الرد على واتساب أو لم تصل رسالة من العميل. يلزم إرسال قالب؛ دعم القوالب سيتاح لاحقاً.');
        }
        $lock = Cache::lock('robodesk-reply:'.hash('sha256', $account.':'.$hash), 60);
        if (! $lock->get()) {
            throw new ConversationReplyException('REPLY_BUSY', 'يوجد إرسال آخر لهذه المحادثة. انتظر ثم حدّث المحادثة.');
        }
        $operation = null;
        $remoteStarted = false;
        try {
            // Commit an operation BEFORE contacting WhatsApp. Retries never re-send it.
            $operation = WhatsAppConversationReply::create(['request_id' => $requestId, 'order_id' => $order->id, 'user_id' => $user->id,
                'employee_name' => $user->name, 'account_key' => $account, 'phone_hash' => $hash, 'fingerprint' => $fingerprint]);
            $attachment = null;
            if ($image) {
                $mime = $image->getMimeType();
                $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
                $diskName = (string) config('media.private_disk', 'local');
                $path = 'robodesk/replies/'.$requestId.'.'.$extension;
                $operation->update(['attachment_disk' => $diskName, 'attachment_path' => $path, 'attachment_mime' => $mime, 'attachment_expires_at' => now()->addDay()]);
                $url = URL::temporarySignedRoute('conversation-reply-media', $operation->attachment_expires_at, ['reply' => $requestId]);
                if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    throw new ConversationReplyException('INVALID_ATTACHMENT', 'إرسال الصور يتطلب رابط الموقع الآمن HTTPS.', 422);
                }
                $stream = fopen($image->getRealPath(), 'rb');
                try {
                    if (! Storage::disk($diskName)->put($path, $stream)) {
                        throw new ConversationReplyException('INVALID_ATTACHMENT', 'تعذر تجهيز الصورة. حاول لاحقاً.', 502);
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $attachment = ['url' => $url, 'type' => 'image', 'fileName' => 'image.'.$extension];
            }
            $remoteStarted = true;
            $page = $this->provider->reply($phone, $text, $attachment);
            [$rows, $links] = $this->history->validate($page, $phone);
            if ($rows === [] || collect($rows)->contains(fn ($row) => $row['direction'] !== 'outbound')) {
                throw new \RuntimeException('invalid_reply_response');
            }
            DB::transaction(function () use ($conversation, $rows, $operation, $user): void {
                foreach ($rows as $row) {
                    $message = WhatsAppConversationMessage::firstOrNew(['conversation_id' => $conversation->id, 'remote_hash' => $row['remote_hash']]);
                    $message->fill($row + ['employee_id' => $user->id, 'employee_name' => $user->name])->save();
                }
                $operation->update(['state' => 'accepted']);
                // Do not advance the read/delta cursor past unseen customer messages.
            });

            return $this->history->history($order, null, $links) + ['reply_state' => 'accepted'];
        } catch (ConversationReplyException $e) {
            if ($operation) {
                $operation->update(['state' => 'failed', 'error_code' => $e->reason, 'error_message' => $e->getMessage(), 'error_status' => $e->status]);
                $this->deleteImage($operation);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($operation) {
                $code = $remoteStarted ? 'SEND_UNCERTAIN' : 'SEND_FAILED';
                $message = $remoteStarted ? 'لم نتمكن من تأكيد نتيجة الإرسال. حدّث المحادثة وتحقق قبل إرسال نفس الرسالة مجدداً.' : 'تعذر تجهيز الرسالة. لم يتم إرسالها.';
                // A timeout/invalid response/DB failure may follow an accepted send.
                $operation->update(['state' => $remoteStarted ? 'unknown' : 'failed', 'error_code' => $code, 'error_message' => $message, 'error_status' => 502]);
                if (! $remoteStarted) {
                    $this->deleteImage($operation);
                }
            }
            throw new ConversationReplyException($remoteStarted ? 'SEND_UNCERTAIN' : 'SEND_FAILED', $remoteStarted ? 'نتيجة الإرسال غير مؤكدة. حدّث المحادثة قبل إعادة الإرسال؛ لن نكرر الرسالة تلقائياً.' : 'تعذر تجهيز الرسالة. لم يتم إرسالها.', 502);
        } finally {
            $lock->release();
        }
    }

    public function deleteImage(WhatsAppConversationReply $reply): void
    {
        if ($reply->attachment_path && str_starts_with($reply->attachment_path, 'robodesk/replies/')) {
            if (Storage::disk($reply->attachment_disk)->delete($reply->attachment_path)) {
                $reply->update(['attachment_path' => null]);
            }
        }
    }

    public function cleanup(): int
    {
        $count = 0;
        foreach (WhatsAppConversationReply::whereNotNull('attachment_path')->where('attachment_expires_at', '<=', now())->orderBy('id')->limit(500)->get() as $reply) {
            $this->deleteImage($reply);
            if ($reply->attachment_path === null) {
                $count++;
            }
        }

        return $count;
    }
}
