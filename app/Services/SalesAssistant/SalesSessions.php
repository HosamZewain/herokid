<?php

namespace App\Services\SalesAssistant;

use App\Models\SalesConversation;
use App\Models\SalesTurn;
use App\Services\Cart\PackageCartExpander;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesSessions
{
    public function hash(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), config('app.key'));
    }

    public function current(Request $request, bool $create = false): ?SalesConversation
    {
        $id = $request->session()->get('sales_assistant.conversation');
        $conversation = is_string($id) ? SalesConversation::whereKey($id)->where('session_hash', $this->hash($request))->where('updated_at', '>', now()->subDays(30))->first() : null;
        if (! $conversation && $create) {
            $conversation = SalesConversation::create(['id' => (string) Str::uuid(), 'session_hash' => $this->hash($request), 'visitor_hash' => $this->visitor($request)]);
            $request->session()->put('sales_assistant.conversation', $conversation->id);
        }

        return $conversation;
    }

    private function visitor(Request $request): string
    {
        $id = $request->cookie('hk_assistant_visitor') ?: $request->session()->get('sales_assistant.visitor');
        if (! is_string($id) || ! Str::isUuid($id)) {
            $id = (string) Str::uuid();
            Cookie::queue(cookie('hk_assistant_visitor', $id, 43200, '/', null, $request->isSecure(), true, false, 'lax'));
        }
        $request->session()->put('sales_assistant.visitor', $id);

        return hash_hmac('sha256', $id, config('app.key'));
    }

    public function own(Request $request): SalesConversation
    {
        return $this->current($request) ?? abort(404);
    }

    public function event(SalesConversation $conversation, string $type, ?string $key = null, array $metrics = [], ?string $turnId = null): void
    {
        DB::table('sales_assistant_events')->insert(['conversation_id' => $conversation->id, 'type' => $type, 'catalog_key' => $key, 'visitor_hash' => $conversation->visitor_hash, 'turn_id' => $turnId,
            'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function knownPrivate(Request $request): array
    {
        return collect(app(PackageCartExpander::class)->expand($request->session()->get('cart.items', [])))->flatMap(fn ($i) => [
            $i['child_name'] ?? null, $i['parent_name'] ?? null, $i['school_name'] ?? null,
        ])->filter()->values()->all();
    }

    public function turns(SalesConversation $conversation): array
    {
        return SalesTurn::where('conversation_id', $conversation->id)->where('created_at', '>', now()->subDays(30))->latest('created_at')->orderByDesc('id')->limit(40)->get()->reverse()->map(function ($turn) {
            $result = $turn->result;
            if (is_array($result)) {
                $result['message'] = app(SalesPrivacy::class)->displayText((string) ($result['message'] ?? ''));
                $result['cards'] = app(SalesCatalog::class)->cards($result['recommendations'] ?? []);
            }

            return ['id' => $turn->id, 'client_nonce' => $turn->client_nonce, 'question' => $turn->request_text,
                'status' => $turn->status, 'result' => $result];
        })->values()->all();
    }
}
