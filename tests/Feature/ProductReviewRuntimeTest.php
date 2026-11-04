<?php

namespace Tests\Feature;

use App\DTOs\Ai\GptImageRequest;
use App\DTOs\Ai\GptImageResult;
use App\Jobs\AdvanceAutoPilotMessage;
use App\Models\AdminActivityLog;
use App\Models\AiProductionExecution;
use App\Models\AiProvider;
use App\Models\CodexSceneRun;
use App\Models\LegacyBookCoverApproval;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRevision;
use App\Models\OrderItem;
use App\Models\OrderPreview;
use App\Models\OrderPreviewStaffApproval;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\OrderProcessStep;
use App\Models\OrderProductPreviewGallery;
use App\Models\OrderReviewManifest;
use App\Models\OrderStatusDefinition;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskConversationSetting;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\Agentic\CapabilityRegistry;
use App\Services\Agentic\ContextObserver;
use App\Services\Agentic\DecisionOrchestrator;
use App\Services\Agentic\MessageDispatchContext;
use App\Services\Agentic\OrderContextBuilder;
use App\Services\Agentic\ProductionExecutor;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\GptImageClient;
use App\Services\CodexScenes\BookPageService;
use App\Services\CodexScenes\BookPdfService;
use App\Services\CodexScenes\BookReferences;
use App\Services\CodexScenes\ManualSceneService;
use App\Services\OrderAutomation\Process\PreviewCohort;
use App\Services\OrderAutomation\Process\ProcessDefinition;
use App\Services\OrderAutomation\Process\ProcessEnrollment;
use App\Services\OrderAutomation\Process\ProcessIdentityService;
use App\Services\OrderAutomation\Process\ProcessOrderContext;
use App\Services\OrderAutomation\Process\ProcessPaymentReview;
use App\Services\OrderAutomation\Process\ProcessPreviewStatus;
use App\Services\OrderAutomation\Process\ProcessProductReview;
use App\Services\OrderAutomation\Process\ProcessResponsePoller;
use App\Services\OrderAutomation\Process\ProcessRuntime;
use App\Services\OrderAutomation\Process\ProcessTemplates;
use App\Services\Orders\AutomationControl;
use App\Services\Orders\AutomationHandoff;
use App\Services\Orders\BookPromptSync;
use App\Services\Orders\LegacyBookAdmission;
use App\Services\Orders\ManualBookCoverApproval;
use App\Services\Orders\OrderAutomationGuidance;
use App\Services\Orders\OrderAutomationSummary;
use App\Services\Orders\OrderProductPreviewImageService;
use App\Services\Orders\OrderProductPreviewService;
use App\Services\Orders\ProductProductionPromptSyncService;
use App\Services\Orders\StaffPreviewInputs;
use App\Services\ProductAutomation\BookCatalog;
use App\Services\ProductAutomation\BookVariantSnapshot;
use App\Services\ProductAutomation\PuzzleAutomation;
use App\Services\ProductAutomation\PuzzleInputs;
use App\Services\RoboDesk\OrderConfirmationSendException;
use App\Services\RoboDesk\OrderConfirmationTransport;
use App\Support\ProductProductionPrompt;
use App\Support\ProductProductionStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\Support\AutoPilotExecutionFixtures;
use Tests\TestCase;

class ProductReviewRuntimeTest extends TestCase
{
    use AutoPilotExecutionFixtures;
    use RefreshDatabase;

    public function test_autopilot_book_pages_admission_preserves_approved_cover_and_uses_existing_pool(): void
    {
        [$item, $run, $manifest] = $this->approvedBook();
        $item->order->update(['paid_amount_cents' => 1, 'payment_status' => 'partially_paid']);
        $this->enableSyntheticExecution(['generate_book_pages']);
        $cover = PuzzleGeneration::sole()->getRawOriginal();
        $state = app(ContextObserver::class)->observe($item->order->checkoutGroupKey());
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $this->assertSame('waiting_slot', CodexSceneRun::sole()->status);
        $this->assertSame($item->id, CodexSceneRun::sole()->order_item_id);
        $this->assertSame($cover, PuzzleGeneration::sole()->getRawOriginal());
        $this->assertSame($manifest->id, CodexSceneRun::sole()->snapshot['manifest_id']);
    }

