<?php

namespace App\Services\RoboDesk\Conversations;

use App\Models\Order;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppConversationMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderConversationService
{
    public function __construct(private ConversationHistoryProvider $provider) {}

    private function contact(Order $order): array
    {
        $phone = ConversationPhone::canonical(data_get($order->delivery_details, 'phone'));
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'رقم العميل المحفوظ غير صالح لعرض محادثة واتساب.']);
        }
        $account = (string) config('robodesk.conversations.account_key', 'primary');
        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $account)) {
            throw new RuntimeException('setup_required');
        }

        return [$phone, $account, hash('sha256', $phone)];
    }

    public function history(Order $order, ?int $before = null, array $links = []): array
    {
        [$phone, $account, $hash] = $this->contact($order);
        $conversation = WhatsAppConversation::where('account_key', $account)->where('phone_hash', $hash)->first();
        $configured = $this->provider->configured();
        $messages = collect();
        $more = false;
        if ($conversation) {
            $query = $conversation->messages();
            if ($before !== null) {
                $cursor = $conversation->messages()->find($before);
                abort_unless($cursor, 422, 'مؤشر المحادثة غير صالح.');
                $cursorDate = $cursor->sent_at->format('Y-m-d H:i:s.v');
                $query->where(fn ($q) => $q->where('sent_at', '<', $cursorDate)
                    ->orWhere(fn ($q) => $q->where('sent_at', $cursorDate)->where('id', '<', $cursor->id)));
            }
            $messages = $query->orderByDesc('sent_at')->orderByDesc('id')->limit(51)->get();
            $more = $messages->count() > 50;
            $messages = $messages->take(50)->reverse()->values();
        }

        return [
            'key' => hash('sha256', $account.':'.$hash), 'configured' => $configured,
            'state' => ! $configured ? 'setup_required' : ($conversation?->sync_state ?? 'idle'),
            'error_code' => $conversation?->error_code,
            'last_synced_at' => $conversation?->last_synced_at?->toIso8601String(),
            'has_more' => $more, 'older_before' => $messages->first()?->id,
            'messages' => $messages->map(fn ($message) => [
                'id' => $message->id, 'direction' => $message->direction, 'kind' => $message->kind,
                'text' => $message->body, 'date' => $message->sent_at->toISOString(),
                'status' => $message->delivery_status, 'agent_name' => $message->sender_name,
                'sender_type' => $message->sender_type,
                'attachments' => collect($message->attachments ?? [])->map(fn ($attachment, $index) => $attachment + (
                    array_key_exists($index, $links[$message->remote_hash] ?? []) ? ['url' => $links[$message->remote_hash][$index]] : []
                ))->all(),
            ])->all(),
        ];
    }

    public function sync(Order $order, ?int $before = null, bool $full = false): array
    {
        if (! $this->provider->configured()) {
            throw new RuntimeException('setup_required');
        }
        [$phone, $account, $hash] = $this->contact($order);
        // A lock avoids duplicate remote calls across multiple employees/windows.
        $lock = Cache::lock('robodesk-history:'.hash('sha256', $account.$hash), 30);
        if (! $lock->get()) {
            return $this->history($order, $before) + ['refresh_deferred' => true];
        }
        try {
            $existing = WhatsAppConversation::where('account_key', $account)->where('phone_hash', $hash)->first();
            if ($existing?->last_synced_at?->gt(now()->subSeconds((int) config('robodesk.conversations.sync_cooldown_seconds', 12)))) {
                return $this->history($order, $before) + ['refresh_deferred' => true];
            }
            $page = $this->provider->fetch($phone, $full ? null : $existing?->sync_cursor);
            [$rows, $links] = $this->validate($page, $phone);
            DB::transaction(function () use ($rows, $phone, $account, $hash, $page): void {
                $conversation = WhatsAppConversation::firstOrCreate(['account_key' => $account, 'phone_hash' => $hash], ['phone' => $phone]);
                $conversation = WhatsAppConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $existingMessages = $conversation->messages()->whereIn('remote_hash', array_column($rows, 'remote_hash'))
                    ->get(['id', 'remote_hash', 'fingerprint'])->keyBy('remote_hash');
                foreach ($rows as $row) {
                    $message = $existingMessages->get($row['remote_hash']) ?? new WhatsAppConversationMessage(['conversation_id' => $conversation->id]);
                    if (! $message->exists || $message->fingerprint !== $row['fingerprint']) {
                        $message->fill($row)->save();
                    }
                }
                // A limited v1 window is never grounds for removing older saved messages.
                $changes = ['last_synced_at' => now(), 'sync_state' => 'ready', 'error_code' => null];
                if ($page->messages !== []) {
                    $changes['sync_cursor'] = $page->messages[array_key_last($page->messages)]['id'];
                } elseif ($page->cursorReset) {
                    $changes['sync_cursor'] = null;
                }
                $conversation->update($changes);
            });

            return $this->history($order, $before, $links);
        } finally {
            $lock->release();
        }
    }

    private function validate(ConversationHistoryPage $page, string $phone): array
    {
        if (ConversationPhone::canonical($page->phone) !== $phone || count($page->messages) > 10000 || ! array_is_list($page->messages)) {
            throw new RuntimeException('invalid_provider_response');
        }
        $rows = $links = [];
        foreach ($page->messages as $raw) {
            if (! is_array($raw) || ! is_string($raw['id'] ?? null) || strlen($raw['id']) > 512 || $raw['id'] === ''
            ) {
                throw new RuntimeException('invalid_provider_response');
            }
            // Production RoboDesk responses can include these non-message records.
            // Ignore only the verified system-log pair; keep its ID available for
            // the raw page's `after` cursor, even when no customer messages remain.
            if (($raw['direction'] ?? null) === 'system' && ($raw['type'] ?? null) === 'systemLog') {
                continue;
            }
            if (! in_array($raw['direction'] ?? null, ['in', 'out'], true)
                || ($raw['type'] ?? null) === 'systemLog'
                || ($raw['channel'] ?? 'WhatsApp') !== 'WhatsApp'
                || ! is_string($raw['date'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $raw['date'])
                || (isset($raw['text']) && (! is_string($raw['text']) || strlen($raw['text']) > 1000000))
                || ! is_array($raw['attachments'] ?? []) || count($raw['attachments'] ?? []) > 50) {
                throw new RuntimeException('invalid_provider_response');
            }
            try {
                $date = CarbonImmutable::parse($raw['date'])->utc()->format('Y-m-d H:i:s.v');
            } catch (\Throwable) {
                throw new RuntimeException('invalid_provider_response');
            }
            $hash = hash('sha256', $raw['id']);
            $attachments = [];
            foreach ($raw['attachments'] ?? [] as $index => $attachment) {
                if (! is_array($attachment)) {
                    throw new RuntimeException('invalid_provider_response');
                }
                $name = $attachment['file'] ?? $raw['fileName'] ?? 'مرفق';
                if (! is_string($name) || strlen($name) > 1024) {
                    throw new RuntimeException('invalid_provider_response');
                }
                $attachments[] = ['name' => $name];
                $url = $attachment['url'] ?? null;
                $parts = is_string($url) ? parse_url($url) : false;
                // URL lives only in this response/browser memory, never the DB or cache.
                $links[$hash][$index] = $parts && ($parts['scheme'] ?? '') === 'https'
                    && ! isset($parts['user']) && ! isset($parts['pass']) && filled($parts['host'] ?? null) ? $url : null;
            }
            $row = ['remote_hash' => $hash, 'remote_id' => $raw['id'],
                'direction' => $raw['direction'] === 'out' ? 'outbound' : 'inbound',
                'kind' => $this->plain($raw['type'] ?? 'text', 20), 'body' => $raw['text'] ?? null,
                'sent_at' => $date, 'attachments' => $attachments,
                'sender_name' => $this->plain($raw['agentName'] ?? null, 255),
                // Provider metadata is opaque plain text, not an authorization enum.
                'sender_type' => $this->plain($raw['senderType'] ?? null, 64),
                'delivery_status' => $this->plain($raw['status'] ?? null, 20)];
            $row['fingerprint'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $rows[$hash] = $row;
        }

        return [array_values($rows), $links];
    }

    private function plain(mixed $value, int $max): ?string
    {
        if ($value !== null && (! is_string($value) || mb_strlen($value) > $max)) {
            throw new RuntimeException('invalid_provider_response');
        }

        return $value;
    }
}
