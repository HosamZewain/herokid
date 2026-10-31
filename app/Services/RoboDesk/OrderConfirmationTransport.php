<?php

namespace App\Services\RoboDesk;

use App\Models\RoboDeskConversationSetting;
use App\Services\OrderAutomation\Process\ProcessTemplates;
use App\Services\RoboDesk\Conversations\ConversationPhone;
use App\Services\RoboDesk\Conversations\RoboDeskConversationHistoryProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OrderConfirmationTransport
{
    public function send(array $data): array
    {
        return $this->sendTemplate($data['customer']['phone'], 'herokid_order_confirmation',
            [$data['customer']['name'], $data['display_reference'], $data['order_details'], number_format($data['amounts']['total'], 2, '.', '')],
            'تأكيد طلب HeroKid '.$data['display_reference']);
    }

    public function sendTemplate(string $phone, string $template, array $values, string $summary): array
    {
        $fields = ProcessTemplates::fields($template);
        if (! $fields || ! array_is_list($values) || count($values) !== count($fields)
            || collect($values)->contains(fn ($v) => ! is_string($v) || ($template !== 'herokid_order_confirmation' && trim($v) === '') || mb_strlen($v) > 4000)) {
            throw new OrderConfirmationSendException('template_contract_invalid');
        }
        $settings = RoboDeskConversationSetting::find(1);
        if (! $settings?->enabled || blank($settings->email) || blank($settings->password)) {
            throw new OrderConfirmationSendException('confirmation_setup_required');
        }
        $http = Http::acceptJson()->withHeaders([
            'Authorization' => RoboDeskConversationHistoryProvider::authorization($settings->email, $settings->password),
        ])->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false]);
        $base = 'https://hero-kid.robodesk.ai/api/conversation';
        // At most eight requests (96s) including one close recovery and one safe 501 retry.
        // Keep below the job's 110s timeout and the shared contact lock's 130s lease.
        $closeRecoveryUsed = false;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $open = $this->openInteractions($http, $base, $phone);
            while ($open !== []) {
                try {
                    $closed = $http->post($base.'/close', ['closeData' => [
                        'conversations' => $open, 'isBulk' => true,
                        'status' => 'completed', 'statusType' => 'completed',
                    ], 'note' => null]);
                    if ($closed->successful() && $closed->json('success') === true) {
                        break;
                    }
                } catch (ConnectionException) {
                    // Closing sends no message. Re-read before the single recovery attempt.
                }
                if ($closeRecoveryUsed) {
                    throw new RuntimeException('confirmation_close_uncertain');
                }
                $closeRecoveryUsed = true;
                $open = $this->openInteractions($http, $base, $phone);
            }
            $response = $http->post($base.'/start/sendMsg', [
                'channel' => 'WhatsApp', 'to' => $phone, 'language' => 'ar-EG',
                'templateMessage' => $summary,
                'template' => [
                    'templateName' => $template, 'text' => implode(' ', array_map(fn ($i) => '$'.$i, range(1, count($values)))),
                    'data' => $values,
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

    private function openInteractions(PendingRequest $http, string $base, string $phone): array
    {
        $history = $http->get($base.'/messagesByPhone', ['phone' => $phone, 'channel' => 'WhatsApp']);
        $interactions = $history->json('interactions');
        if (! $history->successful() || ltrim((string) ConversationPhone::canonical($history->json('phone')), '+') !== $phone
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

        return $open;
    }
}
