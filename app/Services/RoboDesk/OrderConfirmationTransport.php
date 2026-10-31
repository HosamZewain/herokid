<?php

namespace App\Services\RoboDesk;

use App\Models\RoboDeskConversationSetting;
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
        $response = Http::acceptJson()->withHeaders([
            'Authorization' => RoboDeskConversationHistoryProvider::authorization($settings->email, $settings->password),
        ])->connectTimeout(3)->timeout(15)->withOptions(['allow_redirects' => false])
            ->post('https://hero-kid.robodesk.ai/api/conversation/start/sendMsg', [
                'channel' => 'WhatsApp', 'to' => $data['customer']['phone'], 'language' => 'ar-EG',
                'templateMessage' => 'تأكيد طلب HeroKid '.$data['display_reference'],
                'template' => [
                    'templateName' => 'herokid_order_confirmation', 'text' => '$1 $2 $3 $4',
                    'data' => [$data['customer']['name'], $data['display_reference'], $data['order_details'],
                        number_format($data['amounts']['total'], 2, '.', '')],
                ],
            ]);
        // Only these two identifiers are retained; the response also contains private conversation data.
        if ($response->status() === 501 && trim($response->body()) === 'Customer already have another interaction') {
            throw new OrderConfirmationSendException('open_conversation_not_supported');
        }
        if ($response->status() === 409) {
            throw new OrderConfirmationSendException('template_disabled');
        }
        $messageId = $response->json('channelId');
        $interactionId = $response->json('_id');
        if (! $response->successful() || ! is_string($messageId) || ! str_starts_with($messageId, 'wamid.')
            || ! is_string($interactionId) || $interactionId === '') {
            throw new RuntimeException('confirmation_send_uncertain');
        }

        return ['message_id' => $messageId, 'interaction_id' => $interactionId];

    }
}
