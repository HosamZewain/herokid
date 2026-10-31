<?php

namespace App\Services\RoboDesk;

use App\Models\RoboDeskConversationSetting;
use App\Services\RoboDesk\Conversations\ConversationPhone;
use App\Services\RoboDesk\Conversations\RoboDeskConversationHistoryProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OrderConfirmationTransport
{
    public function send(array $data): array
    {
        $settings = RoboDeskConversationSetting::find(1);
        if (! $settings?->enabled || blank($settings->email) || blank($settings->password)) {
            throw new OrderConfirmationSendException('confirmation_setup_required');
        }
        $http = Http::acceptJson()->withHeaders([
            'Authorization' => RoboDeskConversationHistoryProvider::authorization($settings->email, $settings->password),
        ])->connectTimeout(3)->timeout(15)->withOptions(['allow_redirects' => false]);
        $base = 'https://hero-kid.robodesk.ai/api/conversation';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $history = $http->get($base.'/messagesByPhone', ['phone' => $data['customer']['phone'], 'channel' => 'WhatsApp']);
            $interactions = $history->json('interactions');
            if (! $history->successful() || ltrim((string) ConversationPhone::canonical($history->json('phone')), '+') !== $data['customer']['phone']
                || ! is_array($interactions) || ! array_is_list($interactions) || count($interactions) > 1000) {
                throw new RuntimeException('confirmation_history_uncertain');
            }
            $open = [];
            foreach ($interactions as $interaction) {
                if (! is_array($interaction) || ! is_bool($interaction['closed'] ?? null)
                    || ! is_string($interaction['id'] ?? null) || $interaction['id'] === '' || strlen($interaction['id']) > 255) {
                    throw new RuntimeException('confirmation_history_uncertain');
                }
                if ($interaction['closed'] === false) {
                    $open[] = ['_id' => $interaction['id']];
                }
            }
            if ($open !== []) {
                $closed = $http->post($base.'/close', ['closeData' => ['conversations' => $open, 'isBulk' => true], 'note' => null]);
                if (! $closed->successful() || $closed->json('success') !== true) {
                    throw new RuntimeException('confirmation_close_uncertain');
                }
            }
            $response = $http->post($base.'/start/sendMsg', [
                'channel' => 'WhatsApp', 'to' => $data['customer']['phone'], 'language' => 'ar-EG',
                'templateMessage' => 'تأكيد طلب HeroKid '.$data['display_reference'],
                'template' => [
                    'templateName' => 'herokid_order_confirmation', 'text' => '$1 $2 $3 $4',
                    'data' => [$data['customer']['name'], $data['display_reference'], $data['order_details'],
                        number_format($data['amounts']['total'], 2, '.', '')],
                ],
            ]);
            // The only safe retry is the exact, documented no-send response.
            if ($response->status() === 501 && trim($response->body()) === 'Customer already have another interaction') {
                if ($attempt === 0) {
                    continue;
                }
                throw new OrderConfirmationSendException('conversation_race_retry_exhausted');
            }
            if ($response->status() === 409) {
                throw new OrderConfirmationSendException('template_disabled');
            }
            $messageId = $response->json('channelId');
            $interactionId = $response->json('_id');
            if (! $response->successful() || ! is_string($messageId) || ! str_starts_with($messageId, 'wamid.') || strlen($messageId) > 512
                || ! is_string($interactionId) || $interactionId === '' || strlen($interactionId) > 255) {
                throw new RuntimeException('confirmation_send_uncertain');
            }

            // Never retain the rest of the conversation document.
            return ['message_id' => $messageId, 'interaction_id' => $interactionId];
        }
        throw new RuntimeException('confirmation_send_uncertain');
    }
}
