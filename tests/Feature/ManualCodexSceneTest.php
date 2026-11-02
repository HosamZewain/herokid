<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use App\Services\CodexScenes\ManualSceneService;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\AutomationHandoff;
use App\Services\Orders\OrderSceneTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManualCodexSceneTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private User $actor;

    private string $spool;

    private array $texts;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('s3_private');
        $this->spool = sys_get_temp_dir().'/codex-test-'.Str::uuid();
        mkdir($this->spool.'/outbox', 0770, true);
        file_put_contents($this->spool.'/outbox/health.json', '{"authenticated":true}');
        config(['codex_scenes.enabled' => true, 'codex_scenes.spool' => $this->spool, 'order_process.enabled' => false]);
        $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actor->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.photos.view', 'orders.production_prompt.manage'])->pluck('id'));
        $story = Story::create(['title' => 'Synthetic', 'slug' => 'synthetic', 'language' => 'ar', 'price' => 100, 'active' => true]);
        $this->order = Order::create(['story_id' => $story->id, 'order_number' => 'SYNTHETIC-1', 'parent_name' => 'Synthetic', 'child_name' => 'Synthetic child', 'child_age' => 7, 'status' => 'costomer_confirmed', 'paid_amount_cents' => 1]);
        $identity = ChildIdentityRequest::create(['uuid' => (string) Str::uuid(), 'resume_token_hash' => hash('sha256', 'synthetic'), 'parent_name' => 'Synthetic', 'parent_phone' => '01000000001', 'child_name' => 'Synthetic child', 'child_age' => 7, 'age_range' => '6-9', 'gender' => 'girl', 'status' => 'completed']);
        $png = $this->png(1);
        Storage::disk('s3_private')->put('synthetic/identity.png', $png);
        $attempt = ChildIdentityGenerationAttempt::create(['child_identity_request_id' => $identity->id, 'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(), 'status' => 'succeeded', 'model' => 'synthetic', 'prompt_version' => 'test', 'prompt_snapshot' => 'synthetic', 'prompt_hash' => hash('sha256', 'synthetic'), 'input_photos_count' => 2, 'image_size' => 'synthetic', 'image_quality' => 'synthetic', 'output_disk' => 's3_private', 'output_storage_path' => 'synthetic/identity.png', 'output_checksum' => hash('sha256', $png)]);
        $this->order->update(['child_identity_approved_attempt_id' => $attempt->id]);
        $this->texts = array_map(fn ($n) => ['scene_number' => $n, 'title' => 'Synthetic '.$n, 'text' => 'Synthetic scene '.$n, 'complete' => true], range(1, 13));
        $this->mock(OrderSceneTextService::class)->shouldReceive('present')->andReturn(['all_ready' => true, 'scenes' => $this->texts]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->spool);
        parent::tearDown();
    }

    private function png(int $color): string
    {
        $chunk = fn ($type, $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 1536, 1024, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat(chr($color)."\0\0", 1536), 1024))).$chunk('IEND', '');
    }

    private function start(): CodexSceneRun
    {
        $run = app(ManualSceneService::class)->start($this->order->fresh(), $this->actor);
        // Existing regression fixtures model legacy worker protocol 2.
        $run->update(['protocol' => 2]);
        return $run;
    }

    private function sceneOutput(CodexSceneRun $run, int $number, string $status = 'running', ?int $color = null): void
    {
        $dir = $this->spool.'/outbox/'.$run->id;
        File::ensureDirectoryExists($dir);
        $bytes = $this->png($color ?? $number);
        file_put_contents($dir.'/scene-'.sprintf('%02d', $number).'.png', $bytes);
        file_put_contents($dir.'/scene-'.sprintf('%02d', $number).'.json', json_encode(['id' => $run->id, 'order_id' => $run->order_id, 'identity_checksum' => $run->snapshot['identity_checksum'], 'scene' => $number, 'sha256' => hash('sha256', $bytes)]));
        $state = ['id' => $run->id, 'status' => $status];
        if ($run->protocol >= 4) {
            $state['scene_progress'] = $this->progress($run);
        }
        file_put_contents($dir.'/status.json', json_encode($state));
    }

    private function progress(CodexSceneRun $run): array
    {
        $progress = [];
        foreach (range(1,13) as $n) {
            if (isset($run->snapshot['retained_scenes'][$n])) {
                $progress[$n] = ['status' => 'retained', 'attempts' => []];
            } elseif (is_file($this->spool.'/outbox/'.$run->id.'/scene-'.sprintf('%02d', $n).'.json')) {
                $progress[$n] = ['status' => 'succeeded', 'attempts' => [['number' => 1, 'status' => 'succeeded', 'started_at' => $run->created_at->timestamp, 'finished_at' => $run->created_at->timestamp + 1]]];
            }
        }
        return $progress;
    }

    public function test_new_runs_use_independent_scene_protocol(): void
    {
        $run = app(ManualSceneService::class)->start($this->order->fresh(), $this->actor);
        $this->assertSame(4, $run->protocol);
        $this->assertTrue(\App\Support\OrderStatusRegistry::isValid('order', 'pending_team_review'));
    }

    public function test_partial_completion_hands_off_only_missing_scenes_and_records_attempts_once(): void
    {
        $run = $this->start(); $run->update(['protocol' => 4]);
        foreach (array_diff(range(1,13), [4]) as $n) { $this->sceneOutput($run, $n); }
        $progress = $this->progress($run);
        $at = $run->created_at->timestamp;
        $progress[4] = ['status' => 'failed', 'code' => 'invalid_dimensions', 'attempts' => [
            ['number' => 1, 'status' => 'failed', 'code' => 'invalid_dimensions', 'started_at' => $at, 'finished_at' => $at+1],
            ['number' => 2, 'status' => 'failed', 'code' => 'invalid_dimensions', 'started_at' => $at+1, 'finished_at' => $at+2],
        ]];
        ksort($progress);
        file_put_contents($this->spool.'/outbox/'.$run->id.'/status.json', json_encode(['id'=>$run->id, 'status'=>'needs_manual', 'scene_progress'=>$progress]));
        $service = app(ManualSceneService::class);
        $service->collect($run); $service->collect($run->fresh());
        $run->refresh();
        $this->assertSame('needs_manual', $run->status);
        $this->assertSame('pending_team_review', $this->order->fresh()->status);
        $this->assertNull($run->active_slot);
        $this->assertCount(12, $run->scenes);
        $this->assertArrayNotHasKey(4, $run->scenes);
        $this->assertArrayHasKey(13, $run->scenes);
        $this->assertDatabaseCount('order_attachments', 12);
        $this->assertFalse($service->automaticEligible($this->order->fresh()));
        $this->assertSame(14, AdminActivityLog::where('action', 'automation.codex_scene_attempt_started')->count());
        $this->assertSame(14, AdminActivityLog::where('action', 'automation.codex_scene_attempt_finished')->count());
        $this->assertSame(1, AdminActivityLog::where('action', 'automation.codex_scene_manual_handoff')->count());
        $html = view('admin.orders._codex-scene-attempts', ['attemptRun'=>$run])->render();
        $this->assertStringContainsString('المشاهد المطلوب استكمالها: 4', $html);
        $this->assertStringContainsString('2 محاولة', $html);
    }

    public function test_collector_rejects_retry_of_uncertain_attempt_and_false_completion(): void
    {
        $run=$this->start(); $run->update(['protocol'=>4]);
        $at=$run->created_at->timestamp;
        $progress=[1=>['status'=>'failed','code'=>'invalid_png','attempts'=>[
            ['number'=>1,'status'=>'failed','code'=>'generation_result_uncertain','started_at'=>$at,'finished_at'=>$at+1],
            ['number'=>2,'status'=>'failed','code'=>'invalid_png','started_at'=>$at+1,'finished_at'=>$at+2],
        ]]];
        File::ensureDirectoryExists($this->spool.'/outbox/'.$run->id);
        $path=$this->spool.'/outbox/'.$run->id.'/status.json';
        file_put_contents($path,json_encode(['id'=>$run->id,'status'=>'running','scene_progress'=>$progress]));
        try { app(ManualSceneService::class)->collect($run); $this->fail('Unsafe retry accepted'); }
        catch (\RuntimeException $e) { $this->assertSame('Unsafe attempt sequence',$e->getMessage()); }
        file_put_contents($path,json_encode(['id'=>$run->id,'status'=>'completed','scene_progress'=>[]]));
        $this->expectException(\RuntimeException::class);
        app(ManualSceneService::class)->collect($run);
    }

    public function test_state_race_waits_for_atomic_success_before_collecting_image(): void
    {
        $run=$this->start(); $run->update(['protocol'=>4]);
        $this->sceneOutput($run,1);
        $path=$this->spool.'/outbox/'.$run->id.'/status.json';
        $state=json_decode(file_get_contents($path),true);
        $state['scene_progress'][1]['status']='running';
        $state['scene_progress'][1]['attempts'][0]['status']='running';
        unset($state['scene_progress'][1]['attempts'][0]['finished_at']);
        file_put_contents($path,json_encode($state));
        app(ManualSceneService::class)->collect($run);
        $this->assertDatabaseCount('order_attachments',0);
        $this->sceneOutput($run,1);
        app(ManualSceneService::class)->collect($run->fresh());
        $this->assertDatabaseCount('order_attachments',1);
        $this->assertSame(1,AdminActivityLog::where('action','automation.codex_scene_attempt_started')->count());
    }

    public function test_repeated_running_reads_preserve_finished_attempts_and_do_not_duplicate_audit(): void
    {
        $run=$this->start(); $run->update(['protocol'=>4]);
        $service=app(ManualSceneService::class);
        $this->sceneOutput($run,1); $service->collect($run);
        $this->sceneOutput($run->fresh(),2); $service->collect($run->fresh());
        $service->collect($run->fresh());
        $this->assertCount(2,$run->fresh()->scenes);
        $this->assertSame(2,AdminActivityLog::where('action','automation.codex_scene_attempt_started')->count());
        $this->assertSame(2,AdminActivityLog::where('action','automation.codex_scene_attempt_finished')->count());
    }

    public function test_explicit_content_skip_continues_without_retrying_refused_scene(): void
    {
        $service=app(ManualSceneService::class); $old=$this->qualityRun(2);
        file_put_contents($this->spool.'/outbox/'.$old->id.'/status.json', json_encode(['id'=>$old->id,'status'=>'quality_review','code'=>'content_review']));
        $service->collect($old);
        $next=$service->resumeQuality($old->fresh(),'owner-skip-authorized',3);
        $this->assertSame([['scene'=>3,'code'=>'content_review']],$next->snapshot['skipped_scenes']);
        $service->tick();
        $manifest=json_decode(file_get_contents($this->spool.'/inbox/'.$next->id.'/manifest.json'),true);
        $this->assertSame(4,$manifest['protocol']);
        $this->assertSame(3,$manifest['skipped_scenes'][0]['scene']);
        foreach (range(4,13) as $n) { $this->sceneOutput($next,$n); }
        $progress=$this->progress($next);
        $progress[3]=['status'=>'failed','code'=>'content_review','attempts'=>[],'previous_run_id'=>$old->id];
        file_put_contents($this->spool.'/outbox/'.$next->id.'/status.json',json_encode(['id'=>$next->id,'status'=>'needs_manual','scene_progress'=>$progress]));
        $service->collect($next);
        $this->assertSame('needs_manual',$next->fresh()->status);
        $this->assertCount(12,$next->fresh()->scenes);
    }

    private function qualityRun(int $saved = 2): CodexSceneRun
    {
        $this->order->update(['payment_status' => 'partially_paid']);
        $run = $this->start();
        for ($n = 1; $n <= $saved; $n++) {
            $this->sceneOutput($run, $n);
        }
        $dir = $this->spool.'/outbox/'.$run->id;
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/status.json', json_encode(['id' => $run->id, 'status' => 'quality_review', 'code' => 'invalid_dimensions']));
        app(ManualSceneService::class)->collect($run);
        return $run->fresh();
    }

    public function test_quality_resume_preserves_attachments_original_evidence_and_finishes_remaining_scenes(): void
    {
        $service = app(ManualSceneService::class);
        $old = $this->qualityRun();
        $oldSnapshot = $old->snapshot;
        $state = file_get_contents($this->spool.'/outbox/'.$old->id.'/status.json');
        $next = $service->resumeQuality($old, 'owner-test-authorization');
        $this->assertSame($old->scenes, $next->scenes);
        $this->assertSame($oldSnapshot, $old->fresh()->snapshot);
        $this->assertSame('quality_review', $old->fresh()->status);
        $this->assertNull($old->fresh()->active_slot);
        $this->assertSame('waiting_slot', $next->status);
        $service->tick();
        $manifest = json_decode(file_get_contents($this->spool.'/inbox/'.$next->id.'/manifest.json'), true);
        $this->assertSame($old->id, $manifest['resume_from']);
        $this->assertCount(2, $manifest['retained_scenes']);
        $this->assertSame(hash('sha256', $this->png(2)), hash_file('sha256', $this->spool.'/inbox/'.$next->id.'/scene-02.png'));
        for ($n = 3; $n <= 13; $n++) {
            $this->sceneOutput($next, $n, 'completed');
        }
        $service->collect($next);
        $this->assertSame('completed', $next->fresh()->status);
        $this->assertSame('ready_preview', $this->order->fresh()->status);
        $this->assertDatabaseCount('order_attachments', 13);
        $this->assertSame($state, file_get_contents($this->spool.'/outbox/'.$old->id.'/status.json'));
        $this->assertSame(1, AdminActivityLog::where('action', 'automation.codex_scene_resume_authorized')->count());
        $this->assertNull(\App\Services\CodexScenes\SceneTiming::describe($next->fresh())['story_seconds']);
        Http::assertNothingSent();
    }

    public function test_repeated_resume_request_cannot_duplicate_work(): void
    {
        $old = $this->qualityRun(0);
        app(ManualSceneService::class)->resumeQuality($old, 'owner');
        try {
            app(ManualSceneService::class)->resumeQuality($old, 'owner');
            $this->fail('Repeated resume accepted');
        } catch (ValidationException $e) {
            $this->assertDatabaseCount('codex_scene_runs', 2);
            $this->assertSame(1, CodexSceneRun::where('active_slot', 1)->count());
        }
    }

    public function test_resume_rejects_uncertain_worker_changed_identity_hold_and_missing_payment(): void
    {
        $old = $this->qualityRun();
        $service = app(ManualSceneService::class);
        foreach (['uncertain_worker', 'identity_changed', 'hold', 'unpaid'] as $scenario) {
            DB::beginTransaction();
            $path = $this->spool.'/outbox/'.$old->id.'/status.json';
            $prior = file_get_contents($path);
            if ($scenario === 'uncertain_worker') {
                file_put_contents($path, json_encode(['id' => $old->id, 'status' => 'uncertain']));
            } elseif ($scenario === 'identity_changed') {
                $this->order->update(['child_name' => 'changed']);
            } elseif ($scenario === 'hold') {
                DB::table('order_automation_holds')->insert(['checkout_key' => $old->checkout_key, 'user_id' => $this->actor->id, 'fingerprint' => 'synthetic', 'runs' => '[]', 'confirmations' => '[]', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $this->order->update(['paid_amount_cents' => 0]);
            }
            try {
                $service->resumeQuality($old, 'owner');
                $this->fail('Unsafe resume accepted: '.$scenario);
            } catch (ValidationException $e) {
                $this->assertDatabaseCount('codex_scene_runs', 1);
            } finally {
                DB::rollBack();
                file_put_contents($path, $prior);
                $this->order->refresh();
            }
        }
    }

    public function test_operator_classified_content_rejection_frees_capacity_but_cannot_be_retried_as_quality(): void
    {
        $old = $this->qualityRun();
        $old->update(['status' => 'uncertain']);
        $path = $this->spool.'/outbox/'.$old->id.'/status.json';
        file_put_contents($path, json_encode(['id' => $old->id, 'status' => 'quality_review', 'code' => 'content_review']));
        app(ManualSceneService::class)->collect($old);
        $this->assertSame('quality_review', $old->fresh()->status);
        $this->assertSame('content_review', $old->fresh()->error_code);
        $this->expectException(ValidationException::class);
        app(ManualSceneService::class)->resumeQuality($old, 'owner');
    }

    public function test_resume_rejects_corrupt_saved_s3_without_releasing_original(): void
    {
        $old = $this->qualityRun();
        Storage::disk('s3_private')->put($old->scenes[1]['path'], 'corrupt');
        try {
            app(ManualSceneService::class)->resumeQuality($old, 'owner');
            $this->fail('Corruption accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame(1, $old->fresh()->active_slot);
            $this->assertDatabaseCount('codex_scene_runs', 1);
        }
    }

    public function test_worker_timing_is_preserved_and_excludes_collection_delay(): void
    {
        $run = $this->start();
        $this->travel(30)->seconds();
        $start = now()->timestamp;
        $this->sceneOutput($run, 1);
        $path = $this->spool.'/outbox/'.$run->id.'/status.json';
        $timing = ['started_at' => $start, 'elapsed_seconds' => 120, 'finished_at' => $start + 120];
        $this->travel(180)->seconds();
        file_put_contents($path, json_encode(['id' => $run->id, 'status' => 'quality_review', 'code' => 'invalid_dimensions', 'timing' => $timing]));
        app(ManualSceneService::class)->collect($run);
        $run->refresh();
        $this->assertEquals($timing, $run->timing);
        $value = \App\Services\CodexScenes\SceneTiming::describe($run);
        $this->assertSame('00:02:00', $value['duration']);
        $this->assertSame('00:00:30', $value['wait']);
        $this->travel(60)->minutes();
        app(ManualSceneService::class)->collect($run);
        $this->assertSame('00:02:00', \App\Services\CodexScenes\SceneTiming::describe($run->fresh())['duration']);
    }

    public function test_resumed_total_and_average_include_prior_work_without_the_pause(): void
    {
        $run = $this->start();
        $snapshot = $run->snapshot;
        $snapshot['resume_history'] = [['run_id' => 'prior', 'seconds' => 190, 'source' => 'worker']];
        $run->update(['snapshot' => $snapshot, 'status' => 'completed', 'active_slot' => null,
            'timing' => ['started_at' => now()->timestamp, 'finished_at' => now()->timestamp + 100, 'elapsed_seconds' => 100]]);
        $timing = \App\Services\CodexScenes\SceneTiming::describe($run);
        $this->assertSame('00:01:40', $timing['duration']);
        $this->assertSame('00:04:50', $timing['story_duration']);
        $this->assertSame('00:04:50', \App\Services\CodexScenes\SceneTiming::summary()['average']);
    }

    public function test_timing_rejects_clock_replacement_and_invalid_values(): void
    {
        $run = $this->start();
        $this->travel(30)->seconds();
        $start = now()->timestamp;
        $run->timing = ['started_at' => $start, 'elapsed_seconds' => 1];
        $class = \App\Services\CodexScenes\SceneTiming::class;
        $this->assertNull($class::validated(['started_at' => $start - 5, 'elapsed_seconds' => 10], $run));
        $this->assertNull($class::validated(['started_at' => $start, 'elapsed_seconds' => -1], $run));
        $this->assertNull($class::validated(['started_at' => $start, 'elapsed_seconds' => 10, 'finished_at' => $start + 100], $run));
        $this->assertNull($class::validated(['started_at' => 'invalid', 'elapsed_seconds' => 10], $run));
    }

    public function test_completed_average_keeps_legacy_estimates_and_failures_separate(): void
    {
        $run = $this->start();
        $start = now()->timestamp;
        $run->update(['status' => 'completed', 'active_slot' => null, 'timing' => ['started_at' => $start, 'elapsed_seconds' => 120, 'finished_at' => $start + 120]]);
        $legacy = $run->replicate();
        $legacy->id = (string) Str::uuid();
        $legacy->timing = null;
        $legacy->started_at = now();
        $legacy->finished_at = now()->addSeconds(900);
        $legacy->save();
        $failed = $run->replicate();
        $failed->id = (string) Str::uuid();
        $failed->status = 'quality_review';
        $failed->timing = ['started_at' => $start, 'elapsed_seconds' => 600, 'finished_at' => $start + 600];
        $failed->save();
        $this->assertSame(['count' => 1, 'average' => '00:02:00', 'estimated_count' => 1, 'estimated_average' => '00:15:00'], \App\Services\CodexScenes\SceneTiming::summary());
        $failed->timing = null;
        $failed->started_at = now()->subMinutes(5);
        $this->assertNull(\App\Services\CodexScenes\SceneTiming::describe($failed)['seconds']);
    }

    private function sibling(string $number = 'SYNTHETIC-SIBLING'): Order
    {
        $copy = $this->order->replicate();
        $copy->order_number = $number;
        $copy->status = 'costomer_confirmed';
        $copy->save();
        return $copy;
    }

    public function test_automatic_enrollment_handles_paid_siblings_once_and_audits_automation(): void
    {
        config(['codex_scenes.automatic' => true]);
        $this->order->update(['payment_status' => 'partially_paid']);
        $other = $this->sibling();
        $other->update(['paid_amount_cents' => 0, 'payment_status' => 'unpaid']);
        $service = app(ManualSceneService::class);
        $service->enroll(); $service->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 2);
        foreach (CodexSceneRun::all() as $run) {
            $this->assertSame('waiting_slot', $run->status);
            $this->assertSame('automatic', $run->source);
            $this->assertNull($run->requested_by);
        }
        $this->assertSame(2, AdminActivityLog::where('action', 'automation.codex_scene_enrolled')->whereNull('user_id')->count());
        Http::assertNothingSent();
    }

    public function test_queue_dispatches_two_and_keeps_third_waiting_then_never_reenrolls_stopped_story(): void
    {
        $service = app(ManualSceneService::class);
        $first = $this->start();
        $second = $service->start($this->sibling('SYNTHETIC-2'), $this->actor);
        $third = $service->start($this->sibling('SYNTHETIC-3'), $this->actor);
        $service->tick();
        $this->assertSame(2, CodexSceneRun::where('status','queued')->count());
        $this->assertSame(1, CodexSceneRun::where('status','waiting_slot')->count());
        $waiting = CodexSceneRun::where('status','waiting_slot')->firstOrFail();
        $service->cancel($waiting, $this->actor);
        $this->assertSame('stopped', $waiting->fresh()->status);
        $this->assertNull($waiting->fresh()->active_slot);
        $this->assertFileDoesNotExist($this->spool.'/inbox/'.$waiting->id.'/manifest.json');
        config(['codex_scenes.automatic' => true]);
        $waiting->order->update(['payment_status'=>'partially_paid']);
        $service->enroll();
        $this->assertDatabaseCount('codex_scene_runs',3);
    }

    public function test_auto_respects_manual_hold_review_only_and_completed_production(): void
    {
        config(['codex_scenes.automatic' => true]);
        $this->order->update(['payment_status'=>'pending_review']);
        app(ManualSceneService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs',0);
        $this->order->update(['payment_status'=>'paid_in_full','status'=>'ready_preview']);
        app(ManualSceneService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs',0);
        $this->order->update(['status'=>'costomer_confirmed']);
        DB::table('order_automation_holds')->insert(['checkout_key'=>$this->order->checkoutGroupKey(),'user_id'=>$this->actor->id,'fingerprint'=>str_repeat('a',64),'runs'=>'{}','confirmations'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        app(ManualSceneService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs',0);
    }

    public function test_payment_removed_while_waiting_prevents_provider_dispatch(): void
    {
        config(['codex_scenes.automatic' => true]);
        $this->order->update(['payment_status'=>'partially_paid']);
        $service = app(ManualSceneService::class); $service->enroll();
        $this->order->update(['paid_amount_cents'=>0,'payment_status'=>'unpaid']);
        $service->tick();
        $run=CodexSceneRun::firstOrFail();
        $this->assertSame('uncertain',$run->status);
        $this->assertFileDoesNotExist($this->spool.'/inbox/'.$run->id.'/manifest.json');
        Http::assertNothingSent();
    }

    public function test_output_from_another_story_is_rejected_before_s3_attachment(): void
    {
        $run=$this->start(); $this->sceneOutput($run,1);
        $p=$this->spool.'/outbox/'.$run->id.'/scene-01.json';
        $meta=json_decode(file_get_contents($p),true); $meta['order_id']=999999;
        file_put_contents($p,json_encode($meta));
        app(ManualSceneService::class)->tick();
        $this->assertSame('uncertain',$run->fresh()->status);
        $this->assertDatabaseCount('order_attachments',0);
    }

    public function test_uncertain_result_pauses_new_dispatch_and_monitor_is_authorized(): void
    {
        $run=$this->start(); $run->update(['status'=>'uncertain']);
        $other=app(ManualSceneService::class)->start($this->sibling(),$this->actor);
        app(ManualSceneService::class)->tick();
        $this->assertSame('waiting_slot',$other->fresh()->status);
        $this->actor->permissions()->attach(Permission::where('key','order_automation.view')->firstOrFail());
        $this->actingAs($this->actor)->get(route('admin.order-automation.codex-scenes'))->assertOk()->assertSee('قصة #'.$this->order->id)->assertSee('تحتاج مراجعة');
        $this->getJson(route('admin.order-automation.codex-scenes.progress',['status'=>'waiting_slot']))->assertOk()->assertJsonStructure(['html','version']);
        $this->actingAs(User::factory()->create())->get(route('admin.order-automation.codex-scenes'))->assertForbidden();
    }

    public function test_finished_siblings_release_slots_but_keep_checkout_owned_until_last_story_finishes(): void
    {
        $service = app(ManualSceneService::class);
        $service->start($this->order, $this->actor);
        $service->start($this->sibling('SYNTHETIC-2'), $this->actor);
        $service->start($this->sibling('SYNTHETIC-3'), $this->actor);
        $service->tick();
        $staged = CodexSceneRun::where('status', 'queued')->get();
        $this->assertCount(2, $staged);
        $waiting = CodexSceneRun::where('status', 'waiting_slot')->firstOrFail();
        foreach ($staged as $run) {
            foreach (range(1, 13) as $n) {
                $this->sceneOutput($run, $n, $n === 13 ? 'completed' : 'running');
            }
        }
        $service->tick();
        $service->tick(); // Collection order must not affect eventual next-slot admission.
        $this->assertSame('queued', $waiting->fresh()->status);
        $this->assertSame(1, CodexSceneRun::where('active_slot', 1)->count());
        foreach ($staged as $run) {
            $this->assertSame('ready_preview', $run->order->fresh()->status);
            $this->assertTrue(app(AutomationAssignment::class)->owns($run->order));
        }
        foreach (range(1, 13) as $n) {
            $this->sceneOutput($waiting, $n, $n === 13 ? 'completed' : 'running');
        }
        $service->tick();
        $this->assertFalse(app(AutomationAssignment::class)->owns($this->order));
        $this->assertDatabaseCount('order_attachments', 39);
        $service->tick();
        $this->assertDatabaseCount('order_attachments', 39);
        $this->assertSame(39, AdminActivityLog::where('action', 'automation.codex_scene_saved')->count());
        Http::assertNothingSent();
    }

    public function test_viewing_staff_do_not_see_generation_controls_without_production_permission(): void
    {
        $this->actor->permissions()->detach(Permission::where('key', 'orders.production_prompt.manage')->value('id'));
        $this->actingAs($this->actor->fresh());
        $html = view('admin.orders._codex-scenes', ['order' => $this->order])->render();
        $this->assertStringNotContainsString('name="confirm"', $html);
        $this->assertStringNotContainsString('متابعة طابور القصص', $html);
        $run = $this->start();
        $this->getJson(route('admin.orders.codex-scenes.progress', $this->order))->assertOk()
            ->assertJsonMissingPath('snapshot');
        $html = view('admin.orders._codex-scenes-progress', ['order' => $this->order, 'codexRuns' => collect([$run])])->render();
        $this->assertStringNotContainsString('إيقاف التوليد والاحتفاظ بالمشاهد', $html);
        $this->post(route('admin.orders.codex-scenes.stop', [$this->order, $run]))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_known_quality_failure_keeps_story_owned_without_blocking_other_slots_or_replaying(): void
    {
        $service = app(ManualSceneService::class);
        $failed = $this->start();
        $failed->update(['created_at' => now()->subMinute()]);
        $service->start($this->sibling('SYNTHETIC-2'), $this->actor);
        $service->start($this->sibling('SYNTHETIC-3'), $this->actor);
        $service->tick();
        // Model an already finished provider request with a known rejected output.
        $this->sceneOutput($failed, 1, 'quality_review');
        $path = $this->spool.'/outbox/'.$failed->id.'/status.json';
        file_put_contents($path, json_encode(['id'=>$failed->id, 'status'=>'quality_review', 'code'=>'invalid_dimensions']));
        $service->tick(); $service->tick();
        $this->assertSame('quality_review', $failed->fresh()->status);
        $this->assertSame('invalid_dimensions', $failed->fresh()->error_code);
        $this->assertSame(2, CodexSceneRun::where('status', 'queued')->count());
        $this->assertSame(1, count($failed->fresh()->scenes));
        $this->assertTrue(app(AutomationAssignment::class)->owns($this->order));
        $this->assertDatabaseCount('codex_scene_runs', 3);
        $this->assertDatabaseCount('order_attachments', 1);
        $service->cancel($failed, $this->actor);
        file_put_contents($path, json_encode(['id'=>$failed->id, 'status'=>'stopped']));
        $service->collect($failed->fresh());
        $this->assertNull($failed->fresh()->active_slot);
        Http::assertNothingSent();
    }

    public function test_paid_label_without_actual_money_cannot_auto_enroll(): void
    {
        config(['codex_scenes.automatic' => true]);
        $this->order->update(['payment_status' => 'paid_in_full', 'paid_amount_cents' => 0]);
        app(ManualSceneService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
        Http::assertNothingSent();
    }

    public function test_wrong_identity_metadata_is_rejected_even_for_the_correct_order(): void
    {
        $run = $this->start();
        $this->sceneOutput($run, 1);
        $path = $this->spool.'/outbox/'.$run->id.'/scene-01.json';
        $meta = json_decode(file_get_contents($path), true);
        $meta['identity_checksum'] = str_repeat('a', 64);
        file_put_contents($path, json_encode($meta));
        app(ManualSceneService::class)->tick();
        $this->assertSame('uncertain', $run->fresh()->status);
        $this->assertDatabaseCount('order_attachments', 0);
        Http::assertNothingSent();
    }

    public function test_progress_renders_saved_scenes_without_exposing_private_snapshot(): void
    {
        $run = $this->start();
        $this->sceneOutput($run, 1);
        app(ManualSceneService::class)->collect($run);
        $response = $this->actingAs($this->actor)->getJson(route('admin.orders.codex-scenes.progress', $this->order));
        $response->assertOk()->assertJsonStructure(['html', 'version']);
        $this->assertStringContainsString('1 / 13', $response->json('html'));
        $this->assertStringContainsString('تحميل الكل', $response->json('html'));
        $this->assertStringNotContainsString('synthetic/identity.png', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('codex_scene_runs', 1);
        Http::assertNothingSent();
    }

    public function test_download_contains_numbered_original_s3_bytes_and_is_order_scoped(): void
    {
        $run = $this->start();
        $this->sceneOutput($run, 2);
        $this->sceneOutput($run, 1);
        app(ManualSceneService::class)->collect($run);
        $response = $this->actingAs($this->actor)->get(route('admin.orders.codex-scenes.download', [$this->order, $run]));
        $response->assertOk()->assertDownload();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertSame(2, $zip->numFiles);
            $this->assertSame('scene-01.png', $zip->getNameIndex(0));
            $this->assertSame('scene-02.png', $zip->getNameIndex(1));
            $this->assertSame($this->png(1), $zip->getFromName('scene-01.png'));
            $this->assertSame($this->png(2), $zip->getFromName('scene-02.png'));
            $zip->close();
        } finally {
            @unlink($path);
        }
        $other = Order::create(['order_number' => 'SYNTHETIC-OTHER', 'parent_name' => 'Synthetic']);
        $this->get(route('admin.orders.codex-scenes.download', [$other, $run]))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('admin.orders.codex-scenes.download', [$this->order, $run]))->assertForbidden();
        $this->getJson(route('admin.orders.codex-scenes.progress', $this->order))->assertForbidden();
    }

    public function test_empty_or_corrupt_archive_cannot_be_downloaded(): void
    {
        $run = $this->start();
        $this->actingAs($this->actor)->get(route('admin.orders.codex-scenes.download', [$this->order, $run]))->assertNotFound();
        $this->sceneOutput($run, 1);
        app(ManualSceneService::class)->collect($run);
        $scene = $run->fresh()->scenes['1'];
        Storage::disk('s3_private')->put($scene['path'], 'corrupt');
        $this->get(route('admin.orders.codex-scenes.download', [$this->order, $run]))->assertStatus(503);
        $this->assertDatabaseCount('order_attachments', 1);
    }

    public function test_staff_can_start_codex_during_manual_hold_without_resuming_identity_flow(): void
    {
        DB::table('order_automation_holds')->insert([
            'checkout_key' => $this->order->checkoutGroupKey(), 'user_id' => $this->actor->id,
            'fingerprint' => hash('sha256', 'synthetic'), 'runs' => '{}', 'confirmations' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $run = $this->start();
        $this->assertSame('waiting_slot', $run->status);
        $this->assertTrue(app(AutomationAssignment::class)->owns($this->order));
        $this->assertTrue(AutomationHandoff::held($this->order->checkoutGroupKey()));
        Http::assertNothingSent();
    }

    public function test_manual_request_accepts_one_cent_reserves_checkout_and_does_not_call_ai(): void
    {
        $run = $this->start();
        $this->assertSame('waiting_slot', $run->status);
        $this->assertSame('generating', $this->order->fresh()->status);
        $this->assertTrue(app(AutomationAssignment::class)->owns($this->order));
        $this->assertStringNotContainsString('Synthetic child', $run->getRawOriginal('snapshot'));
        app(ManualSceneService::class)->tick();
        $this->assertFileExists($this->spool.'/inbox/'.$run->id.'/manifest.json');
        $this->assertSame('queued', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_review_only_unpaid_order_cannot_start(): void
    {
        $this->order->update(['paid_amount_cents' => 0, 'payment_status' => 'pending_review']);
        $this->expectException(ValidationException::class);
        $this->start();
    }

    public function test_missing_approved_identity_cannot_start(): void
    {
        $this->order->update(['child_identity_approved_attempt_id' => null]);
        $this->expectException(ValidationException::class);
        $this->start();
    }

    public function test_second_click_is_rejected_and_does_not_reset_run(): void
    {
        $this->start();
        $this->expectException(ValidationException::class);
        $this->start();
    }

    public function test_progress_is_private_deduplicated_and_complete_requires_thirteen_scenes(): void
    {
        $run = $this->start();
        $service = app(ManualSceneService::class);
        $this->sceneOutput($run, 1);
        $service->collect($run);
        $service->collect($run);
        $this->assertDatabaseCount('order_attachments', 1);
        $this->assertSame('generating', $this->order->fresh()->status);
        for ($n = 2; $n <= 13; $n++) {
            $this->sceneOutput($run, $n, $n === 13 ? 'completed' : 'running');
        }
        $service->collect($run);
        $this->assertDatabaseCount('order_attachments', 13);
        $this->assertSame('ready_preview', $this->order->fresh()->status);
        $this->assertNull($run->fresh()->active_slot);
        $this->assertFalse(app(AutomationAssignment::class)->owns($this->order));
        $this->assertSame(13, AdminActivityLog::where('action', 'automation.codex_scene_saved')->count());
        $this->assertDatabaseHas('order_attachments', ['production_unit_key' => 'story:'.$this->order->id, 'disk' => 's3_private']);
        Http::assertNothingSent();
    }

    public function test_missing_scene_and_duplicate_scene_hold_the_run_for_review(): void
    {
        $run = $this->start();
        $this->sceneOutput($run, 1);
        app(ManualSceneService::class)->collect($run);
        $this->sceneOutput($run, 2, 'completed', 1);
        app(ManualSceneService::class)->tick();
        $this->assertSame('uncertain', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->active_slot);
        $this->assertSame('generating', $this->order->fresh()->status);
        $this->assertDatabaseCount('order_attachments', 1);
    }

    public function test_changed_identity_is_not_overwritten_and_can_be_stopped(): void
    {
        $run = $this->start();
        $this->order->update(['child_name' => 'Changed']);
        $this->sceneOutput($run, 1);
        $service = app(ManualSceneService::class);
        $service->tick();
        $this->assertSame('uncertain', $run->fresh()->status);
        $service->cancel($run, $this->actor);
        $this->sceneOutput($run, 1, 'stopped');
        $service->collect($run);
        $this->assertSame('stopped', $run->fresh()->status);
        $this->assertNull($run->fresh()->active_slot);
        $this->assertSame('Changed', $this->order->fresh()->child_name);
        $this->assertDatabaseCount('order_attachments', 0);
    }

    public function test_provider_completion_with_only_one_scene_does_not_finish_order(): void
    {
        $run = $this->start();
        $this->sceneOutput($run, 1, 'completed');
        app(ManualSceneService::class)->tick();
        $this->assertSame('uncertain', $run->fresh()->status);
        $this->assertSame('generating', $this->order->fresh()->status);
    }

    public function test_corrupt_output_is_never_attached(): void
    {
        $run = $this->start();
        $this->sceneOutput($run, 1);
        file_put_contents($this->spool.'/outbox/'.$run->id.'/scene-01.png', 'corrupt');
        app(ManualSceneService::class)->tick();
        $this->assertSame('uncertain', $run->fresh()->status);
        $this->assertDatabaseCount('order_attachments', 0);
    }

    public function test_private_image_access_is_scoped_to_its_order(): void
    {
        $run = $this->start();
        $this->sceneOutput($run, 1);
        app(ManualSceneService::class)->collect($run);
        $this->actingAs($this->actor)->get(route('admin.orders.codex-scenes.image', [$this->order, $run, 1]))->assertOk();
        $other = Order::create(['order_number' => 'SYNTHETIC-2', 'parent_name' => 'Synthetic']);
        $this->get(route('admin.orders.codex-scenes.image', [$other, $run, 1]))->assertNotFound();
    }

    public function test_authentication_failure_disables_start_and_private_images_require_permission(): void
    {
        file_put_contents($this->spool.'/outbox/health.json', '{"authenticated":false}');
        $this->assertNotNull(app(ManualSceneService::class)->reason($this->order));
        $this->get('/admin/orders/'.$this->order->id.'/codex-scenes/'.Str::uuid().'/images/1')->assertRedirect();
        $this->actingAs(User::factory()->create())->post(route('admin.orders.codex-scenes.start', $this->order), ['confirm' => 1])->assertForbidden();
    }
}
