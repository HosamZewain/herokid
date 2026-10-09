<?php

namespace App\Services\RoboDesk\Conversations;

use App\Models\RoboDeskConversationSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RoboDeskConversationHistoryProvider implements ConversationHistoryProvider
{
    public function configured(): bool
    {
        $settings = RoboDeskConversationSetting::find(1);

        return $settings?->enabled && filled($settings->email) && filled($settings->password);
    }

    /** JS charCodeAt operates on UTF-16 code units, including surrogate pairs. */
    public static function authorization(string $email, string $password): string
    {
        $hash = 0;
        foreach (unpack('v*', mb_convert_encoding($password, 'UTF-16LE', 'UTF-8')) ?: [] as $unit) {
            $hash = (($hash * 31) + $unit) & 0xFFFFFFFF;
        }
        if ($hash >= 0x80000000) {
            $hash -= 0x100000000;
        }

        return base64_encode('base64:'.$email.':'.$hash);
    }

    public function fetch(string $phone, ?string $after = null): ConversationHistoryPage
    {
        $settings = RoboDeskConversationSetting::find(1);
        if (! $settings?->enabled || ! filled($settings->email) || ! filled($settings->password)) {
            throw new RuntimeException('setup_required');
        }
        // Fixed trusted host: no administrator-supplied URL or redirect/SSRF path.
        $query = ['phone' => ltrim($phone, '+'), 'channel' => 'WhatsApp', 'limit' => $settings->conversation_limit];
        if ($after !== null) {
            $query['after'] = $after;
        }
        $response = Http::acceptJson()->withHeaders([
            'Authorization' => self::authorization($settings->email, $settings->password),
        ])->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])
            ->get('https://hero-kid.robodesk.ai/api/conversation/messagesByPhone', $query);
        if ($response->status() === 400 && $after !== null) {
            $page = $this->fetch($phone); // One bounded retry, never log the invalid cursor/body.

            return new ConversationHistoryPage($page->phone, $page->messages, true);
        }
        if (! $response->successful()) {
            // Never throw an HTTP exception containing provider body/credentials.
            throw new RuntimeException($response->status() === 401 ? 'provider_authentication' : 'provider_unavailable');
        }
        $payload = $response->json();
        if (! is_array($payload) || ! is_string($payload['phone'] ?? null) || ! is_array($payload['messages'] ?? null)) {
            throw new RuntimeException('invalid_provider_response');
        }

        return new ConversationHistoryPage($payload['phone'], $payload['messages']);
    }

    public function reply(string $phone, string $text, ?array $attachment): ConversationHistoryPage
    {
        $settings = RoboDeskConversationSetting::find(1);
        if (! $settings?->enabled || ! filled($settings->email) || ! filled($settings->password)) {
            throw new ConversationReplyException('SETUP_REQUIRED', 'فعّل ربط المحادثات أولاً.', 422);
        }
        $response = Http::acceptJson()->withHeaders(['Authorization' => self::authorization($settings->email, $settings->password)])
            ->connectTimeout(3)->timeout(15)->withOptions(['allow_redirects' => false])
            ->post('https://hero-kid.robodesk.ai/api/conversation/messagesByPhone/reply', array_filter([
                'phone' => ltrim($phone, '+'), 'channel' => 'WhatsApp', 'text' => $text, 'attachment' => $attachment,
            ], fn ($value) => $value !== null));
        $payload = $response->json();
        $code = $payload['code'] ?? null;
        $allowed = [400 => ['INVALID_PHONE', 'EMPTY_REPLY', 'TEXT_TOO_LONG', 'INVALID_ATTACHMENT'],
            409 => ['WINDOW_CLOSED', 'NO_OPEN_CONVERSATION'], 502 => ['SEND_FAILED']];
        if (in_array($code, $allowed[$response->status()] ?? [], true)) {
            $message = is_string($payload['message'] ?? null) ? mb_substr($payload['message'], 0, 2000) : 'رفض واتساب إرسال الرسالة.';
            throw new ConversationReplyException($code, $message, $response->status());
        }
        if ($response->status() === 401) {
            throw new ConversationReplyException('PROVIDER_AUTHENTICATION', 'راجع بيانات وصلاحيات حساب RoboDesk.', 502);
        }
        if (! $response->successful() || ! is_array($payload) || ! is_string($payload['phone'] ?? null) || ! is_array($payload['messages'] ?? null)) {
            throw new RuntimeException('invalid_reply_response');
        }

        return new ConversationHistoryPage($payload['phone'], $payload['messages']);
    }
}
