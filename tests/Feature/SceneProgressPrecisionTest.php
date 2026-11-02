<?php

namespace Tests\Feature;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Services\CodexScenes\SceneProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SceneProgressPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $priorStatus = 'succeeded'): array
    {
        Http::preventStrayRequests();
        $start = (float) (time() - 10) + .2677007;
        $attempt = ['number' => 1, 'status' => 'succeeded', 'started_at' => $start, 'finished_at' => $start + 2];
        $prior = $attempt;
        $prior['started_at'] += .000000238;
        $prior['status'] = $priorStatus;
        if ($priorStatus === 'running') {
            unset($prior['finished_at']);
        } else {
            $prior['finished_at'] += .000000238;
        }
        $run = new CodexSceneRun(['id' => 'synthetic', 'protocol' => 4, 'scene_count' => 1, 'created_at' => now()->subMinute(),
            'snapshot' => [], 'scene_progress' => [1 => ['status' => $priorStatus, 'attempts' => [$prior]]]]);
        $order = Order::create(['order_number' => 'SYNTHETIC-PRECISION', 'status' => 'new']);
        $state = ['status' => 'completed', 'scene_progress' => [1 => ['status' => 'succeeded', 'attempts' => [$attempt]]]];

        return [$run, $state, $order];
    }

    public function test_terminal_epoch_rounding_is_not_a_mutated_outcome(): void
    {
        [$run, $state, $order] = $this->fixture();
        SceneProgress::collect($run, $state, [1 => ['sha256' => 'synthetic']], $order);
        $this->assertSame($state['scene_progress'], $run->scene_progress);
    }

    public function test_running_epoch_rounding_allows_verified_completion(): void
    {
        [$run, $state, $order] = $this->fixture('running');
        SceneProgress::collect($run, $state, [1 => ['sha256' => 'synthetic']], $order);
        $this->assertSame('succeeded', $run->scene_progress[1]['status']);
    }

    public function test_real_timestamp_change_still_requires_review(): void
    {
        [$run, $state, $order] = $this->fixture();
        $state['scene_progress'][1]['attempts'][0]['started_at'] += .001;
        $this->expectException(RuntimeException::class);
        SceneProgress::collect($run, $state, [1 => ['sha256' => 'synthetic']], $order);
    }
}
