<?php

namespace Tests\Feature;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Services\CodexScenes\LocalCleanupPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CodexLocalCleanupTest extends TestCase
{
    use RefreshDatabase;

    private string $spool;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('s3_private');
        $this->spool = sys_get_temp_dir().'/cleanup-'.Str::uuid();
        File::ensureDirectoryExists($this->spool.'/outbox');
        config(['codex_scenes.spool' => $this->spool]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->spool);
        parent::tearDown();
    }

    private function fixture(bool $book = true): CodexSceneRun
    {
        $order = Order::create(['order_number' => 'SYN-'.Str::random(8), 'checkout_group_key' => (string) Str::uuid(), 'parent_name' => 'Synthetic', 'child_name' => 'Synthetic', 'status' => 'delivered']);
        $item = $book ? $order->items()->create(['item_type' => 'product', 'title' => 'Synthetic book', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]) : null;
        $id = (string) Str::uuid();
        $prefix = 'orders/'.$order->id.'/codex-scenes/'.$id.'/';
        $raw = 'synthetic-raw-image-'.$id;
        $final = $book ? 'synthetic-A4-image-'.$id : $raw;
        Storage::disk('s3_private')->put($prefix.'scene-01.png', $raw);
        $path = $prefix.($book ? 'page-01-a4.png' : 'scene-01.png');
        Storage::disk('s3_private')->put($path, $final);
        $attachment = $book ? null : $order->attachments()->create(['production_unit_key' => 'story:'.$order->id, 'disk' => 's3_private', 'path' => $path, 'mime_type' => 'image/png', 'size' => strlen($final), 'original_name' => 'scene.png']);
        $run = CodexSceneRun::create(['id' => $id, 'order_id' => $order->id, 'checkout_key' => $order->checkoutGroupKey(), 'order_item_id' => $item?->id,
            'production_kind' => $book ? 'coloring_book' : 'story', 'protocol' => 6, 'scene_count' => 1, 'status' => 'completed', 'previous_status' => 'waiting_customer', 'active_slot' => null, 'fingerprint' => hash('sha256', 'synthetic'),
            'snapshot' => ['unit_key' => 'product:'.$item?->id.':interior'], 'scenes' => ['1' => ['source_run_id' => $id, 'path' => $path, 'sha256' => hash('sha256', $final), 'source_sha256' => hash('sha256', $raw), 'attachment_id' => $attachment?->id]], 'finished_at' => now()->subDays(3)]);
        if ($book) {
            $pdfPath = $prefix.'book-'.str_repeat('a', 64).'.pdf';
            $pdfBytes = '%PDF-synthetic';
            Storage::disk('s3_private')->put($pdfPath, $pdfBytes);
            $attachment = $order->attachments()->create(['production_unit_key' => $run->snapshot['unit_key'], 'disk' => 's3_private', 'path' => $pdfPath, 'mime_type' => 'application/pdf', 'size' => strlen($pdfBytes), 'original_name' => 'book.pdf']);
            $run->book_pdf = ['status' => 'ready', 'complete' => true, 'missing_pages' => [], 'path' => $pdfPath, 'sha256' => hash('sha256', $pdfBytes), 'attachment_id' => $attachment->id];
        }
        $run->updated_at = now()->subDays(3);
        $run->saveQuietly(['timestamps' => false]);

        return $run->fresh();
    }

    public function test_complete_book_attests_raw_and_a4_pages_and_pdf_without_mutation(): void
    {
        $run = $this->fixture();
        $before = $run->getRawOriginal();
        $files = Storage::disk('s3_private')->allFiles();
        $this->artisan('codex-scenes:local-cleanup-plan')->assertSuccessful();
        $plan = app(LocalCleanupPlan::class)->build();
        $this->assertCount(1, $plan['runs']);
        $this->assertSame($run->scenes['1']['source_sha256'], $plan['runs'][0]['scenes'][1]);
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('s3_private')->allFiles());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_changed_run_is_rejected_at_the_final_dispatch_fence(): void
    {
        $run = $this->fixture();
        $plans = app(LocalCleanupPlan::class);
        $entry = $plans->attest($run);
        $this->assertTrue($plans->unchanged($entry));
        $run->updateQuietly(['status' => 'running', 'active_slot' => 1]);
        $this->assertFalse($plans->unchanged($entry));
    }

    public function test_story_requires_its_actual_attachment(): void
    {
        $run = $this->fixture(false);
        $this->assertSame($run->id, app(LocalCleanupPlan::class)->attest($run)['id']);
        $run->order->attachments()->update(['production_unit_key' => 'story:99999']);
        $this->expectExceptionMessage('cleanup_attachment_mismatch');
        app(LocalCleanupPlan::class)->attest($run);
    }

    public function test_missing_or_changed_raw_and_final_images_or_pdf_fail_closed(): void
    {
        $run = $this->fixture();
        $prefix = 'orders/'.$run->order_id.'/codex-scenes/'.$run->id.'/';
        foreach ([$prefix.'scene-01.png', $run->scenes['1']['path'], $run->book_pdf['path']] as $path) {
            $bytes = Storage::disk('s3_private')->get($path);
            Storage::disk('s3_private')->put($path, 'changed');
            $this->assertSame([], app(LocalCleanupPlan::class)->build()['runs']);
            Storage::disk('s3_private')->delete($path);
            $this->assertSame([], app(LocalCleanupPlan::class)->build()['runs']);
            Storage::disk('s3_private')->put($path, $bytes);
        }
        $this->assertCount(1, app(LocalCleanupPlan::class)->build()['runs']);
    }

    public function test_active_recent_uncertain_paused_and_incomplete_runs_are_preserved(): void
    {
        $run = $this->fixture();
        foreach (['running', 'paused', 'uncertain', 'quality_review', 'needs_manual', 'stopped'] as $status) {
            $run->updateQuietly(['status' => $status]);
            $this->assertSame([], app(LocalCleanupPlan::class)->build()['runs']);
        }
        $run->updateQuietly(['status' => 'completed', 'active_slot' => 1]);
        $this->assertSame([], app(LocalCleanupPlan::class)->build()['runs']);
        $run->updateQuietly(['active_slot' => null, 'finished_at' => now()->subHours(47)]);
        $this->assertSame([], app(LocalCleanupPlan::class)->build()['runs']);
        $run->updateQuietly(['finished_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
        $run->saveQuietly(['timestamps' => false]);
        $run = $run->fresh();
        $run->book_pdf = array_merge($run->book_pdf, ['complete' => false]);
        $run->saveQuietly(['timestamps' => false]);
        $this->assertSame([], app(LocalCleanupPlan::class)->build()['runs']);
    }

    public function test_completed_receipts_do_not_starve_future_runs_and_running_work_fences_cache(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $running = $this->fixture();
        $running->updateQuietly(['status' => 'running', 'active_slot' => 1]);
        File::ensureDirectoryExists($this->spool.'/outbox/'.$first->id);
        file_put_contents($this->spool.'/outbox/'.$first->id.'/local-cleanup.json', json_encode(['id' => $first->id, 'local_images_removed' => true, 'shared_cache_done' => false]));
        $plan = app(LocalCleanupPlan::class)->build(1);
        $this->assertFalse($plan['allow_generated_cache']);
        $this->assertSame($second->id, $plan['runs'][0]['id']);
    }

    public function test_retained_scenes_and_foreign_pdf_are_not_cleaned(): void
    {
        $run = $this->fixture();
        $scenes = $run->scenes;
        $scenes[1]['source_run_id'] = (string) Str::uuid();
        $run->scenes = $scenes;
        try {
            app(LocalCleanupPlan::class)->attest($run);
            $this->fail('retained source accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('cleanup_source_mismatch', $e->getMessage());
        }
        $run = $run->fresh();
        $run->order->attachments()->update(['order_id' => $this->fixture()->order_id]);
        $this->expectExceptionMessage('cleanup_pdf_not_ready');
        app(LocalCleanupPlan::class)->attest($run);
    }
}