    public function test_autopilot_book_cover_admission_preserves_variant_unit_and_does_not_start_pages(): void
    {
        $item = $this->book();
        $item->order->update(['status' => 'new_confirmed']);
        config(['puzzle_automation.after_event_id' => 999999, 'order_process.enabled' => false]);
        $this->enableSyntheticExecution(['generate_book_cover']);
        $state = app(ContextObserver::class)->observe($item->order->checkoutGroupKey());
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted'], json_encode([
            'targets' => app(CapabilityRegistry::class)->targets($state->snapshot),
            'receipts' => AiProductionExecution::pluck('reason_code')->all(),
        ]));
        $work = PuzzleGeneration::sole();
        $this->assertSame($item->id, $work->order_item_id);
        $this->assertSame('book_cover', $work->context['production_stage']);
        $this->assertSame('queued', $work->status);
        $this->assertDatabaseCount('codex_scene_runs', 0);
        Http::assertNothingSent();
    }

    public function test_autopilot_preview_dispatch_uses_exact_existing_message_node_and_preserves_receipts(): void
    {
        [$item, $run] = $this->generatedPreview();
        app(ProcessRuntime::class)->advance($run->id); // Collect all outputs; stop at message node.
        $this->enableSyntheticExecution(['send_product_preview']);
        Queue::fake();
        $state = app(ContextObserver::class)->observe($item->order->checkoutGroupKey());
        $this->assertSame('send_product_preview', $state->snapshot['message_dispatch']['action']);
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        Http::assertNothingSent();
        $job = Queue::pushed(AdvanceAutoPilotMessage::class)->sole();
        $job->handle(app(ProcessRuntime::class));
        $attempts = DB::table('order_process_message_attempts')->count();
        $this->assertSame(1, $attempts);
        Http::assertSentCount(2);
        $job->handle(app(ProcessRuntime::class));
        Http::assertSentCount(2);
        $this->assertSame($attempts, DB::table('order_process_message_attempts')->count());
        $this->assertDatabaseCount('puzzle_generations', 1);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'autopilot.progress', 'subject_id' => $item->order_id]);
    }

    public function test_autopilot_preview_job_rejects_revised_node_and_does_not_advance_other_work(): void
    {
        [$item, $run] = $this->generatedPreview();
        app(ProcessRuntime::class)->advance($run->id);
        $this->enableSyntheticExecution(['send_product_preview']);
        $context = app(MessageDispatchContext::class)->forCheckout($run->checkout_key);
        $this->assertNotNull($context);
        $before = DB::table('order_process_steps')->get()->toJson();
        $run->refresh()->increment('version');
        (new AdvanceAutoPilotMessage($run->id, $context))->handle(app(ProcessRuntime::class));
        Http::assertNothingSent();
        $this->assertSame($before, DB::table('order_process_steps')->get()->toJson());
        $this->assertDatabaseCount('order_process_message_attempts', 0);
    }

    public function test_autopilot_book_guard_blocks_inside_original_admission_without_mutating_outputs(): void
    {
        [$item, $run, $manifest] = $this->approvedBook();
        $called = false;
        try {
            app(BookPageService::class)->start($item, $manifest, false, function () use (&$called) {
                $called = true;
                throw new \RuntimeException('EXECUTION_STALE_CONTEXT');
            });
            $this->fail('Stale admission must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('EXECUTION_STALE_CONTEXT', $e->getMessage());
        }
        $this->assertTrue($called);
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    private string $png;

    private string $spool;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->utc()->startOfDay());
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Storage::fake('s3_private');
        Storage::fake('local');
        Storage::fake('public');
        config(['media.private_disk' => 's3_private', 'order_process.enabled' => true, 'order_process.after_order_id' => 0,
            'order_process.identity_enabled' => true, 'order_process.identity_budget_usd' => 1, 'order_process.identity_template_ready' => true,
            'puzzle_automation.enabled' => true, 'puzzle_automation.after_event_id' => 0,
            'product_review.enabled' => true, 'product_review.template_ready' => true, 'product_review.book_pages_enabled' => true,
            'codex_scenes.enabled' => true, 'codex_scenes.automatic' => false, 'robodesk.enabled' => false, 'robodesk.confirmation.enabled' => false]);
        $this->spool = sys_get_temp_dir().'/book-test-'.Str::uuid();
        mkdir($this->spool.'/outbox', 0770, true);
        file_put_contents($this->spool.'/outbox/health.json', json_encode(['authenticated' => true, 'supported_protocols' => [4, 5, 6]]));
        config(['codex_scenes.spool' => $this->spool]);
        $this->png = $this->png(1);
        Storage::disk('s3_private')->put('child-photos/synthetic.png', $this->png);
        $provider = AiProvider::firstOrCreate(['driver' => 'openai'], ['name' => 'OpenAI', 'display_name' => 'OpenAI']);
        $provider->update(['is_active' => true]);
        $model = $provider->models()->firstOrCreate(['code' => 'gpt-image-2'], ['display_name' => 'Synthetic']);
        $model->update(['is_active' => true, 'generation_capabilities_json' => ['image_editing', 'character_sheet'], 'estimated_cost_amount' => '.1', 'estimated_cost_currency' => 'USD']);
        app(AiProviderCredentialService::class)->save($provider, 'synthetic-key');
        OrderStatusDefinition::firstOrCreate(['type' => 'order', 'key' => 'costomer_confirmed'], ['label_ar' => 'هوية مؤكدة', 'color' => 'teal', 'behavior' => 'standard', 'is_active' => true]);
        $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actor->permissions()->sync(Permission::whereIn('key', ['order_automation.view', 'order_automation.manage', 'order_automation.activate', 'order_automation.execute', 'orders.view', 'orders.update', 'orders.photos.view'])->pluck('id'));
        $d = app(ProcessDefinition::class)->validate(ProcessDefinition::defaults('product_review'));
        $flow = OrderAutomation::create(['name' => 'Synthetic all previews', 'draft' => $d]);
        $revision = OrderAutomationRevision::create(['automation_id' => $flow->id, 'definition' => $d, 'checksum' => hash('sha256', json_encode($d)), 'published_by' => $this->actor->id, 'created_at' => now()]);
        $flow->update(['revision_id' => $revision->id]);
        app(ProcessRuntime::class)->activate($flow, $this->actor, true);
        RoboDeskConversationSetting::create(['id' => 1, 'enabled' => true, 'email' => 'synthetic@example.test', 'password' => 'synthetic']);
        Http::fake(['https://hero-kid.robodesk.ai/*' => fn ($request) => str_contains($request->url(), 'messagesByPhone')
            ? Http::response(['phone' => '201000000001', 'interactions' => [], 'messages' => []])
            : Http::response(['_id' => 'review-interaction', 'channelId' => 'wamid.review'])]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->spool);
        parent::tearDown();
    }

    private function png(int $color): string
    {
        $im = imagecreatetruecolor(1024, 1536);
        imagefill($im, 0, 0, imagecolorallocate($im, $color, 0, 0));
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function book(?Order $order = null): OrderItem
    {
        $order ??= Order::create(['order_number' => 'SYN-'.Str::random(8), 'checkout_group_key' => (string) Str::uuid(), 'parent_name' => 'Synthetic', 'child_name' => 'Synthetic child', 'child_age' => 7, 'child_gender' => 'girl', 'status' => 'new', 'uploaded_photos' => ['child-photos/synthetic.png'], 'delivery_details' => ['phone' => '01000000001']]);
        $product = Product::create(['slug' => 'book-'.Str::random(8), 'name_ar' => 'كتاب تجريبي', 'price_cents' => 10000, 'is_active' => true]);
        $item = $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => 'كتاب اختبار', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
        foreach ([['cover', 'book_cover', 1, 'Cover for {{child_full_name}}'], ['pages', 'book_interior', 3, '{{book_page_count}} coloring pages for {{child_full_name}}']] as [$key,$stage,$count,$prompt]) {
            $item->productionComponents()->create(['stable_key' => $key, 'name' => $key, 'production_stage' => $stage, 'quantity_per_item' => $count, 'prompt_template' => $prompt]);
        }

        return $item;
    }

    private function mixedPreflightFixture(): array
    {
        $book = $this->book();
        $story = Story::create(['title' => 'Synthetic recovery story', 'slug' => 'recovery-story', 'language' => 'ar', 'gender' => 'both', 'price' => 100, 'active' => true]);
        $storyOrder = $book->order->replicate();
        $storyOrder->order_number = 'SYN-RECOVERY-STORY';
        $storyOrder->story_id = $story->id;
        $storyOrder->status = 'new_confirmed';
        $storyOrder->save();
        $storyOrder->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => 'Synthetic story', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
        $items = collect([$book]);
        foreach (['personalized-child-adventure-hdf-puzzle', 'bostr-mkhss-bsor-altfl-mkas-a4', 'personalized-child-family-photo-mug'] as $slug) {
            $order = $book->order->replicate();
            $order->order_number = 'SYN-'.Str::random(8);
            $order->status = 'new_confirmed';
            $order->save();
            $product = Product::create(['slug' => $slug, 'name_ar' => $slug, 'is_active' => true, 'price_cents' => 10000, 'production_prompt_template' => 'One image for {{child_full_name}}']);
            $items->push($order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => $slug, 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]));
        }
        $slugs = config('puzzle_automation.product_slugs');
        config(['puzzle_automation.product_slugs' => array_values(array_diff($slugs, ['bostr-mkhss-bsor-altfl-mkas-a4']))]);
        DB::transaction(fn () => $book->order->update(['status' => 'new_confirmed']));
        app(ProcessRuntime::class)->tick();
        $run = OrderProcessRun::where('checkout_key', $book->order->checkoutGroupKey())->sole();
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertDatabaseCount('order_process_steps', 0);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->times(3)->andReturnUsing(fn (GptImageRequest $request) => $this->mixedImage($request));
        foreach ($items->reject(fn ($item) => $item->product->slug === 'bostr-mkhss-bsor-altfl-mkas-a4') as $item) {
            $context = app(PuzzleInputs::class)->captureCurrent($item);
            $generation = PuzzleGeneration::create(['order_id' => $item->order_id, 'order_item_id' => $item->id, 'checkout_key' => $run->checkout_key,
                'event_id' => $run->event_id, 'unit_key' => $context['unit_key'], 'context' => $context, 'status' => 'queued']);
            app(PuzzleAutomation::class)->generate($generation->id);
            $this->assertSame('succeeded', $generation->fresh()->status, $generation->fresh()->error_code ?? '');
        }
        config(['puzzle_automation.product_slugs' => $slugs]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return [$book, $storyOrder, $items, $run->fresh(), $admin];
    }

    private function mixedImage(GptImageRequest $request): GptImageResult
    {
        $bytes = $this->png;
        if ($request->size === '1536x1024') {
            $image = imagecreatetruecolor(1536, 1024);
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
        }

        return new GptImageResult($bytes, 'png', 'image/png', 'synthetic-recovery', [], []);
    }

    public function test_mixed_recovery_preserves_three_outputs_and_finishes_only_missing_poster_and_identity(): void
    {
        [$book, $story, $items, $previous, $admin] = $this->mixedPreflightFixture();
        $before = PuzzleGeneration::orderBy('id')->get()->map(fn ($run) => $run->getRawOriginal())->all();
        $summary = (new OrderAutomationSummary)->forKeys([$previous->checkout_key])[$previous->checkout_key];
        $this->assertTrue($summary['recheck']['mixed']);
        $this->get(route('admin.orders.groups.show', $book->order))->assertOk()->assertSee('فحص واستكمال الناقص');
        $url = route('admin.orders.workspace.control', $book->order);
        $payload = $this->recheckPayload($previous);
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $previous->fresh()->status);
        $run = OrderProcessRun::latest('id')->first();
        $this->assertSame('queued', $run->status);
        $this->assertSame($previous->id, $run->context['supersedes_run_id']);
        $this->assertSame($before, PuzzleGeneration::orderBy('id')->get()->map(fn ($generation) => $generation->getRawOriginal())->all());
        $this->post($url, $payload)->assertStatus(409);
        $this->assertDatabaseCount('order_process_runs', 2);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->twice()->andReturnUsing(fn (GptImageRequest $request) => $this->mixedImage($request));
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertDatabaseCount('puzzle_generations', 4);
        $this->assertDatabaseCount('order_process_identities', 1);
        $poster = PuzzleGeneration::where('status', 'queued')->sole();
        app(PuzzleAutomation::class)->generate($poster->id);
        app(ProcessIdentityService::class)->generate(OrderProcessIdentity::sole()->id);
        $this->send($run);
        $this->assertCount(5, OrderReviewManifest::sole()->images);
        $this->assertSame($before, PuzzleGeneration::whereIn('id', array_column($before, 'id'))->orderBy('id')->get()->map(fn ($generation) => $generation->getRawOriginal())->all());
        $this->assertSame('unpaid', $book->order->fresh()->payment_status);
        $this->assertNull(OrderReviewManifest::sole()->approved_at);
        $this->assertDatabaseCount('codex_scene_runs', 0);
        $this->assertSame($admin->id, AdminActivityLog::where('action', 'order.preview_preflight_rechecked')->sole()->user_id);
        Http::assertSentCount(2);
    }

    public function test_mixed_recovery_rejects_changed_or_missing_completed_artwork_atomically(): void
    {
        [$book, $story, $items, $run] = $this->mixedPreflightFixture();
        $saved = PuzzleGeneration::where('order_item_id', $book->id)->sole();
        $old = $saved->context;
        $context = $old;
        $context['fingerprint'] = str_repeat('0', 64);
        $saved->update(['context' => $context]);
        $url = route('admin.orders.workspace.control', $book->order);
        $payload = $this->recheckPayload($run);
        $this->post($url, $payload)->assertSessionHasErrors('automation');
        $saved->update(['context' => $old]);
        $bytes = Storage::disk($saved->disk)->get($saved->output_path);
        Storage::disk($saved->disk)->put($saved->output_path, 'damaged synthetic artwork');
        $this->post($url, $payload)->assertSessionHasErrors('automation');
        Storage::disk($saved->disk)->put($saved->output_path, $bytes);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertDatabaseCount('order_process_runs', 1);
        $this->assertDatabaseCount('order_process_identities', 0);
        $this->assertSame('running', DB::table('order_automation_controls')->value('state'));
        $this->assertSame(1, (int) DB::table('order_automation_controls')->value('version'));
        Http::assertNothingSent();
    }

    public function test_mixed_recovery_keeps_safe_pause_assignment_and_reuses_existing_queued_poster(): void
    {
        [$book, $story, $items, $run, $admin] = $this->mixedPreflightFixture();
        $posterItem = $items->first(fn ($item) => $item->product->slug === 'bostr-mkhss-bsor-altfl-mkas-a4');
        $context = app(PuzzleInputs::class)->captureCurrent($posterItem);
        $poster = PuzzleGeneration::create(['order_id' => $posterItem->order_id, 'order_item_id' => $posterItem->id, 'checkout_key' => $run->checkout_key,
            'event_id' => $run->event_id, 'unit_key' => $context['unit_key'], 'context' => $context, 'status' => 'queued']);
        $url = route('admin.orders.workspace.control', $book->order);
        $this->post($url, ['action' => 'pause', 'version' => 1, 'reason' => 'Synthetic safe pause'])->assertSessionHasNoErrors();
        $this->post($url, $this->recheckPayload($run->fresh()))->assertSessionHasNoErrors();
        $new = OrderProcessRun::latest('id')->first();
        app(ProcessRuntime::class)->advance($new->id);
        $this->assertSame('waiting_identity', $new->fresh()->status);
        $this->assertDatabaseCount('puzzle_generations', 4);
        $this->assertSame($poster->id, PuzzleGeneration::where('order_item_id', $posterItem->id)->sole()->id);
        $this->assertSame('running', DB::table('order_automation_controls')->value('state'));
        $this->assertDatabaseCount('order_automation_holds', 0);
        Http::assertNothingSent();
    }

    private function blockedPhotoPreview(): array
    {
        $item = $this->book();
        Storage::disk('s3_private')->put('child-photos/synthetic.heic', 'synthetic unsupported image');
        $item->order->updateQuietly(['uploaded_photos' => ['child-photos/synthetic.heic']]);
        DB::transaction(fn () => $item->order->update(['status' => 'new_confirmed']));
        app(ProcessRuntime::class)->tick();
        $run = OrderProcessRun::where('checkout_key', $item->order->checkoutGroupKey())->sole();
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('blocked', $run->fresh()->status);

        return [$item, $run->fresh()];
    }

    private function recheckPayload(OrderProcessRun $run): array
    {
        return ['action' => 'recheck', 'version' => (int) (DB::table('order_automation_controls')->where('checkout_key', $run->checkout_key)->value('version') ?? 1),
            'run_id' => $run->id, 'run_version' => $run->version];
    }

    public function test_unusable_photo_preflight_is_actionable_and_failed_recheck_has_no_effects(): void
    {
        [$item, $run] = $this->blockedPhotoPreview();
        $this->assertSame('puzzle_no_usable_photos', $run->error_code);
        $this->assertSame($item->id, $run->context['blocker']['item_id']);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $this->assertDatabaseCount('order_process_steps', 0);
        $summary = (new OrderAutomationSummary)->forKeys([$run->checkout_key])[$run->checkout_key];
        $this->assertStringContainsString('JPG', $summary['reason']);
        $this->assertStringContainsString((string) $item->id, $summary['label']);
        $this->assertSame($run->id, $summary['recheck']['run_id']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.orders.groups.show', $item->order))->assertOk()
            ->assertSee('فحص واستكمال تجهيز المعاينة')->assertDontSee('preview_requires_review');
        $this->post(route('admin.orders.workspace.control', $item->order), $this->recheckPayload($run))->assertSessionHasErrors('automation');
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertDatabaseCount('order_automation_holds', 0);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $this->assertDatabaseCount('order_process_runs', 1);
        Http::assertNothingSent();
    }

    public function test_preflight_recheck_uses_valid_child_photo_and_queues_once_without_reconfirming(): void
    {
        [$item, $run] = $this->blockedPhotoPreview();
        // This is the old generic blocker seen by existing orders such as HK11-104.
        $run->update(['error_code' => 'preview_requires_review']);
        $item->order->updateQuietly(['uploaded_photos' => ['child-photos/synthetic.heic', 'child-photos/synthetic.png']]);
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = $this->recheckPayload($run);
        $url = route('admin.orders.workspace.control', $item->order);
        $this->actingAs($admin)->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $run->fresh()->status);
        $new = OrderProcessRun::latest('id')->first();
        $this->assertSame('queued', $new->status);
        $this->assertSame($run->id, $new->context['supersedes_run_id']);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $this->assertDatabaseCount('order_automation_holds', 0);
        $this->post($url, $payload)->assertStatus(409);
        $this->assertDatabaseCount('order_process_runs', 2);
        $this->assertSame($admin->id, AdminActivityLog::where('action', 'order.preview_preflight_rechecked')->sole()->user_id);
        app(ProcessRuntime::class)->advance($new->id);
        $generation = PuzzleGeneration::sole();
        $this->assertSame('queued', $generation->status);
        $this->assertSame('child-photos/synthetic.png', $generation->context['photos'][0]['path']);
        $this->assertSame([1], array_column($generation->context['excluded_photos'], 'number'));
        $this->assertStringContainsString('1 صورة صالحة', $generation->photoWarning());
        $this->assertSame(['child-photos/synthetic.heic', 'child-photos/synthetic.png'], $item->order->fresh()->uploaded_photos);
        Http::assertNothingSent();
    }

    public function test_preflight_recheck_explains_disabled_budget_and_saved_attachment_without_claiming_success(): void
    {
        [$item, $run] = $this->blockedPhotoPreview();
        $item->order->updateQuietly(['uploaded_photos' => ['child-photos/synthetic.png']]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $url = route('admin.orders.workspace.control', $item->order);
        $payload = $this->recheckPayload($run);
        config(['product_review.enabled' => false]);
        $this->post($url, $payload)->assertSessionHasErrors(['automation' => OrderAutomationGuidance::reason('preview_disabled')]);
        config(['product_review.enabled' => true]);
        $definition = $run->revision->definition;
        $low = $definition;
        $low['nodes'][0]['config']['budget_usd'] = 0;
        $run->revision->update(['definition' => $low]);
        $this->post($url, $payload)->assertSessionHasErrors(['automation' => OrderAutomationGuidance::reason('preview_budget_exceeded')]);
        $run->revision->update(['definition' => $definition]);
        OrderAttachment::create(['order_id' => $item->order_id, 'path' => 'retained.png', 'disk' => 's3_private', 'original_name' => 'retained.png', 'mime_type' => 'image/png', 'size' => 1]);
        $this->post($url, $payload)->assertSessionHasErrors('automation');
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $this->assertDatabaseCount('order_process_runs', 1);
        $this->assertDatabaseCount('order_automation_holds', 0);
        Http::assertNothingSent();
    }

    public function test_explicit_preflight_recheck_after_safe_pause_keeps_assignment_and_starts_once(): void
    {
        [$item, $run] = $this->blockedPhotoPreview();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $url = route('admin.orders.workspace.control', $item->order);
        $this->post($url, ['action' => 'pause', 'version' => 1, 'reason' => 'Correct photos'])->assertSessionHasNoErrors();
        $this->assertSame('paused', $run->fresh()->status);
        $item->order->updateQuietly(['uploaded_photos' => ['child-photos/synthetic.png']]);
        $this->post($url, $this->recheckPayload($run->fresh()))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame('running', DB::table('order_automation_controls')->value('state'));
        $this->assertDatabaseCount('order_group_assignments', 0);
        $this->assertDatabaseCount('order_process_runs', 2);
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
    }

    public function test_valid_photo_fallback_reaches_cover_provider_and_interior_references_without_heic(): void
    {
        $item = $this->book();
        Storage::disk('s3_private')->put('child-photos/synthetic.heic', 'synthetic unsupported image');
        $item->order->updateQuietly(['uploaded_photos' => ['child-photos/synthetic.heic', 'child-photos/synthetic.png']]);
        $run = $this->enroll($item->order);
        $generation = PuzzleGeneration::sole();
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->withArgs(function (GptImageRequest $request) {
            $this->assertSame('data:image/png;base64,'.base64_encode($this->png), $request->inputImages[0]);
            $this->assertStringNotContainsString(base64_encode('synthetic unsupported image'), implode('', $request->inputImages));

            return true;
        })->andReturn(new GptImageResult($this->png(2), 'png', 'image/png', 'synthetic-receipt', [], []));
        app(PuzzleAutomation::class)->generate($generation->id);
        $this->assertSame('succeeded', $generation->fresh()->status);
        $references = app(BookReferences::class)->capture($item, $generation->context['photos'], 3)['references'];
        $this->assertCount(1, $references);
        $this->assertSame('child-photos/synthetic.png', $references[0]['path']);
        Storage::disk('s3_private')->assertExists('child-photos/synthetic.heic');
        $summary = (new OrderAutomationSummary)->forKeys([$run->checkout_key])[$run->checkout_key];
        $this->assertStringContainsString('تجهيز معاينات المنتجات', $summary['label']);
        $cover = collect($summary['parts'])->firstWhere('stage', 'book_cover');
        $this->assertStringContainsString('استُبعدت الصور رقم 1', $cover['reason']);
        Http::assertNothingSent();
    }

    public function test_preflight_recheck_respects_scope_versions_and_existing_generation(): void
    {
        [$item, $run] = $this->blockedPhotoPreview();
        $item->order->updateQuietly(['uploaded_photos' => ['child-photos/synthetic.png']]);
        $url = route('admin.orders.workspace.control', $item->order);
        $payload = $this->recheckPayload($run);
        $this->actingAs($this->actor)->post($url, $payload)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post($url, array_replace($payload, ['run_version' => $run->version + 1]))->assertStatus(409);
        $another = $this->book();
        $this->post(route('admin.orders.workspace.control', $another->order), $payload)->assertNotFound();
        PuzzleGeneration::create(['order_id' => $item->order_id, 'order_item_id' => $item->id, 'checkout_key' => $run->checkout_key,
            'unit_key' => 'product:'.$item->id, 'event_id' => $run->event_id, 'status' => 'uncertain', 'context' => [], 'billing_status' => 'unknown']);
        $this->post($url, $payload)->assertStatus(409);
        $this->assertNull((new OrderAutomationSummary)->forKeys([$run->checkout_key])[$run->checkout_key]['recheck']);
        $this->assertDatabaseCount('order_process_runs', 1);
        $this->assertDatabaseCount('order_automation_holds', 0);
        Http::assertNothingSent();
    }

    public function test_photo_fallback_never_borrows_sibling_photos_and_valid_inputs_are_stable(): void
    {
        $one = $this->book();
        $two = $this->book();
        $two->order->updateQuietly(['checkout_group_key' => $one->order->checkoutGroupKey(), 'child_name' => 'Other child', 'uploaded_photos' => ['child-photos/other.png']]);
        Storage::disk('s3_private')->put('child-photos/other.png', $this->png(3));
        $one->order->updateQuietly(['status' => 'new_confirmed']);
        $two->order->updateQuietly(['status' => 'new_confirmed']);
        $capture = app(PuzzleInputs::class);
        $before = $capture->captureCurrent($one);
        $this->assertArrayNotHasKey('excluded_photos', $before);
        Storage::disk('s3_private')->put('child-photos/synthetic.heic', 'unsupported');
        $one->order->updateQuietly(['uploaded_photos' => ['child-photos/synthetic.heic', 'child-photos/synthetic.png']]);
        $first = $capture->captureCurrent($one->fresh());
        $second = $capture->captureCurrent($two->fresh());
        $this->assertSame(['child-photos/synthetic.png'], array_column($first['photos'], 'path'));
        $this->assertSame(['child-photos/other.png'], array_column($second['photos'], 'path'));
        $this->assertNotSame($first['fingerprint'], $second['fingerprint']);
        $one->order->updateQuietly(['uploaded_photos' => ['child-photos/synthetic.png']]);
        $this->assertSame($before['fingerprint'], $capture->captureCurrent($one->fresh())['fingerprint']);
        $one->order->updateQuietly(['uploaded_photos' => ['child-photos/synthetic.heic']]);
        $this->expectExceptionMessage('puzzle_no_usable_photos');
        $capture->captureCurrent($one->fresh());
    }

    private function enroll(Order $order): OrderProcessRun
    {
        DB::transaction(fn () => $order->update(['status' => 'new_confirmed']));
        app(ProcessRuntime::class)->tick();
        $run = OrderProcessRun::where('checkout_key', $order->checkoutGroupKey())->sole();
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_identity', $run->fresh()->status, $run->fresh()->error_code ?? '');

        return $run->fresh();
    }

    private function send(OrderProcessRun $run): void
    {
        foreach (range(1, 3) as $_) {
            app(ProcessRuntime::class)->advance($run->id);
        }
        $this->assertSame('waiting_response', $run->fresh()->status, $run->fresh()->error_code ?? '');
    }

    private function reply(OrderProcessRun $run): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::create(['account_key' => $run->account_key, 'phone_hash' => $run->phone_hash, 'phone' => '+201000000001']);
        $base = ['remote_id' => 'template', 'remote_hash' => hash('sha256', 'template'), 'fingerprint' => hash('sha256', 'template'), 'direction' => 'outbound', 'body' => 'معاينة', 'kind' => 'text', 'sent_at' => now(),
            'provider_metadata' => ['contract_fields_present' => true, 'channelId' => 'wamid.review', 'replyTo' => null, 'templateName' => ProcessTemplates::REVIEW, 'interactionId' => 'review-interaction']];
        $conversation->messages()->create($base);
        $this->travel(3)->seconds();
        $conversation->messages()->create(array_replace($base, ['remote_id' => 'reply', 'remote_hash' => hash('sha256', 'reply'), 'fingerprint' => hash('sha256', 'reply'), 'direction' => 'inbound', 'body' => 'تأكيد المعاينة', 'sent_at' => now(),
            'provider_metadata' => ['contract_fields_present' => true, 'channelId' => 'wamid.answer', 'replyTo' => 'wamid.review', 'templateName' => null, 'interactionId' => 'review-interaction']]));
        app(ProcessResponsePoller::class)->process($conversation);
        app(ProcessResponsePoller::class)->process($conversation);
        foreach (range(1, 3) as $_) {
            app(ProcessRuntime::class)->advance($run->id);
        }

        return $conversation;
    }

    private function approvedBook(): array
    {
        $item = $this->book();
        $run = $this->enroll($item->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->withArgs(function (GptImageRequest $r) {
            $this->assertSame('1024x1536', $r->size);

            return true;
        })
            ->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        app(PuzzleAutomation::class)->generate(PuzzleGeneration::sole()->id);
        $this->assertSame('succeeded', PuzzleGeneration::sole()->status, PuzzleGeneration::sole()->error_code ?? '');
        $this->send($run);
        $this->assertSame('preview_uploaded', $item->order->fresh()->status);
        $conversation = $this->reply($run);
        $this->assertSame('waiting_staff', $run->fresh()->status, $run->fresh()->error_code ?? '');
        $manifest = OrderReviewManifest::sole();
        $this->assertNotNull($manifest->approved_at);

        return [$item, $run, $manifest, $conversation];
    }

    public function test_revised_product_checkout_preserves_output_and_sends_one_new_manifest(): void
    {
        $this->actor->permissions()->attach(Permission::whereIn('key', ['orders.automation.control', 'orders.view_all'])->pluck('id'));
        $first = $this->book();
        $old = $this->enroll($first->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->twice()
            ->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        app(PuzzleAutomation::class)->generate(PuzzleGeneration::sole()->id);
        $output = PuzzleGeneration::sole()->getAttributes();
        $this->send($old);
        $manifest = OrderReviewManifest::sole()->getAttributes();
        $request = Request::create('/');
        app(AutomationHandoff::class)->take($first->order, $this->actor, $request);
        $added = $this->book();
        $added->order->updateQuietly(['checkout_group_key' => $first->order->checkoutGroupKey()]);
        try {
            app(AutomationHandoff::class)->resume($first->order, $this->actor, $request);
            $this->fail('An unconfirmed addition must not start');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('غير مؤكد', $e->getMessage());
        }
        Order::where('checkout_group_key', $first->order->checkoutGroupKey())->update(['status' => 'new_confirmed']);
        app(AutomationHandoff::class)->resume($first->order, $this->actor, $request);
        $new = OrderProcessRun::latest('id')->first();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame($manifest, OrderReviewManifest::find($manifest['id'])->getAttributes());
        $this->assertSame($output, PuzzleGeneration::find($output['id'])->getAttributes());
        app(ProcessRuntime::class)->advance($new->id);
        $this->assertSame('waiting_identity', $new->fresh()->status, $new->fresh()->error_code ?? '');
        $this->assertDatabaseCount('puzzle_generations', 2);
        app(PuzzleAutomation::class)->generate(PuzzleGeneration::latest('id')->first()->id);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://hero-kid.robodesk.ai/*' => fn ($request) => str_contains($request->url(), 'messagesByPhone')
            ? Http::response(['phone' => '201000000001', 'interactions' => [], 'messages' => []])
            : Http::response(['_id' => 'review-interaction', 'channelId' => 'wamid.revised'])]);
        $this->send($new);
        app(ProcessRuntime::class)->advance($new->id);
        $this->assertDatabaseCount('order_review_manifests', 2);
        $this->assertCount(2, OrderReviewManifest::latest('id')->first()->images);
        $this->assertSame($old->id, $new->fresh()->context['supersedes_run_id']);
        $this->assertSame($output, PuzzleGeneration::find($output['id'])->getAttributes());
        $this->assertSame(2, OrderProcessRun::count());
        $this->assertSame(2, OrderProcessStep::where('type', 'whatsapp')->where('status', 'completed')->count());
        $this->assertFalse(AutomationHandoff::held($first->order->checkoutGroupKey()));
        $this->travel(3)->seconds();
        $conversation = WhatsAppConversation::create(['account_key' => $new->account_key, 'phone_hash' => $new->phone_hash, 'phone' => '+201000000001']);
        foreach (['wamid.review', 'wamid.revised'] as $wamid) {
            $conversation->messages()->create(['remote_id' => $wamid, 'remote_hash' => hash('sha256', $wamid), 'fingerprint' => hash('sha256', $wamid), 'direction' => 'outbound', 'body' => 'معاينة', 'kind' => 'text', 'sent_at' => now(),
                'provider_metadata' => ['contract_fields_present' => true, 'channelId' => $wamid, 'templateName' => ProcessTemplates::REVIEW, 'interactionId' => 'review-interaction']]);
        }
        foreach (['wamid.review', 'wamid.revised'] as $index => $replyTo) {
            $this->travel(2)->seconds();
            $conversation->messages()->create(['remote_id' => 'answer-'.$index, 'remote_hash' => hash('sha256', 'answer-'.$index), 'fingerprint' => hash('sha256', 'answer-'.$index),
                'direction' => 'inbound', 'body' => 'تأكيد المعاينة', 'kind' => 'text', 'sent_at' => now(),
                'provider_metadata' => ['contract_fields_present' => true, 'channelId' => 'answer-'.$index, 'replyTo' => $replyTo, 'templateName' => null, 'interactionId' => 'review-interaction']]);
            app(ProcessResponsePoller::class)->process($conversation);
            foreach (range(1, 3) as $_) {
                app(ProcessRuntime::class)->advance($new->id);
            }
            $this->assertSame($index === 0 ? 'waiting_response' : 'waiting_staff', $new->fresh()->status, $new->fresh()->error_code ?? '');
        }
        $this->assertSame($manifest, OrderReviewManifest::find($manifest['id'])->getAttributes());
        $this->assertNotNull(OrderReviewManifest::where('run_id', $new->id)->sole()->approved_at);
        $this->assertSame($old->id, $new->fresh()->context['supersedes_run_id']);
        $this->assertSame(0, $first->order->fresh()->paid_amount_cents);
    }

    public function test_changed_completed_product_or_uncertain_send_cannot_restart_preview(): void
    {
        $this->actor->permissions()->attach(Permission::whereIn('key', ['orders.automation.control', 'orders.view_all'])->pluck('id'));
        $first = $this->book();
        $old = $this->enroll($first->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()
            ->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        app(PuzzleAutomation::class)->generate(PuzzleGeneration::sole()->id);
        $this->send($old);
        $request = Request::create('/');
        app(AutomationHandoff::class)->take($first->order, $this->actor, $request);
        $first->order->refresh()->updateQuietly(['status' => 'new_confirmed', 'child_name' => 'Changed child']);
        try {
            app(AutomationHandoff::class)->resume($first->order, $this->actor, $request);
            $this->fail('Changed completed output must be regenerated explicitly');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('puzzle_completed_inputs_changed', $e->getMessage());
        }
        $this->assertTrue(AutomationHandoff::held($first->order->checkoutGroupKey()));
        $this->assertSame('paused', $old->fresh()->status);
        $first->order->updateQuietly(['child_name' => 'Synthetic child']);
        $old->steps()->where('type', 'whatsapp')->update(['status' => 'uncertain']);
        try {
            app(AutomationHandoff::class)->resume($first->order, $this->actor, $request);
            $this->fail('Uncertain send must block a fresh preview');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('غير محسوم', $e->getMessage());
        }
        $this->assertDatabaseCount('order_process_runs', 1);
        $this->assertDatabaseCount('puzzle_generations', 1);
    }

    public function test_explicit_admission_shows_no_workflow_and_starts_once_without_sending_confirmation(): void
    {
        $item = $this->book();
        $item->order->updateQuietly(['status' => 'new_confirmed']);
        $admin = User::factory()->create(['role' => 'admin']);
        $summary = (new OrderAutomationSummary)->forKeys([$item->order->checkoutGroupKey()])[$item->order->checkoutGroupKey()];
        $this->assertSame('لم يدخل مسار الإنتاج الآلي', $summary['label']);
        $this->assertFalse($summary['tracking']);
        $this->assertTrue($summary['needs_enrollment']);
        $this->actingAs($admin)->get(route('admin.orders.groups.show', $item->order))->assertOk()->assertSee('فحص وإدخال للأوتوميشن')->assertDontSee('طلب إيقاف آمن');
        $payload = ['confirm' => 1, 'version' => 1];
        $this->post(route('admin.orders.workspace.admit', $item->order), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('order_process_runs', 1);
        $this->assertSame('queued', OrderProcessRun::sole()->status);
        $this->post(route('admin.orders.workspace.admit', $item->order), $payload)->assertStatus(409);
        $this->post(route('admin.orders.workspace.admit', $item->order), ['confirm' => 1, 'version' => 2])->assertSessionHasErrors('automation');
        $this->assertDatabaseCount('order_process_runs', 1);
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
    }

    public function test_admission_rejects_bad_photo_before_any_upgrade_or_generation_and_displays_reason(): void
    {
        $item = $this->book();
        Storage::disk('s3_private')->put('child-photos/synthetic.heic', 'synthetic unsupported image');
        $item->order->updateQuietly(['status' => 'new_confirmed', 'uploaded_photos' => ['child-photos/synthetic.heic']]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.orders.workspace.admit', $item->order), ['confirm' => 1, 'version' => 1])->assertSessionHasErrors('automation');
        $this->assertDatabaseCount('order_process_runs', 0);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $summary = (new OrderAutomationSummary)->forKeys([$item->order->checkoutGroupKey()])[$item->order->checkoutGroupKey()];
        $this->assertStringContainsString('HEIC', $summary['reason']);
        $this->assertFalse($summary['tracking']);
        $this->assertSame('intervention', $summary['state']);
        Http::assertNothingSent();
    }

    public function test_admission_requires_scope_and_does_not_unpause_or_ignore_fulfilment(): void
    {
        $item = $this->book();
        $item->order->updateQuietly(['status' => 'new_confirmed']);
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['confirm' => 1, 'version' => 1];
        $this->actor->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.photos.view', 'orders.production_prompt.manage', 'orders.automation.control'])->pluck('id'));
        $this->actingAs($this->actor->fresh())->post(route('admin.orders.workspace.admit', $item->order), $payload)->assertForbidden();
        DB::table('order_automation_controls')->insert(['checkout_key' => $item->order->checkoutGroupKey(), 'state' => 'paused', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin)->post(route('admin.orders.workspace.admit', $item->order), $payload)->assertSessionHasErrors('automation');
        $this->assertSame('paused', DB::table('order_automation_controls')->value('state'));
        DB::table('order_automation_controls')->update(['state' => 'running']);
        $item->order->updateQuietly(['printing_status' => 'completed']);
        $this->post(route('admin.orders.workspace.admit', $item->order), $payload)->assertSessionHasErrors('automation');
        $this->assertDatabaseCount('order_process_runs', 0);
        Http::assertNothingSent();
    }

    private function staffApprovalFixture(): array
    {
        $item = $this->book();
        $run = $this->enroll($item->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        app(PuzzleAutomation::class)->generate(PuzzleGeneration::sole()->id);
        $this->send($run);
        $admin = User::factory()->create(['role' => 'admin']);
        $manifest = OrderReviewManifest::sole();

        return [$item, $run->fresh(), $manifest, $admin];
    }

    private function staffApprovalPayload(OrderProcessRun $run, OrderReviewManifest $manifest): array
    {
        return ['manifest_id' => $manifest->id, 'fingerprint' => $manifest->fingerprint,
            'inputs_fingerprint' => app(StaffPreviewInputs::class)->fingerprint(app(StaffPreviewInputs::class)->capture($manifest)),
            'run_version' => $run->fresh()->version,
            'control_version' => DB::table('order_automation_controls')->where('checkout_key', $run->checkout_key)->value('version') ?? 1,
            'confirm' => 1];
    }

    public function test_staff_approval_settles_paused_paid_book_after_status_changes_without_resending(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        DB::table('order_group_assignments')->insert(['checkout_group_key' => $run->checkout_key, 'assigned_to_user_id' => $admin->id, 'assigned_by_user_id' => $admin->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $control = app(AutomationControl::class);
        $version = DB::table('order_automation_controls')->where('checkout_key', $run->checkout_key)->value('version');
        $control->pause($item->order, $admin, Request::create('/'), $version, 'Synthetic stop');
        $item->order->updateQuietly(['status' => 'pending_team_review', 'payment_status' => 'partially_paid', 'paid_amount_cents' => 20000]);
        $payload = $this->staffApprovalPayload($run, $manifest);
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($manifest->fresh()->approved_at);
        $this->assertNull($manifest->fresh()->response_message_id);
        $this->assertDatabaseCount('order_process_responses', 0);
        $this->assertDatabaseCount('order_preview_staff_approvals', 1);
        $this->assertDatabaseHas('order_preview_staff_approvals', ['manifest_id' => $manifest->id, 'user_id' => $admin->id, 'channel' => 'staff']);
        $this->assertFalse(AutomationHandoff::held($run->checkout_key));
        $this->assertFalse(AutomationControl::blocked($run->checkout_key));
        $this->assertSame('waiting_staff', $run->fresh()->status);
        $this->assertSame($admin->id, DB::table('order_group_assignments')->where('checkout_group_key', $run->checkout_key)->value('assigned_to_user_id'));
        $this->assertSame(20000, $item->order->fresh()->paid_amount_cents);
        $this->assertNull(app(BookPageService::class)->admissionReason($item->fresh()));
        app(BookPageService::class)->enroll();
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 1);
        $this->assertSame($item->id, CodexSceneRun::sole()->order_item_id);
        $this->assertSame(3, CodexSceneRun::sole()->scene_count);
        $this->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertStatus(409);
        $this->assertDatabaseCount('order_preview_staff_approvals', 1);
        $this->assertDatabaseCount('puzzle_generations', 1);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'order.preview_approved_by_staff', 'user_id' => $admin->id, 'subject_id' => $item->order_id]);
    }

    public function test_approved_book_resumes_after_staff_status_and_payment_changes_without_recreating_preview(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order), $this->staffApprovalPayload($run, $manifest))->assertSessionHasNoErrors();
        $manifest->refresh();
        app(AutomationHandoff::class)->take($item->order, $admin, Request::create('/'));
        $item->order->updateQuietly(['status' => 'delivered', 'payment_status' => 'partially_paid', 'paid_amount_cents' => 20400]);
        Setting::updateOrCreate(['key' => 'book_approved_enrollment_after'], ['value' => now()->addDay()->toDateTimeString()]);
        $this->assertFalse(app(BookPageService::class)->statusEligible($item->order->fresh()));
        app(AutomationHandoff::class)->resume($item->order, $admin, Request::create('/'));
        $this->assertFalse(AutomationHandoff::held($run->checkout_key));
        $this->assertSame('waiting_staff', $run->fresh()->status);
        $this->assertSame($manifest->approved_at?->toISOString(), $manifest->fresh()->approved_at?->toISOString());
        $this->assertSame('delivered', $item->order->fresh()->status);
        app(BookPageService::class)->enroll();
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 1);
        $this->assertDatabaseCount('order_review_manifests', 1);
        $this->assertDatabaseCount('puzzle_generations', 1);
        $this->assertSame(20400, $item->order->fresh()->paid_amount_cents);
        Http::assertSentCount(2);
    }

    public function test_safe_control_resume_accepts_unchanged_approved_book_but_keeps_changed_child_held(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order), $this->staffApprovalPayload($run, $manifest))->assertSessionHasNoErrors();
        $control = app(AutomationControl::class);
        $version = DB::table('order_automation_controls')->where('checkout_key', $run->checkout_key)->value('version');
        $control->pause($item->order, $admin, Request::create('/'), $version, 'Synthetic stop');
        $originalName = $item->order->child_name;
        $item->order->updateQuietly(['status' => 'delivered', 'child_name' => 'Changed child']);
        $version = DB::table('order_automation_controls')->where('checkout_key', $run->checkout_key)->value('version');
        try {
            $control->resume($item->order, $admin, Request::create('/'), $version);
            $this->fail('Changed child cannot use prior cover consent');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assignment', $e->errors());
        }
        $this->assertTrue(AutomationHandoff::held($run->checkout_key));
        $this->assertTrue(AutomationControl::blocked($run->checkout_key));
        $item->order->updateQuietly(['child_name' => $originalName, 'payment_status' => 'partially_paid', 'paid_amount_cents' => 100]);
        $control->resume($item->order, $admin, Request::create('/'), $version);
        $this->assertFalse(AutomationHandoff::held($run->checkout_key));
        $this->assertFalse(AutomationControl::blocked($run->checkout_key));
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 1);
    }

    public function test_staff_approval_does_not_record_payment_or_generate_unpaid_pages(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $this->actingAs($admin)->get(route('admin.orders.groups.show', $item->order))->assertOk()
            ->assertSee('تسجيل الموافقة واستكمال الطلب')->assertDontSee('طريقة موافقة العميل')->assertDontSee('تفاصيل الموافقة')->assertDontSee('مراجعة الدفع — مرفقات العميل');
        $this->post(route('admin.orders.workspace.preview-approval', $item->order), $this->staffApprovalPayload($run, $manifest))->assertRedirect()->assertSessionHasNoErrors();
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
        $this->assertSame(0, $item->order->fresh()->paid_amount_cents);
        $this->assertNotNull($manifest->fresh()->approved_at);
        Http::assertSentCount(2);
    }

    public function test_staff_approval_rejects_changed_data_and_changed_page_prompt(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $payload = $this->staffApprovalPayload($run, $manifest);
        $item->order->updateQuietly(['child_name' => 'Different child']);
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertSessionHasErrors('preview_approval');
        $item->order->updateQuietly(['child_name' => 'Synthetic child']);
        $item->productionComponents()->where('production_stage', 'book_interior')->update(['prompt_template' => 'Changed interior']);
        $this->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertStatus(409);
        $this->assertNull($manifest->fresh()->approved_at);
        $this->assertDatabaseCount('order_preview_staff_approvals', 0);
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    public function test_note_edit_can_be_explicitly_approved_without_replacing_accepted_cover(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order), $this->staffApprovalPayload($run, $manifest))->assertSessionHasNoErrors();
        Setting::updateOrCreate(['key' => 'book_approved_enrollment_after'], ['value' => now()->addDay()->toDateTimeString()]);
        $originalApproval = OrderPreviewStaffApproval::sole()->getRawOriginal();
        $originalManifest = $manifest->fresh()->getRawOriginal();
        app(AutomationHandoff::class)->take($item->order, $admin, Request::create('/'));
        $item->order->updateQuietly(['parent_notes' => 'Use simpler interior activities', 'status' => 'delivered', 'paid_amount_cents' => 20000, 'payment_status' => 'partially_paid']);
        $this->get(route('admin.orders.groups.show', $item->order))->assertOk()->assertSee('تسجيل الموافقة واستكمال الطلب');
        $payload = $this->staffApprovalPayload($run, $manifest->fresh());
        $this->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('order_preview_staff_approvals', 2);
        $this->assertSame($originalApproval, OrderPreviewStaffApproval::oldest('id')->first()->getRawOriginal());
        $this->assertSame($originalManifest, $manifest->fresh()->getRawOriginal());
        $this->assertFalse(AutomationHandoff::held($run->checkout_key));
        $this->assertNull(app(BookPageService::class)->admissionReason($item->fresh()));
        $this->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertStatus(409);
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 1);
        $this->assertDatabaseCount('puzzle_generations', 1);
        Http::assertSentCount(2);
    }

    public function test_note_edit_before_first_staff_approval_preserves_child_variant_and_photo_gates(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $item->order->updateQuietly(['parent_notes' => 'New instruction for pages']);
        $payload = $this->staffApprovalPayload($run, $manifest);
        $item->order->updateQuietly(['parent_notes' => 'Another instruction after opening page']);
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertStatus(409);
        $payload = $this->staffApprovalPayload($run, $manifest);
        $item->order->updateQuietly(['interests' => 'Changed theme']);
        $this->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertSessionHasErrors('preview_approval');
        $this->assertDatabaseCount('order_preview_staff_approvals', 0);
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    public function test_staff_can_accept_existing_cover_after_template_refresh_without_regenerating_it(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $originalImages = $manifest->images;
        $item->productionComponents()->where('production_stage', 'book_cover')->update(['prompt_template' => 'Refreshed cover template {{child_full_name}}']);
        $item->productionComponents()->where('production_stage', 'book_interior')->update(['prompt_template' => 'Refreshed inside {{book_page_count}} pages {{child_full_name}}']);
        $item->order->updateQuietly(['payment_status' => 'partially_paid', 'paid_amount_cents' => 20000]);
        $payload = $this->staffApprovalPayload($run, $manifest);
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertSessionHasNoErrors();
        $this->assertSame($originalImages, $manifest->fresh()->images);
        $this->assertNotNull($manifest->fresh()->approved_at);
        $ordersBefore = DB::table('orders')->get()->toJson();
        $this->partialMock(PuzzleInputs::class)->shouldNotReceive('captureCurrent');
        $context = app(OrderContextBuilder::class)->build($item->order->checkoutGroupKey());
        $this->assertNotNull($context['review']['staff_approval_ref']);
        $this->assertTrue(collect($context['units'])->firstWhere('capability', 'book_interior')['cover_approved']);
        $this->assertTrue(collect($context['units'])->firstWhere('capability', 'book_interior')['book_admission_eligible']);
        $this->assertSame($ordersBefore, DB::table('orders')->get()->toJson());
        $item->order->updateQuietly(['status' => 'delivered', 'printing_status' => 'not_started', 'shipping_status' => 'not_ready']);
        $context = app(OrderContextBuilder::class)->build($item->order->checkoutGroupKey());
        $this->assertFalse(collect($context['units'])->firstWhere('capability', 'book_interior')['book_admission_eligible']);
        $this->app->forgetInstance(PuzzleInputs::class);
        $book = app(BookPageService::class)->start($item->fresh(), $manifest->fresh(), true);
        $this->assertStringContainsString('Refreshed inside 3 pages', $book->snapshot['prompt']);
        $this->assertSame($originalImages[0]['sha256'], $book->snapshot['identity_checksum']);
        $this->assertDatabaseCount('puzzle_generations', 1);
        $item->order->updateQuietly(['parent_notes' => 'Changed customer requirement']);
        $this->expectException(\RuntimeException::class);
        app(BookPageService::class)->fingerprint($book->fresh());
    }

    public function test_staff_approval_rejects_stale_version_cancelled_order_and_unsettled_execution(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $payload = $this->staffApprovalPayload($run, $manifest);
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order), array_replace($payload, ['run_version' => $run->version + 1]))->assertStatus(409);
        $item->order->updateQuietly(['status' => 'cancelled']);
        $this->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertSessionHasErrors('preview_approval');
        $item->order->updateQuietly(['status' => 'preview_uploaded']);
        $run->steps()->where('type', 'whatsapp')->update(['status' => 'uncertain']);
        $this->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertSessionHasErrors('preview_approval');
        $this->assertDatabaseCount('order_preview_staff_approvals', 0);
        $this->assertNull($manifest->fresh()->approved_at);
    }

    public function test_staff_approval_requires_permissions_current_assignment_and_explicit_consent(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $payload = $this->staffApprovalPayload($run, $manifest);
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order), array_replace($payload, ['confirm' => 0]))->assertSessionHasErrors('confirm');
        $this->actor->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.photos.view', 'orders.production_prompt.manage', 'orders.automation.control'])->pluck('id'));
        $this->actingAs($this->actor->fresh())->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertForbidden();
        $this->assertDatabaseCount('order_preview_staff_approvals', 0);
    }

    public function test_staff_approval_rejects_new_preview_and_unreadable_output_without_partial_mutation(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $payload = $this->staffApprovalPayload($run, $manifest);
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order), array_replace($payload, ['manifest_id' => $manifest->id + 1]))->assertStatus(409);
        $this->post(route('admin.orders.workspace.preview-approval', $item->order), array_replace($payload, ['fingerprint' => str_repeat('0', 64)]))->assertStatus(409);
        $image = $manifest->images[0];
        Storage::disk($image['disk'])->put($image['path'], 'corrupt synthetic output');
        $this->post(route('admin.orders.workspace.preview-approval', $item->order), $payload)->assertSessionHasErrors('preview_approval');
        $this->assertNull($manifest->fresh()->approved_at);
        $this->assertSame('waiting_response', $run->fresh()->status);
        $this->assertDatabaseCount('order_preview_staff_approvals', 0);
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    public function test_late_customer_reply_cannot_repeat_staff_approval_or_change_payment(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order), $this->staffApprovalPayload($run, $manifest))->assertSessionHasNoErrors();
        $approvedAt = $manifest->fresh()->approved_at->toISOString();
        $this->reply($run);
        $this->assertSame($approvedAt, $manifest->fresh()->approved_at->toISOString());
        $this->assertDatabaseCount('order_preview_staff_approvals', 1);
        $this->assertDatabaseCount('order_process_responses', 0);
        $this->assertSame(0, $item->order->fresh()->paid_amount_cents);
        Http::assertSentCount(2);
    }

    public function test_paid_approved_legacy_status_is_visible_and_requires_explicit_admission(): void
    {
        [$item,$flow,$manifest] = $this->approvedBook();
        $item->order->updateQuietly(['status' => 'delivered', 'payment_status' => 'partially_paid', 'paid_amount_cents' => 20700, 'printing_status' => 'not_started', 'shipping_status' => 'not_ready']);
        Setting::where('key', 'book_approved_enrollment_after')->update(['value' => now()->addMinute()->toDateTimeString()]);
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
        $summary = app(OrderAutomationSummary::class)->forKeys([$item->order->checkoutGroupKey()])[$item->order->checkoutGroupKey()];
        $this->assertStringStartsWith('صفحات التلوين لم تبدأ', $summary['label']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson(route('admin.orders.codex-scenes.banner', $item->order))->assertOk()->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, 'صفحات التلوين لم تبدأ'))->has('version'));
    }

    public function test_book_start_reloads_item_relations_after_concurrent_prompt_edit(): void
    {
        [$item, $flow, $manifest] = $this->approvedBook();
        $item->order->updateQuietly(['status' => 'delivered', 'payment_status' => 'partially_paid', 'paid_amount_cents' => 1, 'printing_status' => 'not_started', 'shipping_status' => 'not_ready']);
        $item->load('productionComponents');
        $item->productionComponents()->where('production_stage', 'book_interior')->update(['prompt_template' => 'Changed pages after approval']);
        try {
            app(BookPageService::class)->start($item, $manifest, true);
            $this->fail('Stale item must not generate approved content after edits.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Book inputs changed after approval', $error->getMessage());
        }
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    public function test_explicit_book_start_uses_codex_once_and_preserves_payment(): void
    {
        [$item,$flow,$manifest] = $this->approvedBook();
        $item->order->updateQuietly(['status' => 'delivered', 'payment_status' => 'partially_paid', 'paid_amount_cents' => 1, 'printing_status' => 'not_started', 'shipping_status' => 'not_ready']);
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['confirm' => 1, 'expected_updated_at' => $item->fresh()->updated_at->toISOString()];
        $this->actingAs($admin)->post(route('admin.orders.workspace.book-start', [$item->order, $item]), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('coloring_book', CodexSceneRun::sole()->production_kind);
        $this->assertSame('waiting_slot', CodexSceneRun::sole()->status);
        $this->post(route('admin.orders.workspace.book-start', [$item->order, $item]), $payload)->assertSessionHasErrors('automation');
        $this->assertDatabaseCount('codex_scene_runs', 1);
        $this->assertSame(1, $item->order->fresh()->paid_amount_cents);
    }

    public function test_new_approved_paid_status_enters_but_printed_book_does_not(): void
    {
        [$item,$flow,$manifest] = $this->approvedBook();
        Setting::where('key', 'book_approved_enrollment_after')->update(['value' => now()->subMinute()->toDateTimeString()]);
        $item->order->updateQuietly(['status' => 'delivered', 'payment_status' => 'partially_paid', 'paid_amount_cents' => 1, 'printing_status' => 'completed', 'shipping_status' => 'not_ready']);
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
        $item->order->updateQuietly(['printing_status' => 'not_started']);
        // Editing an old order must not silently enroll historical work.
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
        $item->order->statusLogs()->create(['status_type' => 'order', 'status' => 'delivered']);
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 1);
    }

    public function test_product_only_cover_preview_approval_and_positive_payment_gate(): void
    {
        [$item,$flow,$manifest] = $this->approvedBook();
        $service = app(BookPageService::class);
        $service->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
        $this->assertSame(0, (int) $item->order->fresh()->paid_amount_cents);
        $item->order->updateQuietly(['payment_status' => 'partially_paid', 'paid_amount_cents' => 1]);
        $service->enroll();
        $service->enroll();
        $run = CodexSceneRun::sole();
        $this->assertSame(3, $run->scene_count);
        $this->assertSame($item->id, $run->order_item_id);
        $this->assertSame(6, $run->protocol);
        app(ManualSceneService::class)->tick();
        $this->assertSame('queued', $run->fresh()->status);
        $data = json_decode(file_get_contents($this->spool.'/inbox/'.$run->id.'/manifest.json'), true);
        $this->assertSame($item->id, $data['order_item_id']);
        $this->assertSame(3, $data['scene_count']);
        $this->assertStringContainsString('3 coloring pages', $data['prompt']);
        foreach (range(1, 3) as $n) {
            $this->pageOutput($run, $n);
        }
        app(ManualSceneService::class)->collect($run);
        app(ManualSceneService::class)->collect($run->fresh());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('delivered', $item->order->fresh()->status);
        $this->assertCount(3, $run->fresh()->scenes);
        foreach ($run->fresh()->scenes as $scene) {
            $this->assertNull($scene['attachment_id']);
            $this->assertSame($scene['sha256'], hash('sha256', Storage::disk('s3_private')->get($scene['path'])));
        }
        $pdf = $run->fresh()->book_pdf;
        $this->assertSame('ready', $pdf['status']);
        $attachment = OrderAttachment::findOrFail($pdf['attachment_id']);
        $this->assertSame('s3_private', $attachment->disk);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame('product:'.$item->id.':component:pages', $attachment->production_unit_key);
        $this->assertStringStartsWith('%PDF-', Storage::disk('s3_private')->get($attachment->path));
        $this->assertSame(1, OrderAttachment::where('production_unit_key', $attachment->production_unit_key)->count());

    }

    public function test_catalog_book_prompt_sync_preserves_cover_consent_and_pins_dispatched_run(): void
    {
        [$item, $flow, $manifest] = $this->approvedBook();
        $source = $item->product->productionComponents()->create(['stable_key' => 'new-catalog-pages-key', 'name' => 'Pages', 'production_stage' => 'book_interior', 'quantity_per_item' => 50, 'is_active' => true,
            'prompt_template' => 'New interior {{book_page_count}} pages for {{child_full_name}}']);
        $manifestBefore = $manifest->getRawOriginal();
        $sync = app(ProductProductionPromptSyncService::class);
        $this->assertSame(1, $sync->syncForProduct($item->product));
        $this->assertSame(0, $sync->syncForProduct($item->product));
        $part = $item->productionComponents()->where('production_stage', 'book_interior')->sole();
        $this->assertSame(3, $part->quantity_per_item);
        $this->assertSame($source->prompt_template, $part->prompt_template);
        $this->assertDatabaseCount('book_prompt_revisions', 1);
        $context = app(OrderContextBuilder::class)->build($item->order->checkoutGroupKey());
        $this->assertTrue(collect($context['units'])->firstWhere('capability', 'book_interior')['cover_approved']);
        $this->assertTrue(app(ProcessProductReview::class)->ready($flow->fresh()));
        $this->assertSame($manifestBefore, $manifest->fresh()->getRawOriginal());
        $item->order->updateQuietly(['payment_status' => 'partially_paid', 'paid_amount_cents' => 1]);
        $run = app(BookPageService::class)->start($item->fresh(), $manifest->fresh(), true);
        $this->assertStringContainsString('New interior 3 pages', $run->snapshot['prompt']);
        $fingerprint = $run->fingerprint;
        $source->update(['prompt_template' => 'Later template {{child_full_name}}']);
        $this->assertSame(0, $sync->syncForProduct($item->product));
        $this->assertSame($fingerprint, app(BookPageService::class)->fingerprint($run->fresh()));
        $this->assertStringContainsString('New interior 3 pages', $run->fresh()->snapshot['prompt']);
    }

    public function test_catalog_prompt_sync_does_not_approve_changed_page_count_or_replace_generated_cover(): void
    {
        [$item, $flow, $manifest] = $this->approvedBook();
        foreach (['cover' => 'book_cover', 'pages' => 'book_interior'] as $key => $stage) {
            $item->product->productionComponents()->create(['stable_key' => $key, 'name' => $key, 'production_stage' => $stage,
                'quantity_per_item' => 50, 'is_active' => true, 'prompt_template' => 'New '.$key.' {{child_full_name}}']);
        }
        $oldCover = $item->productionComponents()->where('production_stage', 'book_cover')->value('prompt_template');
        $result = app(BookPromptSync::class)->sync($item->product, $this->actor->id);
        $this->assertSame(['updated' => 1, 'frozen' => 1], $result);
        $this->assertSame($oldCover, $item->productionComponents()->where('production_stage', 'book_cover')->value('prompt_template'));
        $this->assertNotNull($manifest->fresh()->approved_at);
        $item->productionComponents()->where('production_stage', 'book_interior')->update(['quantity_per_item' => 40]);
        $this->assertFalse(app(BookPromptSync::class)->matchesInterior($item->fresh(), $manifest->images[0]['interior_fingerprint']));
        $context = app(OrderContextBuilder::class)->build($item->order->checkoutGroupKey());
        $this->assertFalse(collect($context['units'])->firstWhere('capability', 'book_interior')['cover_approved']);
        $this->expectException(\RuntimeException::class);
        app(ProcessProductReview::class)->ready($flow->fresh());
    }

    public function test_book_prompt_backfill_command_is_dry_by_default_and_requires_matching_catalog(): void
    {
        $item = $this->book();
        $item->product->productionComponents()->create(['stable_key' => 'new-pages', 'name' => 'Pages', 'production_stage' => 'book_interior',
            'quantity_per_item' => 99, 'is_active' => true, 'prompt_template' => 'Updated {{book_page_count}} {{child_full_name}}']);
        $part = $item->productionComponents()->where('production_stage', 'book_interior')->sole();
        $before = $part->prompt_template;
        $this->assertSame(0, Artisan::call('orders:sync-book-prompts'));
        $plan = json_decode(Artisan::output(), true);
        $this->assertSame(1, $plan['products'][$item->product_id]['updated']);
        $this->assertSame($before, $part->fresh()->prompt_template);
        $this->assertDatabaseCount('book_prompt_revisions', 0);
        $this->assertSame(1, Artisan::call('orders:sync-book-prompts', ['--apply' => true]));
        $this->assertSame(0, Artisan::call('orders:sync-book-prompts', ['--apply' => true, '--actor' => $this->actor->id, '--expect' => $plan['catalog_hash']]));
        $this->assertDatabaseCount('book_prompt_revisions', 1);
        $this->assertSame(3, $part->fresh()->quantity_per_item);
        $this->assertDatabaseCount('codex_scene_runs', 0);
        Http::assertNothingSent();
    }

    private function pdfFixture(int $count): CodexSceneRun
    {
        $item = $this->book();
        $run = CodexSceneRun::create(['id' => (string) Str::uuid(), 'order_id' => $item->order_id, 'order_item_id' => $item->id,
            'checkout_key' => $item->order->checkoutGroupKey(), 'production_kind' => 'coloring_book', 'scene_count' => $count,
            'source' => 'automatic', 'protocol' => 6, 'status' => 'completed', 'previous_status' => 'delivered', 'fingerprint' => str_repeat('a', 64),
            'snapshot' => ['unit_key' => 'product:'.$item->id.':component:pages']]);
        $scenes = [];
        foreach (array_reverse(range(1, $count)) as $n) {
            $image = imagecreatetruecolor(1024, 1448);
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            $ink = imagecolorallocate($image, 20, 20, 20);
            imagerectangle($image, 30, 30, 994, 1418, $ink);
            imageellipse($image, 512, 650, 400 + $n, 400, $ink);
            imagestring($image, 5, 400, 150, 'SYNTHETIC PAGE '.$n, $ink);
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $path = 'orders/'.$run->order_id.'/codex-scenes/'.$run->id.'/page-'.sprintf('%02d', $n).'-a4.png';
            Storage::disk('s3_private')->put($path, $bytes);
            $scenes[(string) $n] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'source_run_id' => $run->id, 'attachment_id' => null];
        }
        $run->update(['scenes' => $scenes]);

        return $run;
    }

    public function test_book_pdf_20_40_50_pages_is_a4_single_attachment_and_idempotent(): void
    {
        $service = app(BookPdfService::class);
        foreach ([20, 40, 50] as $count) {
            $run = $this->pdfFixture($count);
            $service->request($run);
            $run->save();
            $service->publish($run);
            $service->publish($run->fresh());
            $meta = $run->fresh()->book_pdf;
            $this->assertSame('ready', $meta['status']);
            $this->assertTrue($meta['complete']);
            $this->assertSame([], $meta['missing_pages']);
            $this->assertSame(1, $meta['attempts']);
            $bytes = Storage::disk('s3_private')->get($meta['path']);
            $parser = new Mpdf(['tempDir' => storage_path('framework/cache/mpdf')]);
            $this->assertSame($count, $parser->setSourceFile(StreamReader::createByString($bytes)));
            $size = $parser->getTemplateSize($parser->importPage(1));
            $this->assertEqualsWithDelta(210, $size['width'], .1);
            $this->assertEqualsWithDelta(297, $size['height'], .1);
            $this->assertSame(1, OrderAttachment::where('order_id', $run->order_id)->where('production_unit_key', $run->snapshot['unit_key'])->count());
            if ($count === 20) {
                File::ensureDirectoryExists(storage_path('app/testing-pdf'));
                file_put_contents(storage_path('app/testing-pdf/book-20.pdf'), $bytes);
            }
        }
    }

    public function test_partial_pdf_keeps_missing_page_positions_and_rejects_other_book(): void
    {
        $service = app(BookPdfService::class);
        $run = $this->pdfFixture(3);
        $scenes = $run->scenes;
        unset($scenes['2']);
        $run->update(['scenes' => $scenes, 'status' => 'needs_manual']);
        $service->request($run);
        $run->save();
        $service->publish($run);
        $meta = $run->fresh()->book_pdf;
        $this->assertSame('ready', $meta['status']);
        $this->assertFalse($meta['complete']);
        $this->assertSame([2], $meta['missing_pages']);
        $attachment = OrderAttachment::findOrFail($meta['attachment_id']);
        $this->assertStringContainsString('INCOMPLETE', $attachment->original_name);
        $bytes = Storage::disk('s3_private')->get($meta['path']);
        $parser = new Mpdf(['tempDir' => storage_path('framework/cache/mpdf')]);
        $this->assertSame(3, $parser->setSourceFile(StreamReader::createByString($bytes)));
        File::ensureDirectoryExists(storage_path('app/testing-pdf'));
        file_put_contents(storage_path('app/testing-pdf/book-partial.pdf'), $bytes);
        $other = $this->pdfFixture(3);
        $this->expectException(\RuntimeException::class);
        $service::pageBytes($other, 1, $run->scenes['1']);
    }

    public function test_pdf_failure_retries_assembly_only_and_does_not_repack_historical_runs(): void
    {
        $service = app(BookPdfService::class);
        $run = $this->pdfFixture(3);
        Queue::fake();
        $service->retryPending();
        $this->assertNull($run->fresh()->book_pdf);
        $service->request($run);
        $run->save();
        $path = $run->scenes['1']['path'];
        $bytes = Storage::disk('s3_private')->get($path);
        Storage::disk('s3_private')->put($path, 'corrupted');
        $service->publish($run);
        $this->assertSame('failed', $run->fresh()->book_pdf['status']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertCount(3, $run->fresh()->scenes);
        Storage::disk('s3_private')->put($path, $bytes);
        $service->retryPending();
        $this->assertSame('ready', $run->fresh()->book_pdf['status']);
        $this->assertSame(2, $run->fresh()->book_pdf['attempts']);
        $this->assertSame(1, CodexSceneRun::count());
        Queue::assertNothingPushed();
        $broken = $this->pdfFixture(3);
        $service->request($broken);
        $broken->save();
        Storage::disk('s3_private')->put($broken->scenes['1']['path'], 'bad');
        foreach (range(1, 4) as $_) {
            $service->publish($broken->fresh());
        }
        $this->assertSame(3, $broken->fresh()->book_pdf['attempts']);
        $this->assertSame('failed', $broken->fresh()->book_pdf['status']);
        $this->assertSame('completed', $broken->fresh()->status);
    }

    private function pageOutput(CodexSceneRun $run, int $n, ?int $wrongItem = null): void
    {
        $dir = $this->spool.'/outbox/'.$run->id;
        File::ensureDirectoryExists($dir);
        $bytes = $this->png($n + 10);
        file_put_contents($dir.'/scene-'.sprintf('%02d', $n).'.png', $bytes);
        file_put_contents($dir.'/scene-'.sprintf('%02d', $n).'.json', json_encode(['id' => $run->id, 'order_id' => $run->order_id, 'order_item_id' => $wrongItem ?? $run->order_item_id, 'production_kind' => 'coloring_book', 'identity_checksum' => $run->snapshot['identity_checksum'], 'scene' => $n, 'sha256' => hash('sha256', $bytes)]));
        $progress = [];
        foreach (range(1, $n) as $i) {
            $progress[$i] = ['status' => 'succeeded', 'attempts' => [['number' => 1, 'status' => 'succeeded', 'started_at' => $run->created_at->timestamp, 'finished_at' => $run->created_at->timestamp]]];
        }
        file_put_contents($dir.'/status.json', json_encode(['id' => $run->id, 'status' => $n === $run->scene_count ? 'completed' : 'running', 'scene_progress' => $progress]));
    }

    public function test_partial_book_keeps_later_pages_and_hands_only_missing_page_to_team(): void
    {
        [$item,,$manifest] = $this->approvedBook();
        $item->order->updateQuietly(['payment_status' => 'partially_paid', 'paid_amount_cents' => 1]);
        $run = app(BookPageService::class)->start($item, $manifest);
        app(ManualSceneService::class)->tick();
        $this->pageOutput($run, 1);
        $this->pageOutput($run, 3);
        $file = $this->spool.'/outbox/'.$run->id.'/status.json';
        $state = json_decode(file_get_contents($file), true);
        $state['status'] = 'needs_manual';
        $attempt = ['status' => 'failed', 'code' => 'invalid_dimensions', 'started_at' => $run->created_at->timestamp, 'finished_at' => $run->created_at->timestamp];
        $state['scene_progress'][2] = ['status' => 'failed', 'code' => 'invalid_dimensions', 'attempts' => [['number' => 1] + $attempt, ['number' => 2] + $attempt]];
        file_put_contents($file, json_encode($state));
        app(ManualSceneService::class)->collect($run);
        $this->assertSame('needs_manual', $run->fresh()->status);
        $this->assertSame('pending_team_review', $item->order->fresh()->status);
        $this->assertEquals([1, 3], array_keys($run->fresh()->scenes));
        $this->assertNull($run->fresh()->active_slot);
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 1);
    }

    public function test_wrong_item_page_is_never_attached(): void
    {
        [$item,,$manifest] = $this->approvedBook();
        $item->order->updateQuietly(['payment_status' => 'partially_paid', 'paid_amount_cents' => 1]);
        $run = app(BookPageService::class)->start($item, $manifest);
        app(ManualSceneService::class)->tick();
        $this->pageOutput($run, 1, $item->id + 1);
        app(ManualSceneService::class)->tick();
        $this->assertSame('uncertain', $run->fresh()->status);
        $this->assertSame([], $run->fresh()->scenes);
    }

    public function test_modified_page_prompt_requires_new_approval(): void
    {
        [$item,,$manifest] = $this->approvedBook();
        $item->order->updateQuietly(['payment_status' => 'partially_paid', 'paid_amount_cents' => 1]);
        $item->productionComponents()->where('production_stage', 'book_interior')->update(['prompt_template' => 'Changed']);
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    public function test_receipt_attachment_after_approval_marks_review_without_money_or_pages(): void
    {
        [$item,$run,$manifest,$conversation] = $this->approvedBook();
        config(['order_process.payment_review_enabled' => true]);
        $conversation->messages()->create(['remote_id' => 'file', 'remote_hash' => hash('sha256', 'file'), 'fingerprint' => hash('sha256', 'file'),
            'direction' => 'inbound', 'kind' => 'image', 'attachments' => [['name' => 'synthetic.png']], 'sent_at' => now(),
            'provider_metadata' => ['interactionId' => 'review-interaction', 'channelId' => 'wamid.file']]);
        $review = app(ProcessPaymentReview::class);
        $this->assertTrue($review->needsFullWindow($run->context['phone']));
        $review->process($conversation);
        $review->process($conversation);
        $this->assertDatabaseCount('order_payment_reviews', 1);
        $this->assertDatabaseCount('order_payment_review_signals', 1);
        $this->assertSame('pending_review', $item->order->fresh()->payment_status);
        $this->assertSame(0, (int) $item->order->fresh()->paid_amount_cents);
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    public function test_mixed_checkout_waits_for_story_identity_and_cover_and_approves_both_once(): void
    {
        $item = $this->book();
        $story = Story::create(['title' => 'Synthetic story', 'slug' => 'mixed-test', 'language' => 'ar', 'gender' => 'both', 'price' => 100, 'active' => true]);
        $storyOrder = $item->order->replicate();
        $storyOrder->order_number = 'SYN-STORY';
        $storyOrder->child_name = 'Second synthetic child';
        $storyOrder->child_age = 7;
        $storyOrder->story_id = $story->id;
        $storyOrder->save();
        $storyOrder->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => 'Synthetic story', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
        $storyOrder->updateQuietly(['status' => 'new_confirmed']);
        $run = $this->enroll($item->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->twice()->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        app(ProcessIdentityService::class)->generate(OrderProcessIdentity::sole()->id);
        app(ProcessRuntime::class)->advance($run->id);
        Http::assertNothingSent();
        app(PuzzleAutomation::class)->generate(PuzzleGeneration::sole()->id);
        $this->send($run);
        $this->assertSame('preview_uploaded', $item->order->fresh()->status);
        $this->assertSame('preview_uploaded', $storyOrder->fresh()->status);
        $this->assertCount(2, OrderReviewManifest::sole()->images);
        $this->assertEqualsCanonicalizing([$item->order_id, $storyOrder->id], array_column(OrderReviewManifest::sole()->images, 'order_id'));
        $this->assertStringContainsString('Second synthetic child', collect(OrderReviewManifest::sole()->images)->firstWhere('kind', 'identity')['label']);
        $this->reply($run);
        $this->assertNotNull(OrderReviewManifest::sole()->approved_at);
        $this->assertNotNull($storyOrder->fresh()->child_identity_approved_attempt_id);
        $this->assertSame('costomer_confirmed', $storyOrder->fresh()->status);
        $this->assertSame('pending_team_review', $item->order->fresh()->status);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('order_process_responses', 1);
    }

    public function test_mixed_story_puzzle_poster_book_and_mug_produce_one_complete_preview(): void
    {
        $book = $this->book();
        $story = Story::create(['title' => 'Synthetic story', 'slug' => 'poster-mixed-story', 'language' => 'ar', 'gender' => 'both', 'price' => 100, 'active' => true]);
        $storyOrder = $book->order->replicate();
        $storyOrder->order_number = 'SYN-POSTER-STORY';
        $storyOrder->story_id = $story->id;
        $storyOrder->status = 'new_confirmed';
        $storyOrder->save();
        $storyOrder->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => 'Synthetic story', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
        $posterItem = null;
        foreach (['personalized-child-adventure-hdf-puzzle', 'bostr-mkhss-bsor-altfl-mkas-a4', 'personalized-child-family-photo-mug'] as $slug) {
            $order = $book->order->replicate();
            $order->order_number = 'SYN-'.Str::random(8);
            $order->status = 'new_confirmed';
            $order->save();
            $product = Product::create(['slug' => $slug, 'name_ar' => $slug, 'is_active' => true, 'price_cents' => 10000,
                'production_prompt_template' => 'One image for {{child_full_name}} using {{child_image_references}}']);
            $item = $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => $slug, 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
            if ($slug === 'bostr-mkhss-bsor-altfl-mkas-a4') {
                $posterItem = $item;
            }
        }
        $run = $this->enroll($book->order);
        $this->assertDatabaseCount('puzzle_generations', 4);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->times(5)
            ->andReturnUsing(function (GptImageRequest $request) {
                $bytes = $this->png;
                if ($request->size === '1536x1024') {
                    $image = imagecreatetruecolor(1536, 1024);
                    ob_start();
                    imagepng($image);
                    $bytes = ob_get_clean();
                    imagedestroy($image);
                }

                return new GptImageResult($bytes, 'png', 'image/png', 'synthetic', [], []);
            });
        app(ProcessIdentityService::class)->generate(OrderProcessIdentity::sole()->id);
        foreach (PuzzleGeneration::orderBy('id')->get() as $generation) {
            app(PuzzleAutomation::class)->generate($generation->id);
            $this->assertSame('succeeded', $generation->fresh()->status, $generation->fresh()->error_code ?? '');
        }
        $this->send($run);
        $manifest = OrderReviewManifest::sole();
        $this->assertCount(5, $manifest->images);
        $this->assertSame($posterItem->id, collect($manifest->images)->firstWhere('item_id', $posterItem->id)['item_id']);
        $this->assertSame('preview_uploaded', $book->order->fresh()->status);
        $this->assertDatabaseCount('codex_scene_runs', 0);
        $this->assertDatabaseCount('order_process_runs', 1);
        Http::assertSentCount(2);
    }

    public function test_staff_approval_of_mixed_two_child_preview_approves_exact_identity_and_cover(): void
    {
        $item = $this->book();
        $story = Story::create(['title' => 'Synthetic story', 'slug' => 'mixed-test', 'language' => 'ar', 'gender' => 'both', 'price' => 100, 'active' => true]);
        $storyOrder = $item->order->replicate();
        $storyOrder->order_number = 'SYN-STORY';
        $storyOrder->child_name = 'Second synthetic child';
        $storyOrder->child_age = 7;
        $storyOrder->story_id = $story->id;
        $storyOrder->save();
        $storyOrder->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => 'Synthetic story', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
        $storyOrder->updateQuietly(['status' => 'new_confirmed']);
        $run = $this->enroll($item->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->twice()->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        app(ProcessIdentityService::class)->generate(OrderProcessIdentity::sole()->id);
        app(ProcessRuntime::class)->advance($run->id);
        Http::assertNothingSent();
        app(PuzzleAutomation::class)->generate(PuzzleGeneration::sole()->id);
        $this->send($run);
        $this->assertSame('preview_uploaded', $item->order->fresh()->status);
        $this->assertSame('preview_uploaded', $storyOrder->fresh()->status);
        $this->assertCount(2, OrderReviewManifest::sole()->images);
        $this->assertEqualsCanonicalizing([$item->order_id, $storyOrder->id], array_column(OrderReviewManifest::sole()->images, 'order_id'));
        $this->assertStringContainsString('Second synthetic child', collect(OrderReviewManifest::sole()->images)->firstWhere('kind', 'identity')['label']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.orders.workspace.preview-approval', $item->order),
            $this->staffApprovalPayload($run, OrderReviewManifest::sole()))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull(OrderReviewManifest::sole()->approved_at);
        $this->assertNotNull($storyOrder->fresh()->child_identity_approved_attempt_id);
        $this->assertSame('costomer_confirmed', $storyOrder->fresh()->status);
        $this->assertSame('pending_team_review', $item->order->fresh()->status);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('order_process_responses', 0);
        $this->assertDatabaseCount('order_preview_staff_approvals', 1);
        $this->assertSame($storyOrder->fresh()->child_identity_approved_attempt_id, OrderProcessIdentity::sole()->attempt_id);
        $this->assertSame($admin->id, OrderPreviewStaffApproval::sole()->user_id);
    }

    public function test_old_worker_and_manual_hold_block_book_admission(): void
    {
        [$item,,$manifest] = $this->approvedBook();
        $item->order->updateQuietly(['payment_status' => 'partially_paid', 'paid_amount_cents' => 1]);
        file_put_contents($this->spool.'/outbox/health.json', '{"authenticated":true}');
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
        file_put_contents($this->spool.'/outbox/health.json', '{"authenticated":true,"supported_protocols":[5,6]}');
        DB::table('order_automation_holds')->insert(['checkout_key' => $item->order->checkoutGroupKey(), 'user_id' => $this->actor->id, 'runs' => '[]', 'confirmations' => '[]', 'fingerprint' => 'synthetic', 'created_at' => now(), 'updated_at' => now()]);
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    public function test_template_disabled_cannot_close_conversation_or_send(): void
    {
        config(['product_review.template_ready' => false]);
        try {
            app(OrderConfirmationTransport::class)->sendTemplate('201000000001', ProcessTemplates::REVIEW, ['a', 'b', 'c', 'd'], 'test');
            $this->fail();
        } catch (OrderConfirmationSendException) {
        }Http::assertNothingSent();
    }

    public function test_waits_for_all_books_then_one_immutable_preview_message(): void
    {
        $one = $this->book();
        $two = $this->book($one->order);
        $run = $this->enroll($one->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->twice()->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        $generations = PuzzleGeneration::orderBy('id')->get();
        app(PuzzleAutomation::class)->generate($generations[0]->id);
        app(ProcessRuntime::class)->advance($run->id);
        Http::assertNothingSent();
        $this->assertDatabaseCount('order_review_manifests', 0);
        app(PuzzleAutomation::class)->generate($generations[1]->id);
        $this->send($run);
        $manifest = OrderReviewManifest::sole();
        $this->assertCount(2, $manifest->images);
        Http::assertSentCount(2);
        $this->followingRedirects()->get(URL::temporarySignedRoute('order-process.review-preview', now()->addMinute(), ['manifest' => $manifest->id]))->assertOk()->assertSee('كتاب اختبار');
        $two->productionComponents()->where('production_stage', 'book_cover')->update(['prompt_template' => 'Changed cover']);
        $this->reply($run);
        $this->assertNull($manifest->fresh()->approved_at);
        $this->assertSame('blocked', $run->fresh()->status);
    }

    public function test_unified_review_preserves_old_links_snapshot_and_protects_every_image(): void
    {
        $item = $this->book();
        $run = $this->enroll($item->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        app(PuzzleAutomation::class)->generate(PuzzleGeneration::sole()->id);
        $this->send($run);
        $manifest = OrderReviewManifest::sole();
        $expires = now()->addMinutes(30);
        $legacy = URL::temporarySignedRoute('order-process.review-preview', $expires, ['manifest' => $manifest->id]);
        $canonical = URL::temporarySignedRoute('order-review.show', $expires, ['manifest' => $manifest->id]);
        $this->get($legacy)->assertRedirect($canonical);
        $gallery = OrderProductPreviewGallery::sole();
        $unified = URL::temporarySignedRoute('order-review.show', now()->addDays(35), ['manifest' => $manifest->id]);
        $this->assertSame($unified, $gallery->publicUrl());
        $galleryUrl = route('order-product-previews.show', ['token' => $gallery->plainPublicToken()]);
        $this->get($galleryUrl)->assertRedirect($unified);
        $this->get($canonical)->assertOk()->assertViewIs('front.order-product-previews.show')
            ->assertSee($item->order->checkoutReference->short_reference)
            ->assertSee('كتاب اختبار')->assertSee('bg-slate-50')->assertDontSee($manifest->images[0]['path']);
        $this->get(route('order-review.show', $manifest))->assertForbidden();
        $this->get(URL::temporarySignedRoute('order-review.show', now()->subMinute(), ['manifest' => $manifest->id]))->assertForbidden();
        $this->get(URL::temporarySignedRoute('order-process.review-preview', now()->subMinute(), ['manifest' => $manifest->id]))->assertForbidden();

        $original = $manifest->images[0];
        $snapshot = $manifest->images;
        // Identity images must use the same mandatory watermark path as product covers.
        $identity = $original;
        unset($identity['preview_id']);
        $identity['kind'] = 'identity';
        $identity['label'] = 'هوية طفل اختبار — قصة اختبار';
        $manifest->update(['images' => [$original, $identity]]);
        foreach (['order-process.review-image', 'order-review.image'] as $route) {
            foreach ([0, 1] as $index) {
                $url = URL::temporarySignedRoute($route, now()->addMinutes(15), ['manifest' => $manifest->id, 'index' => $index]);
                $this->withHeader('Sec-Fetch-Dest', 'document')->get($url)->assertNotFound();
                $image = $this->withHeader('Sec-Fetch-Dest', 'image')->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
                $this->assertNotSame(app(ProcessProductReview::class)->bytes($original), $image->getContent());
                $this->assertSame(app(OrderProductPreviewImageService::class)->protectedBytes(app(ProcessProductReview::class)->bytes($original)), $image->getContent());
            }
        }
        $this->assertNull($manifest->fresh()->approved_at);
        $manifest->update(['images' => $snapshot]);
        // Editing the order does not relabel or replace artwork in an already sent snapshot.
        $item->order->update(['child_name' => 'اسم مختلف']);
        $this->get($canonical)->assertOk()->assertDontSee('اسم مختلف');
        $this->assertSame($snapshot, $manifest->fresh()->images);
        // An extra manual preview must not disappear by redirecting to an older snapshot.
        app(OrderProductPreviewService::class)->upload($item->order,
            [UploadedFile::fake()->image('extra.jpg')], null, $this->actor);
        $this->get($galleryUrl)->assertOk()->assertSee('2 صورة معاينة');
        $this->get($canonical)->assertOk()->assertSee('1 صورة معاينة');
        $run->update(['status' => 'cancelled']);
        $this->get($canonical)->assertNotFound();
        $this->get($legacy)->assertNotFound();
    }

    public function test_cutover_keeps_old_response_processing_and_avoids_duplicate_ownership(): void
    {
        $item = $this->book();
        $run = $this->enroll($item->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        app(PuzzleAutomation::class)->generate(PuzzleGeneration::sole()->id);
        $this->send($run);
        $old = $run->automation;
        $d = ProcessDefinition::defaults('product_review');
        $next = OrderAutomation::create(['name' => 'Next', 'draft' => $d]);
        $revision = OrderAutomationRevision::create(['automation_id' => $next->id, 'definition' => $d, 'checksum' => hash('sha256', json_encode($d)), 'published_by' => $this->actor->id, 'created_at' => now()]);
        $next->update(['revision_id' => $revision->id]);
        app(ProcessEnrollment::class)->cutover($old, $next, $this->actor);
        $this->assertTrue($old->fresh()->enabled);
        $this->assertFalse($old->fresh()->accepts_new_runs);
        $this->reply($run);
        $this->assertNotNull(OrderReviewManifest::sole()->approved_at);
        $newItem = $this->book();
        $newRun = $this->enroll($newItem->order);
        $this->assertSame($next->id, $newRun->automation_id);
        DB::transaction(fn () => $item->order->update(['status' => 'new']));
        DB::transaction(fn () => $item->order->update(['status' => 'new_confirmed']));
        app(ProcessRuntime::class)->tick();
        $this->assertSame(1, OrderProcessRun::where('checkout_key', $run->checkout_key)->count());
    }

    public function test_book_a4_preserves_raw_output_and_records_native_resolution(): void
    {
        [$item,,$manifest] = $this->approvedBook();
        $generation = PuzzleGeneration::sole();
        $cover = getimagesizefromstring(Storage::disk('s3_private')->get($generation->output_path));
        $this->assertEqualsWithDelta(297 / 210, $cover[1] / $cover[0], .002);
        $this->assertSame($this->png, Storage::disk('s3_private')->get('order-attachments/'.$item->order_id.'/puzzle-'.$generation->id.'.png'));
        $item->order->updateQuietly(['payment_status' => 'partially_paid', 'paid_amount_cents' => 1]);
        $run = app(BookPageService::class)->start($item, $manifest);
        app(ManualSceneService::class)->tick();
        $data = json_decode(file_get_contents($this->spool.'/inbox/'.$run->id.'/manifest.json'), true);
        $this->assertSame('child_photo', $data['references'][0]['role']);
        $this->assertSame($this->png, file_get_contents($this->spool.'/inbox/'.$run->id.'/'.$data['references'][0]['file']));
        $this->pageOutput($run, 1);
        app(ManualSceneService::class)->collect($run);
        $scene = $run->fresh()->scenes[1];
        $this->assertLessThan(300, $scene['artwork']['effective_ppi']);
        $this->assertFalse($scene['artwork']['resampled']);
        $this->assertTrue($scene['artwork']['print_review_required']);
        $this->assertEqualsWithDelta(297 / 210, $scene['height'] / $scene['width'], .002);
    }

    public function test_finished_uncertain_cover_can_be_retried_then_returned_to_preview_flow(): void
    {
        $this->actor->permissions()->syncWithoutDetaching(Permission::whereIn('key', ['orders.production_prompt.manage', 'orders.automation.control', 'orders.view_all'])->pluck('id'));
        $item = $this->book();
        $run = $this->enroll($item->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andThrow(new \RuntimeException('uncertain'));
        $previous = PuzzleGeneration::sole();
        app(PuzzleAutomation::class)->generate($previous->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->actingAs($this->actor->fresh());
        $this->get(route('admin.orders.groups.show', $item->order))->assertOk()->assertSee('authorize_uncertain_retry', false)->assertSee('إعادة توليد الكفر');
        $this->post(route('admin.orders.groups.assignment.automation', $item->order), ['action' => 'take'])->assertSessionHasNoErrors();
        $this->post(route('admin.orders.products.regenerate', [$item->order, $item]),
            ['previous_run_id' => $previous->id, 'confirm' => 1, 'authorize_uncertain_retry' => 1])->assertSessionHasNoErrors();
        $new = PuzzleGeneration::latest('id')->first();
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()
            ->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic-recovered', [], []));
        app(PuzzleAutomation::class)->generate($new->id);
        $this->assertSame('succeeded', $new->fresh()->status);
        $this->post(route('admin.orders.groups.assignment.automation', $item->order), ['action' => 'resume'])->assertSessionHasNoErrors();
        $next = OrderProcessRun::latest('id')->first();
        $this->assertNotSame($run->id, $next->id);
        app(ProcessRuntime::class)->advance($next->id);
        $this->send($next);
        $this->assertSame($new->id, OrderReviewManifest::sole()->images[0]['generation_id']);
        $this->assertNull(OrderReviewManifest::sole()->approved_at);
        $this->assertDatabaseCount('puzzle_generations', 2);
        $this->assertDatabaseCount('order_attachments', 1);
        $this->assertDatabaseCount('codex_scene_runs', 0);
        $this->assertSame('unknown', $previous->fresh()->billing_status);
    }

    public function test_coloring_cover_can_regenerate_with_custom_prompt_without_reusing_old_approval(): void
    {
        [$item, $run] = $this->generatedPreview();
        $this->send($run);
        $this->actor->permissions()->syncWithoutDetaching(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.photos.view', 'orders.production_prompt.manage', 'orders.assign', 'orders.assignment.manage', 'orders.automation.control', 'orders.view_all'])->pluck('id'));
        $this->actingAs($this->actor->fresh());
        $this->post(route('admin.orders.groups.assignment.automation', $item->order), ['action' => 'take'])->assertSessionHasNoErrors();
        $manifest = OrderReviewManifest::sole();
        $oldImages = $manifest->images;
        $previous = PuzzleGeneration::sole();
        $this->get(route('admin.orders.groups.show', $item->order))->assertOk()->assertSee('تعديل برومبت إعادة التوليد');
        $this->post(route('admin.orders.products.regenerate', [$item->order, $item]), ['previous_run_id' => $previous->id, 'confirm' => 1,
            'prompt' => 'CUSTOM NEW COVER WITH RAINBOW AND ORIGINAL CHILD'])->assertSessionHasNoErrors();
        $new = PuzzleGeneration::latest('id')->first();
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->withArgs(function (GptImageRequest $request) {
            $this->assertStringContainsString('CUSTOM NEW COVER', $request->prompt);

            return true;
        })->andReturn(new GptImageResult($this->png(2), 'png', 'image/png', 'synthetic-new-cover', [], []));
        app(PuzzleAutomation::class)->generate($new->id);
        $this->assertSame('succeeded', $new->fresh()->status);
        $this->assertSame($oldImages, $manifest->fresh()->images);
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertNull($manifest->fresh()->approved_at);
        $this->assertSame(0, CodexSceneRun::count());
        $this->assertSame(2, OrderAttachment::count());
        $this->post(route('admin.orders.groups.assignment.automation', $item->order), ['action' => 'resume'])->assertSessionHasNoErrors();
        $next = OrderProcessRun::latest('id')->first();
        $this->assertNotSame($run->id, $next->id);
        app(ProcessRuntime::class)->advance($next->id);
        $this->send($next);
        $this->assertNull(OrderReviewManifest::latest('id')->first()->approved_at);
        $this->assertSame(2, PuzzleGeneration::count());
    }

    public function test_two_books_with_interior_first_keep_cover_button_and_resume_after_one_child_changes(): void
    {
        $this->actor->permissions()->syncWithoutDetaching(Permission::whereIn('key', ['orders.production_prompt.manage', 'orders.automation.control', 'orders.view_all'])->pluck('id'));
        $first = $this->book();
        $second = $this->book();
        $second->order->updateQuietly(['checkout_group_key' => $first->order->checkoutGroupKey(), 'status' => 'new_confirmed']);
        foreach ([$first, $second] as $item) {
            $item->productionComponents()->where('production_stage', 'book_interior')->update(['sort_order' => 0]);
            $item->productionComponents()->where('production_stage', 'book_cover')->update(['sort_order' => 10]);
        }
        $run = $this->enroll($first->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->twice()
            ->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        foreach (PuzzleGeneration::all() as $generation) {
            app(PuzzleAutomation::class)->generate($generation->id);
        }
        $retained = PuzzleGeneration::where('order_item_id', $first->id)->sole()->getAttributes();
        $previous = PuzzleGeneration::where('order_item_id', $second->id)->sole();
        $this->send($run);
        $manifest = OrderReviewManifest::sole()->getAttributes();
        $this->actingAs($this->actor->fresh());
        $this->post(route('admin.orders.groups.assignment.automation', $first->order), ['action' => 'take'])->assertSessionHasNoErrors();
        $second->order->updateQuietly(['child_name' => 'Different child', 'uploaded_photos' => ['child-photos/new-child.png']]);
        Storage::disk('s3_private')->put('child-photos/new-child.png', $this->png(3));
        $this->get(route('admin.orders.groups.show', $first->order))->assertOk()->assertSee('إعادة توليد الكفر')
            ->assertSee(route('admin.orders.products.regenerate', [$second->order, $second]), false);
        $this->post(route('admin.orders.products.regenerate', [$second->order, $second]), ['previous_run_id' => $previous->id, 'confirm' => 1,
            'prompt' => 'New colorful cover for Different child with exact current identity'])->assertSessionHasNoErrors();
        $new = PuzzleGeneration::latest('id')->first();
        $this->assertSame($second->id, $new->order_item_id);
        $this->assertSame('Different child', $new->context['child_identity']['child_name']);
        $this->assertSame('child-photos/new-child.png', $new->context['photos'][0]['path']);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()
            ->andReturn(new GptImageResult($this->png(4), 'png', 'image/png', 'synthetic-new', [], []));
        app(PuzzleAutomation::class)->generate($new->id);
        $this->assertSame('succeeded', $new->fresh()->status);
        $this->post(route('admin.orders.groups.assignment.automation', $first->order), ['action' => 'resume'])->assertSessionHasNoErrors();
        $next = OrderProcessRun::latest('id')->first();
        $this->assertNotSame($run->id, $next->id);
        app(ProcessRuntime::class)->advance($next->id);
        $this->assertSame('waiting_identity', $next->fresh()->status, $next->fresh()->error_code ?? '');
        $this->assertSame($retained, PuzzleGeneration::find($retained['id'])->getAttributes());
        $this->assertDatabaseCount('puzzle_generations', 3);
        $this->send($next);
        $this->assertSame($manifest, OrderReviewManifest::find($manifest['id'])->getAttributes());
        $this->assertNull(OrderReviewManifest::latest('id')->first()->approved_at);
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    public function test_staff_confirms_external_order_after_safe_pause_without_resending_or_approving_artwork(): void
    {
        $item = $this->book();
        $actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($actor);
        $url = route('admin.orders.workspace.control', $item->order);
        $this->get(route('admin.orders.groups.show', $item->order))->assertOk()->assertSee('تأكيد الطلب بواسطة الفريق واستكمال الأوتوميشن');
        $this->post($url, ['action' => 'pause', 'version' => 1, 'reason' => 'External confirmation'])->assertSessionHasNoErrors();
        $version = DB::table('order_automation_controls')->value('version');
        // Reproduce the team changing the displayed status while manually held.
        $item->order->updateQuietly(['status' => 'new_confirmed']);
        $this->post($url, ['action' => 'confirm', 'version' => $version])->assertSessionHasErrors('confirm');
        $payload = ['action' => 'confirm', 'version' => $version, 'confirm' => 1];
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $run = OrderProcessRun::sole();
        $this->assertSame('queued', $run->status);
        $this->assertSame('running', DB::table('order_automation_controls')->value('state'));
        $this->assertDatabaseCount('order_automation_holds', 0);
        $this->assertDatabaseCount('order_review_manifests', 0);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $this->assertDatabaseCount('codex_scene_runs', 0);
        $this->assertSame(0, $item->order->fresh()->paid_amount_cents);
        $this->assertSame($actor->id, AdminActivityLog::where('action', 'order.confirmed_by_staff')->sole()->user_id);
        $this->post($url, $payload)->assertStatus(409);
        $this->assertDatabaseCount('order_process_runs', 1);
        Http::assertNothingSent();
    }

    public function test_staff_order_confirmation_invalid_photos_roll_back_status_hold_and_admission(): void
    {
        $item = $this->book();
        $actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($actor);
        $item->order->updateQuietly(['uploaded_photos' => ['missing-child.png']]);
        $this->post(route('admin.orders.workspace.control', $item->order), ['action' => 'confirm', 'version' => 1, 'confirm' => 1])->assertSessionHasErrors('automation');
        $this->assertSame('new', $item->order->fresh()->status);
        $this->assertDatabaseCount('order_process_runs', 0);
        $this->assertDatabaseCount('order_automation_controls', 0);
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
    }

    private function replacementCover(): array
    {
        [$item, $run] = $this->generatedPreview();
        $this->send($run);
        $generation = PuzzleGeneration::sole();
        $old = $generation->getRawOriginal();
        OrderPreview::findOrFail($generation->preview_id)->delete();
        $run->refresh()->update(['status' => 'blocked', 'error_code' => 'preview_image_unavailable']);
        $actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($actor);
        app(OrderProductPreviewService::class)->upload($item->order->fresh(), [UploadedFile::fake()->image('replacement-cover.png', 400, 600)], null, $actor);
        $preview = OrderPreview::latest('id')->first();
        $this->assertNull($preview->production_unit_key);

        return [$item->fresh(), $run->fresh(), $preview, $old];
    }

    public function test_manual_replacement_uses_new_cover_and_current_child_data_then_starts_paid_pages_once(): void
    {
        [$item, $run, $preview, $oldGeneration] = $this->replacementCover();
        $item->order->updateQuietly(['child_name' => 'New child', 'parent_notes' => 'New accepted instructions', 'paid_amount_cents' => 30000, 'payment_status' => 'partially_paid']);
        $item = $item->fresh();
        $service = app(ManualBookCoverApproval::class);
        $manifest = OrderReviewManifest::sole()->getRawOriginal();
        Http::fake();
        $this->get(route('admin.orders.groups.show', $item->order))->assertOk()->assertSee('اختيار واعتماد الكفر البديل واستكمال الكتاب');
        $this->get(route('admin.orders.workspace.legacy-book', [$item->order, $item, 'replacement' => 1]))->assertOk()->assertSee('replacement-cover.png')->assertSee('New child');
        $offer = collect($service->offers($item))->firstWhere('preview.id', $preview->id);
        $payload = ['replacement' => 1, 'confirm' => 1, 'preview_token' => $offer['token']];
        $url = route('admin.orders.workspace.legacy-book-approve', [$item->order, $item]);
        $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $pages = CodexSceneRun::sole();
        $approval = LegacyBookCoverApproval::sole();
        $this->assertSame('waiting_slot', $pages->status);
        $this->assertSame($preview->file_path, $pages->snapshot['identity_path']);
        $this->assertSame($approval->id, $pages->snapshot['legacy_approval_id']);
        $this->assertSame('New child', $pages->snapshot['child']['child_name']);
        $this->assertSame('waiting_staff', $run->fresh()->status);
        $this->assertNotNull($preview->fresh()->production_unit_key);
        $this->assertSame($oldGeneration, PuzzleGeneration::sole()->getRawOriginal());
        $this->assertSame($manifest, OrderReviewManifest::sole()->getRawOriginal());
        $this->assertSame(30000, $item->order->fresh()->paid_amount_cents);
        $this->assertSame($pages->fingerprint, app(BookPageService::class)->fingerprint($pages));
        $this->post($url, $payload)->assertSessionHasErrors('legacy_book');
        $this->assertDatabaseCount('codex_scene_runs', 1);
        $this->assertDatabaseCount('legacy_book_cover_approvals', 1);
        Http::assertNothingSent();
    }

    public function test_manual_replacement_requires_explicit_selection_and_rejects_changed_images_or_child(): void
    {
        [$item, $run, $preview] = $this->replacementCover();
        Http::fake();
        $service = app(ManualBookCoverApproval::class);
        $offer = $service->offers($item)[0];
        $url = route('admin.orders.workspace.legacy-book-approve', [$item->order, $item]);
        $payload = ['replacement' => 1, 'confirm' => 1, 'preview_token' => $offer['token']];
        $this->post($url, array_diff_key($payload, ['confirm' => true]))->assertSessionHasErrors('confirm');
        $item->order->updateQuietly(['child_name' => 'Edited after selection']);
        $this->post($url, $payload)->assertStatus(409);
        $this->assertDatabaseCount('legacy_book_cover_approvals', 0);
        $item = $item->fresh();
        $payload['preview_token'] = $service->offers($item)[0]['token'];
        Storage::disk($preview->disk)->put($preview->file_path, $this->png(3));
        $this->post($url, $payload)->assertSessionHasErrors('legacy_book');
        $this->assertDatabaseCount('legacy_book_cover_approvals', 0);
        $this->assertNull($preview->fresh()->production_unit_key);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertDatabaseCount('codex_scene_runs', 0);
        Http::assertNothingSent();
    }

    public function test_manual_replacement_safe_paused_unpaid_order_records_consent_without_starting_pages(): void
    {
        [$item, $run, $preview] = $this->replacementCover();
        Http::fake();
        $controlUrl = route('admin.orders.workspace.control', $item->order);
        $version = DB::table('order_automation_controls')->value('version');
        $this->post($controlUrl, ['action' => 'pause', 'version' => $version, 'reason' => 'Replace cover'])->assertSessionHasNoErrors();
        $service = app(ManualBookCoverApproval::class);
        $offer = $service->offers($item->fresh())[0];
        $this->post(route('admin.orders.workspace.legacy-book-approve', [$item->order, $item]), ['replacement' => 1, 'confirm' => 1, 'preview_token' => $offer['token']])->assertSessionHasNoErrors()->assertSessionHas('success', fn ($value) => str_contains($value, 'الصفحات لم تبدأ'));
        $this->assertDatabaseCount('legacy_book_cover_approvals', 1);
        $this->assertDatabaseCount('codex_scene_runs', 0);
        $this->assertDatabaseCount('order_automation_holds', 0);
        $this->assertSame('running', DB::table('order_automation_controls')->value('state'));
        $this->assertStringContainsString('دفعة', app(BookPageService::class)->admissionReason($item->fresh()));
        Http::assertNothingSent();
    }

    public function test_manual_cover_receipt_is_revoked_by_later_child_change_and_cannot_bind_another_book(): void
    {
        [$item, $run, $preview] = $this->replacementCover();
        Http::fake();
        $service = app(ManualBookCoverApproval::class);
        $actor = auth()->user();
        $service->approve($item, $actor, Request::create('/'), $service->offers($item)[0]['token']);
        $this->assertSame($preview->id, app(LegacyBookAdmission::class)->approvedInputs($item)['preview_id']);
        $other = $this->book($item->order->fresh());
        $offers = $service->offers($other);
        $this->assertArrayNotHasKey('token', $offers[0]);
        $this->assertArrayHasKey('error', $offers[0]);
        $item->order->updateQuietly(['parent_notes' => 'Changed after explicit approval']);
        try {
            app(LegacyBookAdmission::class)->approvedInputs($item->fresh());
            $this->fail('Changed current inputs must require new consent.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Book inputs changed after approval', $e->getMessage());
        }
        $this->assertDatabaseCount('codex_scene_runs', 0);
        Http::assertNothingSent();
    }

    public function test_manual_cover_rejects_unconfirmed_stop_and_unsolved_provider_result(): void
    {
        [$item, $run, $preview] = $this->replacementCover();
        Http::fake();
        $service = app(ManualBookCoverApproval::class);
        $payload = ['replacement' => 1, 'confirm' => 1, 'preview_token' => $service->offers($item)[0]['token']];
        $url = route('admin.orders.workspace.legacy-book-approve', [$item->order, $item]);
        DB::table('order_automation_controls')->update(['state' => 'pause_uncertain']);
        $this->post($url, $payload)->assertSessionHasErrors('legacy_book');
        DB::table('order_automation_controls')->update(['state' => 'running']);
        PuzzleGeneration::sole()->update(['status' => 'uncertain']);
        $this->post($url, $payload)->assertSessionHasErrors('legacy_book');
        $this->assertDatabaseCount('legacy_book_cover_approvals', 0);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertNull($preview->fresh()->production_unit_key);
        Http::assertNothingSent();
    }

    public function test_manual_cover_cannot_change_the_purchased_page_count(): void
    {
        [$item] = $this->replacementCover();
        $variant = $item->product->variants()->create(['name_ar' => '40 pages', 'book_page_count' => 40, 'is_active' => true]);
        $item->update(['product_variant_id' => $variant->id, 'variant_snapshot' => ['id' => $variant->id, 'book_page_count' => 40]]);
        $this->get(route('admin.orders.workspace.legacy-book', [$item->order, $item, 'replacement' => 1]))->assertOk()->assertSee('راجع الاختيار المشترى وعدد صفحات الكتاب');
        $this->assertDatabaseCount('legacy_book_cover_approvals', 0);
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    private function generatedPreview(): array
    {
        $item = $this->book();
        $run = $this->enroll($item->order);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()
            ->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        app(PuzzleAutomation::class)->generate(PuzzleGeneration::sole()->id);
        $this->assertSame('new_confirmed', $item->order->fresh()->status);

        return [$item, $run];
    }

    public function test_accepted_preview_reconciles_old_status_once_without_resending(): void
    {
        [$item, $run] = $this->generatedPreview();
        $this->send($run);
        $manifest = OrderReviewManifest::sole()->getRawOriginal();
        // Reproduce a pre-fix run with a durable receipt and its old status snapshot.
        $item->order->fresh()->updateQuietly(['status' => 'new_confirmed']);
        $contexts = app(ProcessOrderContext::class);
        $run->refresh()->update(['context' => $contexts->snapshot($contexts->orders($run->checkout_key)) + ['actor_id' => $this->actor->id]]);
        app(ProcessPreviewStatus::class)->synchronize($run->id);
        $logCount = $item->order->statusLogs()->count();
        app(ProcessPreviewStatus::class)->synchronize($run->id);
        $this->assertSame('preview_uploaded', $item->order->fresh()->status);
        $this->assertSame($logCount, $item->order->statusLogs()->count());
        $this->assertSame($manifest, OrderReviewManifest::sole()->getRawOriginal());
        app(ProcessRuntime::class)->guard($run->fresh());
        $this->assertDatabaseHas('admin_activity_logs', ['subject_id' => $item->order_id, 'action' => 'automation.preview_waiting_approval', 'user_id' => null]);
        $activity = AdminActivityLog::where('action', 'automation.preview_waiting_approval')->latest('id')->first();
        $this->assertSame('Automation', $activity->properties['actor_name']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('puzzle_generations', 1);
    }

    public function test_uncertain_preview_send_does_not_change_order_status(): void
    {
        [$item, $run] = $this->generatedPreview();
        $this->mock(OrderConfirmationTransport::class)->shouldReceive('sendTemplate')->once()->andThrow(new \RuntimeException('unknown provider result'));
        foreach (range(1, 3) as $_) {
            app(ProcessRuntime::class)->advance($run->id);
        }
        $this->assertSame('uncertain', $run->fresh()->status);
        $this->assertSame('new_confirmed', $item->order->fresh()->status);
        $this->assertDatabaseMissing('admin_activity_logs', ['action' => 'automation.preview_waiting_approval']);
    }

    public function test_late_preview_receipt_preserves_manual_takeover(): void
    {
        [$item, $run] = $this->generatedPreview();
        $this->mock(OrderConfirmationTransport::class)->shouldReceive('sendTemplate')->once()->andReturnUsing(function () use ($run, $item) {
            $run->update(['status' => 'paused']);
            $item->order->updateQuietly(['status' => 'under_review']);

            return ['message_id' => 'wamid.review', 'interaction_id' => 'review-interaction'];
        });
        foreach (range(1, 3) as $_) {
            app(ProcessRuntime::class)->advance($run->id);
        }
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertSame('under_review', $item->order->fresh()->status);
        $this->assertSame('completed', $run->steps()->where('type', 'whatsapp')->sole()->status);
    }

    public function test_status_projection_failure_cannot_erase_an_accepted_send(): void
    {
        [$item, $run] = $this->generatedPreview();
        $this->mock(OrderConfirmationTransport::class)->shouldReceive('sendTemplate')->once()->andReturnUsing(function () {
            $this->mock(ProcessPreviewStatus::class)->shouldReceive('synchronize')->once()->andThrow(new \RuntimeException('synthetic projection failure'));

            return ['message_id' => 'wamid.review', 'interaction_id' => 'review-interaction'];
        });
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $step = $run->steps()->where('type', 'whatsapp')->sole();
        $this->assertSame('completed', $step->status);
        $this->assertSame('completed', $step->messageAttempts()->sole()->status);
        $this->assertSame('wamid.review', $step->receipt['message_id']);
        $this->assertSame('new_confirmed', $item->order->fresh()->status);
        $this->app->forgetInstance(ProcessPreviewStatus::class);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_response', $run->fresh()->status);
        $this->assertSame('preview_uploaded', $item->order->fresh()->status);
    }

    public function test_preview_status_reconciliation_rejects_changed_customer_inputs(): void
    {
        [$item, $run] = $this->generatedPreview();
        $this->send($run);
        $item->order->fresh()->updateQuietly(['status' => 'new_confirmed', 'child_name' => 'Edited child']);
        try {
            app(ProcessPreviewStatus::class)->synchronize($run->id);
            $this->fail('Changed inputs must be rejected');
        } catch (\RuntimeException $error) {
            $this->assertSame('inputs_changed', $error->getMessage());
        }
        $this->assertSame('new_confirmed', $item->order->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_cover_v2_is_snapshotted_without_changing_old_cover_contracts(): void
    {
        $old = $this->book();
        $old->order->updateQuietly(['status' => 'new_confirmed']);
        $before = app(PuzzleInputs::class)->capture($old);
        $this->assertSame('a4-cover-v1', $before['artwork_contract']);
        $fresh = $this->book();
        $fresh->productionComponents()->where('production_stage', 'book_cover')->update(['prompt_template' => ProductProductionStage::coverPrompt()]);
        $fresh->unsetRelation('productionComponents');
        $this->enroll($fresh->order);
        $generation = PuzzleGeneration::sole();
        $this->assertSame('a4-cover-v2', $generation->context['artwork_contract']);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic', [], []));
        app(PuzzleAutomation::class)->generate($generation->id);
        $generation->refresh();
        $this->assertSame('succeeded', $generation->status);
        $size = getimagesizefromstring(Storage::disk('s3_private')->get($generation->output_path));
        $this->assertSame([1024, 1448], array_slice($size, 0, 2));
        $this->assertSame($before['fingerprint'], app(PuzzleInputs::class)->capture($old)['fingerprint']);
    }

    public function test_cover_design_upgrade_only_changes_managed_catalog_not_order_snapshots(): void
    {
        $item = $this->book();
        $product = $item->product;
        $component = $product->productionComponents()->create(['stable_key' => 'automation-cover-v1', 'name' => 'Cover',
            'production_stage' => 'book_cover', 'quantity_per_item' => 1, 'is_active' => true,
            'prompt_template' => ProductProductionStage::legacyCoverPrompt()]);
        $snapshots = $item->productionComponents()->get()->toArray();
        $this->artisan('books:cover-design', ['products' => [$product->id]])->assertSuccessful();
        $this->assertSame(ProductProductionStage::legacyCoverPrompt(), $component->fresh()->prompt_template);
        $this->artisan('books:cover-design', ['products' => [$product->id], '--apply' => true])->assertSuccessful();
        $this->assertSame(ProductProductionStage::coverPrompt(), $component->fresh()->prompt_template);
        $this->assertSame($snapshots, $item->productionComponents()->get()->toArray());
        $this->artisan('books:cover-design', ['products' => [$product->id], '--apply' => true])->assertSuccessful();
        $this->assertSame($snapshots, $item->productionComponents()->get()->toArray());
    }

    public function test_catalog_preserves_old_briefs_and_snapshots_exact_variant_page_counts(): void
    {
        $book = $this->book();
        $product = $book->product;
        $product->update(['name_ar' => 'كتاب تلوين تجريبي']);
        $old = $product->productionComponents()->create(['stable_key' => 'legacy', 'name' => 'Old', 'prompt_template' => 'Historical brief', 'quantity_per_item' => 1, 'is_active' => true]);
        $variants = [];
        foreach ([20, 40, 50] as $count) {
            $variant = $product->variants()->create(['name_ar' => 'كتاب '.$count.' صفحة', 'is_active' => true]);
            $variants[$variant->id] = $count;
        }
        app(BookCatalog::class)->configure($product, 20, $variants);
        $this->assertSame('Historical brief', $old->fresh()->prompt_template);
        $this->assertFalse($old->fresh()->is_active);
        foreach ($variants as $id => $count) {
            $item = $book->order->items()->create(['product_id' => $product->id, 'product_variant_id' => $id, 'variant_snapshot' => ['book_page_count' => $count],
                'item_type' => 'product', 'title' => 'Synthetic', 'quantity' => 1, 'unit_price_cents' => 100, 'total_price_cents' => 100]);
            $this->assertCount(2, $item->productionComponents);
            $this->assertSame($count, $item->productionComponents->firstWhere('production_stage', 'book_interior')->quantity_per_item);
            $this->assertStringContainsString((string) $count, ProductProductionPrompt::forItem($item, true)->firstWhere('production_stage', 'book_interior')['prompt']);
        }
    }

    public function test_a3_variant_is_not_silently_generated_as_a4(): void
    {
        $item = $this->book();
        $item->update(['variant_snapshot' => ['name_ar' => '50 صفحة A3', 'book_page_count' => 50]]);
        $item->order->update(['status' => 'new_confirmed']);
        $this->expectExceptionMessage('puzzle_book_variant_requires_review');
        app(PuzzleInputs::class)->capture($item);
    }

    public function test_wrong_child_story_link_blocks_book_pages(): void
    {
        [$item,,$manifest] = $this->approvedBook();
        $other = $this->book();
        $other->order->updateQuietly(['child_name' => 'Other child']);
        $linked = $other->order->items()->create(['item_type' => 'story', 'title' => 'Other story', 'quantity' => 1, 'unit_price_cents' => 100, 'total_price_cents' => 100]);
        $item->linked_order_item_id = $linked->id;
        $this->expectExceptionMessage('book_story_link_ambiguous');
        app(BookReferences::class)->capture($item, [], 20);
    }

    public function test_confirmed_cohort_converts_only_unstarted_book_and_keeps_history(): void
    {
        $item = $this->book();
        $item->productionComponents()->delete();
        $item->product->update(['name_ar' => 'كتاب تلوين']);
        $old = $item->product->productionComponents()->create(['stable_key' => 'legacy', 'name' => 'Old', 'prompt_template' => 'Original brief', 'quantity_per_item' => 1, 'is_active' => true]);
        $snapshot = $item->productionComponents()->create(['stable_key' => 'legacy', 'name' => 'Old', 'prompt_template' => 'Original brief', 'quantity_per_item' => 1]);
        app(BookCatalog::class)->configure($item->product, 40, []);
        $item->order->fresh()->updateQuietly(['status' => 'new_confirmed']);
        $flow = OrderAutomation::where('name', 'Synthetic all previews')->sole();
        $cohort = app(PreviewCohort::class);
        $before = DB::table('order_item_production_components')->count();
        $cohort->inspect($item->order->checkoutGroupKey(), $flow);
        $this->assertSame($before, DB::table('order_item_production_components')->count());
        $this->assertNull($snapshot->fresh()->retired_at);
        $run = $cohort->enroll($item->order->checkoutGroupKey(), $flow);
        $this->assertNotNull($snapshot->fresh()->retired_at);
        $this->assertSame('Original brief', $snapshot->fresh()->prompt_template);
        $this->assertCount(2, $item->fresh()->productionComponents);
        $this->assertSame(40, $item->fresh()->productionComponents->firstWhere('production_stage', 'book_interior')->quantity_per_item);
        $this->assertSame('queued', $run->status);
        $this->assertSame(0, $item->order->fresh()->paid_amount_cents);
        Http::assertNothingSent();
        $this->expectExceptionMessage('existing_workflow_or_manual_hold');
        $cohort->enroll($item->order->checkoutGroupKey(), $flow);
    }

    public function test_missing_variant_pages_are_repaired_only_during_explicit_unstarted_enrollment(): void
    {
        $items = collect([$this->book(), $this->book()]);
        $key = $items->first()->order->checkoutGroupKey();
        foreach ($items as $item) {
            $variant = $item->product->variants()->create(['name_ar' => 'كتاب 20 صفحة', 'book_page_count' => 20, 'is_active' => true]);
            $item->update(['product_variant_id' => $variant->id, 'variant_snapshot' => ['id' => $variant->id, 'name_ar' => $variant->name_ar]]);
            $item->productionComponents()->where('production_stage', 'book_interior')->update(['quantity_per_item' => 1]);
            $item->order->updateQuietly(['checkout_group_key' => $key, 'status' => 'new_confirmed']);
        }
        $flow = OrderAutomation::where('name', 'Synthetic all previews')->sole();
        app(PreviewCohort::class)->inspect($key, $flow);
        foreach ($items as $item) {
            $this->assertNull(data_get($item->fresh()->variant_snapshot, 'book_page_count'));
        }
        $run = app(PreviewCohort::class)->enroll($key, $flow);
        $this->assertSame('queued', $run->status);
        foreach ($items as $item) {
            $this->assertSame(20, $item->fresh()->variant_snapshot['book_page_count']);
            $this->assertSame(20, $item->fresh()->productionComponents->firstWhere('production_stage', 'book_interior')->quantity_per_item);
            $this->assertSame(0, $item->order->fresh()->paid_amount_cents);
        }
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
    }

    public function test_variant_repair_refuses_existing_output_and_mismatched_catalog_selection(): void
    {
        $item = $this->book();
        $variant = $item->product->variants()->create(['name_ar' => 'كتاب 40 صفحة', 'book_page_count' => 40, 'is_active' => true]);
        $item->update(['product_variant_id' => $variant->id, 'variant_snapshot' => ['id' => $variant->id, 'name_ar' => 'كتاب 20 صفحة']]);
        $repair = app(BookVariantSnapshot::class);
        try {
            $repair->prepare($item->fresh());
            $this->fail('Mismatched purchased variant must not be repaired');
        } catch (\RuntimeException $e) {
            $this->assertSame('book_variant_requires_review', $e->getMessage());
        }
        $item->update(['variant_snapshot' => ['id' => $variant->id, 'name_ar' => $variant->name_ar]]);
        app(OrderProductPreviewService::class)->upload($item->order, [UploadedFile::fake()->image('old.png')], null, $this->actor);
        $this->expectExceptionMessage('existing_book_output_or_attempt');
        $repair->persist($item->fresh());
    }
}
