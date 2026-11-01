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
        return app(ManualSceneService::class)->start($this->order->fresh(), $this->actor);
    }

    private function sceneOutput(CodexSceneRun $run, int $number, string $status = 'running', ?int $color = null): void
    {
        $dir = $this->spool.'/outbox/'.$run->id;
        File::ensureDirectoryExists($dir);
        $bytes = $this->png($color ?? $number);
        file_put_contents($dir.'/scene-'.sprintf('%02d', $number).'.png', $bytes);
        file_put_contents($dir.'/scene-'.sprintf('%02d', $number).'.json', json_encode(['id' => $run->id, 'order_id' => $run->order_id, 'identity_checksum' => $run->snapshot['identity_checksum'], 'scene' => $number, 'sha256' => hash('sha256', $bytes)]));
        file_put_contents($dir.'/status.json', json_encode(['id' => $run->id, 'status' => $status]));
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
