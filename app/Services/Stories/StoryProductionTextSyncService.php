<?php

namespace App\Services\Stories;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\ProductionSceneSnapshotRefreshService;
use Illuminate\Validation\ValidationException;

class StoryProductionTextSyncService
{
    /** Explicit catalog save operation, never called by the Agent API read path. */
    public function sync(Story $story, User $actor, bool $allowCompleted = false): array
    {
        $result = ['updated_units' => [], 'unchanged_units' => [], 'blocked_units' => []];
        Order::query()->where('story_id', $story->id)->whereHas('sceneTextSnapshots')->select('id')
            ->chunkById(100, function ($orders) use ($story, $actor, $allowCompleted, &$result): void {
                foreach ($orders as $order) {
                    try {
                        $refresh = app(ProductionSceneSnapshotRefreshService::class)->refresh($order->id, $story->id, $actor,
                            'Story template save: production text synchronization', true, $allowCompleted);
                        $result[$refresh['changed_snapshot_ids'] === [] ? 'unchanged_units' : 'updated_units'][] = 'story:'.$order->id;
                    } catch (ValidationException $exception) {
                        $result['blocked_units'][] = ['unit' => 'story:'.$order->id, 'reason' => $exception->getMessage()];
                    }
                }
            });

        return $result;
    }
}
