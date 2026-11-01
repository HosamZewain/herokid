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
        file_put_contents($dir.'/scene-'.sprintf('%02d', $number).'.json', json_encode(['scene' => $number, 'sha256' => hash('sha256', $bytes)]));
        file_put_contents($dir.'/status.json', json_encode(['id' => $run->id, 'status' => $status]));
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
        $this->assertSame('preparing', $run->status);
        $this->assertTrue(app(AutomationAssignment::class)->owns($this->order));
        $this->assertTrue(AutomationHandoff::held($this->order->checkoutGroupKey()));
        Http::assertNothingSent();
    }

    public function test_manual_request_accepts_one_cent_reserves_checkout_and_does_not_call_ai(): void
    {
        $run = $this->start();
        $this->assertSame('preparing', $run->status);
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
