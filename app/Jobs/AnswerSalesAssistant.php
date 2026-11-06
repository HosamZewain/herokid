<?php

namespace App\Jobs;

use App\Models\SalesTurn;
use App\Services\SalesAssistant\SalesBudget;
use App\Services\SalesAssistant\SalesProvider;
use App\Support\RequestSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class AnswerSalesAssistant implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(public string $turnId, public array $cart)
    {
        $this->onConnection('storefront-assistant')->onQueue('storefront-assistant');
    }

    public function handle(SalesProvider $provider, SalesBudget $budget): void
    {
        // Reset only this long-lived worker's request memo, never the shared cache.
        request()->attributes->remove(RequestSettings::class);
        $claimed = DB::transaction(function () {
            $turn = SalesTurn::whereKey($this->turnId)->lockForUpdate()->first();
            if (! $turn || $turn->status !== 'queued') {
                return null;
            }
            $turn->update(['status' => 'processing', 'started_at' => now()]);

            return $turn;
        });
        if (! $claimed) {
            return;
        }
        try {
            $reply = $provider->answer($claimed, $this->cart);
        } catch (\Throwable) {
            $reply = ['status' => 'uncertain', 'usage' => []];
        }
        $budget->settle($claimed, $reply['usage'], $reply['status'], $reply['result'] ?? null);
    }

    public function failed(?\Throwable $exception): void
    {
        if ($turn = SalesTurn::find($this->turnId)) {
            app(SalesBudget::class)->settle($turn, [], 'uncertain', null);
        }
    }
}
