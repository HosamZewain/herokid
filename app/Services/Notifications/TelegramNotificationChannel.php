<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannelInterface;
use App\DTOs\Notifications\NotificationMessage;
use App\DTOs\Notifications\NotificationResult;
use App\DTOs\Notifications\NotificationTestRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TelegramNotificationChannel implements NotificationChannelInterface
{
    public function __construct(private readonly NotificationCredentialService $credentials) {}

    public function isAvailable(): bool
    {
        $channel = $this->credentials->channel('telegram');

        return $channel->is_active && $this->credentials->hasToken($channel);
    }

    public function send(NotificationMessage $message): NotificationResult
    {
        $channel = $this->credentials->channel('telegram');
        $token = $this->credentials->token($channel);

        if (! $channel->is_active || blank($token)) {
            return NotificationResult::failure('Telegram channel is not configured.');
        }

        try {
            $payload = [
                'chat_id' => $message->recipient,
                'text' => $this->sanitize($message->body),
                'disable_web_page_preview' => true,
            ];

            if (filled($message->parseMode)) {
                $payload['parse_mode'] = $message->parseMode;
            }

            $response = Http::asForm()
                ->timeout((int) config('admin_notifications.telegram.timeout_seconds', 10))
                ->post(rtrim((string) config('admin_notifications.telegram.api_base_url'), '/').'/bot'.$token.'/sendMessage', $payload);

            $json = $response->json();
            $metadata = [
                'http_status' => $response->status(),
                'ok' => (bool) data_get($json, 'ok', $response->successful()),
                'message_id' => data_get($json, 'result.message_id'),
                'chat_id' => data_get($json, 'result.chat.id'),
                'date' => data_get($json, 'result.date'),
            ];

            if ($response->failed() || data_get($json, 'ok') === false) {
                $description = $this->safeError((string) data_get($json, 'description', 'Telegram send failed.'));

                return NotificationResult::failure($description, $metadata);
            }

            return NotificationResult::success($metadata);
        } catch (Throwable $exception) {
            return NotificationResult::failure($this->safeError($exception->getMessage()));
        }
    }

    public function test(NotificationTestRequest $request): NotificationResult
    {
        return $this->send(new NotificationMessage(
            eventKey: 'notifications.test',
            channelType: 'telegram',
            recipient: $request->recipient,
            body: $request->message,
            severity: 'info',
            subject: 'Telegram test notification',
        ));
    }

    private function sanitize(string $body): string
    {
        $body = strip_tags($body);
        $body = preg_replace('/bot[0-9]+:[A-Za-z0-9_\-]+/', 'bot[redacted]', $body) ?: '';
        $body = preg_replace('/\b[0-9]{5,}:[A-Za-z0-9_\-]+\b/', '[redacted-token]', $body) ?: '';
        $body = preg_replace('/Bearer\s+[A-Za-z0-9_\-:.]+/', 'Bearer [redacted]', $body) ?: '';
        $body = preg_replace('/Key\s+[A-Za-z0-9_\-:.]+/', 'Key [redacted]', $body) ?: '';

        return Str::limit(trim($body), 3900, '...');
    }

    private function safeError(string $message): string
    {
        $message = preg_replace('/bot[0-9]+:[A-Za-z0-9_\-]+/', 'bot[redacted]', $message) ?: '';
        $message = preg_replace('/\b[0-9]{5,}:[A-Za-z0-9_\-]+\b/', '[redacted-token]', $message) ?: '';
        $message = preg_replace('/Bearer\s+[A-Za-z0-9_\-:.]+/', 'Bearer [redacted]', $message) ?: '';
        $message = preg_replace('/Key\s+[A-Za-z0-9_\-:.]+/', 'Key [redacted]', $message) ?: '';

        return Str::limit(trim($message) ?: 'Telegram send failed.', 500, '...');
    }
}
