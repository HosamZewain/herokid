<?php

namespace App\Services\Orders;

use App\Models\OrderItem;
use App\Models\OrderProcessRun;
use App\Models\PuzzleGeneration;

/** Staff selection retains provenance, not consent: the new preview still needs approval. */
class PreviewProductReuse
{
    public static function matches(OrderProcessRun $run, OrderItem $item, PuzzleGeneration $generation, array $current): bool
    {
        $saved = data_get($run->context, 'story_recovery.products.'.$item->id);

        return $saved && $generation->status === 'succeeded' && ! $generation->lease_until
            && $generation->order_item_id === $item->id && $generation->order_id === $item->order_id
            && $generation->checkout_key === $run->checkout_key && $generation->unit_key === $current['unit_key']
            && $generation->id === $saved['generation_id'] && $generation->preview_id === $saved['preview_id']
            && hash_equals($saved['sha256'], $generation->checksum ?? '')
            && hash_equals($saved['original_fingerprint'], $generation->context['fingerprint'] ?? '')
            && hash_equals($saved['input_fingerprint'], $current['fingerprint']);
    }
}
