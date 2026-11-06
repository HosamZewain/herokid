<?php

namespace App\Services\SalesAssistant;

use App\Models\SalesConversation;
use App\Models\SalesTurn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesBudget
{
    /** Always lock conversation -> budget day -> session day, including on settlement. */
    public function reserve(SalesConversation $conversation, string $text, string $nonce): SalesTurn
    {
        $day = now('Africa/Cairo')->toDateString();
        // Bootstrap in autocommit: INSERT IGNORE inside the locked transaction can
        // hold shared unique-key locks which deadlock when many clients upgrade them.
        DB::table('sales_daily_budgets')->insertOrIgnore(['day' => $day]);

        return DB::transaction(function () use ($conversation, $text, $nonce, $day) {
            $conversation = SalesConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $existing = SalesTurn::where('conversation_id', $conversation->id)->where('client_nonce', $nonce)->first();
            if ($existing) {
                return $existing;
            }
            if (SalesTurn::where('conversation_id', $conversation->id)->whereIn('status', ['queued', 'processing'])->exists()) {
                throw ValidationException::withMessages(['message' => 'استنى ردي على الرسالة الحالية الأول.']);
            }
            $daily = DB::table('sales_daily_budgets')->where('day', $day)->lockForUpdate()->first();
            DB::table('sales_session_days')->insertOrIgnore(['session_hash' => $conversation->session_hash, 'day' => $day]);
            $session = DB::table('sales_session_days')->where('session_hash', $conversation->session_hash)->where('day', $day)->lockForUpdate()->first();
            $reserve = (int) config('sales_assistant.turn_reservation_micros', 100000);
            if ($daily->spent_micros + $daily->reserved_micros + $reserve > app(SalesSettings::class)->budget()
                || $session->turns >= config('sales_assistant.session_daily_turns', 40)) {
                throw ValidationException::withMessages(['message' => 'المحادثة الذكية مش متاحة دلوقتي. تقدر تكمل اختيارك وطلبك أو تكلم فريقنا على واتساب.']);
            }
            DB::table('sales_daily_budgets')->where('day', $day)->increment('reserved_micros', $reserve);
            DB::table('sales_session_days')->where('id', $session->id)->increment('turns');
            $conversation->touch();
            $turn = SalesTurn::create(['id' => (string) Str::uuid7(), 'conversation_id' => $conversation->id,
                'client_nonce' => $nonce, 'status' => 'queued', 'request_text' => $text, 'budget_day' => $day,
                'reserved_micros' => $reserve, 'capability_nonce' => Str::random(64)]);
            app(SalesSessions::class)->event($conversation, 'message_received', metrics: ['characters' => mb_strlen($text)], turnId: $turn->id);

            return $turn;
        }, 3);
    }

    public function settle(SalesTurn $turn, array $usage, string $status, ?array $result): void
    {
        DB::transaction(function () use ($turn, $usage, $status, $result) {
            SalesConversation::whereKey($turn->conversation_id)->lockForUpdate()->firstOrFail();
            $turn = SalesTurn::whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if (! in_array($turn->status, ['queued', 'processing'], true)) {
                return;
            }
            // A queued item may have started while maintenance was acquiring its locks.
            if ($status === 'expired' && $turn->status !== 'queued') {
                return;
            }
            DB::table('sales_daily_budgets')->where('day', $turn->budget_day)->lockForUpdate()->firstOrFail();
            // A missing/uncertain provider receipt retains the entire reservation.
            $known = isset($usage['input_tokens'], $usage['output_tokens']);
            $cost = $known ? (int) ceil(((int) $usage['input_tokens'] * config('sales_assistant.input_micros_per_million') * 1.25
                + (int) $usage['output_tokens'] * config('sales_assistant.output_micros_per_million')) / 1000000) : 0;
            if ($known) {
                DB::table('sales_daily_budgets')->where('day', $turn->budget_day)->decrement('reserved_micros', $turn->reserved_micros);
                DB::table('sales_daily_budgets')->where('day', $turn->budget_day)->increment('spent_micros', $cost);
            }
            $conversation = SalesConversation::findOrFail($turn->conversation_id);
            app(SalesSessions::class)->event($conversation, $status === 'completed' ? 'reply_completed' : 'reply_'.$status, metrics: ['usage_known' => $known], turnId: $turn->id);
            $duration = $turn->started_at ? min(4294967295, max(0, (int) $turn->started_at->diffInMilliseconds(now()))) : 0;
            DB::table('sales_assistant_events')->where('turn_id', $turn->id)->where('type', $status === 'completed' ? 'reply_completed' : 'reply_'.$status)->update([
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0), 'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'cached_tokens' => (int) ($usage['cached_tokens'] ?? 0), 'reasoning_tokens' => (int) ($usage['reasoning_tokens'] ?? 0),
                'spent_micros' => $cost, 'duration_ms' => $duration, 'tool_calls' => $turn->tool_calls]);
            foreach ($result['recommendations'] ?? [] as $choice) {
                app(SalesSessions::class)->event($conversation, ($result['upsell'] ?? false) ? 'upsell_recommended' : 'recommended', $choice['key'], turnId: $turn->id);
            }
            $turn->update(['status' => $status, 'result' => $result, 'spent_micros' => $cost,
                'reserved_micros' => $known ? 0 : $turn->reserved_micros, 'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0), 'cached_tokens' => (int) ($usage['cached_tokens'] ?? 0), 'reasoning_tokens' => (int) ($usage['reasoning_tokens'] ?? 0), 'finished_at' => now()]);
        }, 3);
    }
}
