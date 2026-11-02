<?php

namespace Tests\Feature;

use App\DTOs\Ai\GptImageRequest;
use App\DTOs\Ai\GptImageResult;
use App\Models\AiProvider;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRevision;
use App\Models\OrderItem;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Models\OrderStatusDefinition;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskConversationSetting;
use App\Models\Story;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\GptImageClient;
use App\Services\CodexScenes\BookPageService;
use App\Services\CodexScenes\ManualSceneService;
use App\Services\OrderAutomation\Process\ProcessDefinition;
use App\Services\OrderAutomation\Process\ProcessIdentityService;
use App\Services\OrderAutomation\Process\ProcessPaymentReview;
use App\Services\OrderAutomation\Process\ProcessResponsePoller;
use App\Services\OrderAutomation\Process\ProcessRuntime;
use App\Services\OrderAutomation\Process\ProcessTemplates;
use App\Services\ProductAutomation\PuzzleAutomation;
use App\Services\RoboDesk\OrderConfirmationSendException;
use App\Services\RoboDesk\OrderConfirmationTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductReviewRuntimeTest extends TestCase
{
    use RefreshDatabase;

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
        file_put_contents($this->spool.'/outbox/health.json', json_encode(['authenticated' => true, 'supported_protocols' => [4, 5]]));
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
        $conversation = $this->reply($run);
        $this->assertSame('waiting_staff', $run->fresh()->status, $run->fresh()->error_code ?? '');
        $manifest = OrderReviewManifest::sole();
        $this->assertNotNull($manifest->approved_at);

        return [$item, $run, $manifest, $conversation];
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
        $this->assertSame(5, $run->protocol);
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
        $this->assertSame('ready_preview', $item->order->fresh()->status);
        $this->assertCount(3, $run->fresh()->scenes);
        foreach ($run->fresh()->scenes as $scene) {
            $a = OrderAttachment::find($scene['attachment_id']);
            $this->assertSame('s3_private', $a->disk);
            $this->assertSame('product:'.$item->id.':component:pages', $a->production_unit_key);
            $this->assertSame($scene['sha256'], hash('sha256', Storage::disk('s3_private')->get($a->path)));
        }
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
        $this->assertCount(2, OrderReviewManifest::sole()->images);
        $this->reply($run);
        $this->assertNotNull(OrderReviewManifest::sole()->approved_at);
        $this->assertNotNull($storyOrder->fresh()->child_identity_approved_attempt_id);
        $this->assertSame('costomer_confirmed', $storyOrder->fresh()->status);
        $this->assertSame('new_confirmed', $item->order->fresh()->status);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('order_process_responses', 1);
    }

    public function test_old_worker_and_manual_hold_block_book_admission(): void
    {
        [$item,,$manifest] = $this->approvedBook();
        $item->order->updateQuietly(['payment_status' => 'partially_paid', 'paid_amount_cents' => 1]);
        file_put_contents($this->spool.'/outbox/health.json', '{"authenticated":true}');
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
        file_put_contents($this->spool.'/outbox/health.json', '{"authenticated":true,"supported_protocols":[5]}');
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
        $this->get(URL::temporarySignedRoute('order-process.review-preview', now()->addMinute(), ['manifest' => $manifest->id]))->assertOk()->assertSee('كتاب اختبار');
        $two->productionComponents()->where('production_stage', 'book_cover')->update(['prompt_template' => 'Changed cover']);
        $this->reply($run);
        $this->assertNull($manifest->fresh()->approved_at);
        $this->assertSame('blocked', $run->fresh()->status);
    }
}
