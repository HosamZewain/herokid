<?php

namespace Tests\Feature;

use App\DTOs\Ai\GptImageRequest;
use App\DTOs\Ai\GptImageResult;
use App\Models\AdminActivityLog;
use App\Models\AiProvider;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRevision;
use App\Models\OrderItem;
use App\Models\OrderPreviewStaffApproval;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\OrderProcessStep;
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
use App\Services\Orders\OrderAutomationSummary;
use App\Services\Orders\ProductProductionPromptSyncService;
use App\Services\Orders\StaffPreviewInputs;
use App\Services\ProductAutomation\BookCatalog;
use App\Services\ProductAutomation\PuzzleAutomation;
use App\Services\ProductAutomation\PuzzleInputs;
use App\Services\RoboDesk\OrderConfirmationSendException;
use App\Services\RoboDesk\OrderConfirmationTransport;
use App\Support\ProductProductionPrompt;
use App\Support\ProductProductionStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
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
            'confirm' => 1, 'channel' => 'phone', 'note' => 'Synthetic customer phone consent'];
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

    public function test_staff_approval_does_not_record_payment_or_generate_unpaid_pages(): void
    {
        [$item, $run, $manifest, $admin] = $this->staffApprovalFixture();
        $this->actingAs($admin)->get(route('admin.orders.groups.show', $item->order))->assertOk()
            ->assertSee('تسجيل موافقة العميل بواسطة الفريق')->assertDontSee('مراجعة الدفع — مرفقات العميل');
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
        $this->post(route('admin.orders.workspace.preview-approval', $item->order), array_replace($payload, ['note' => '']))->assertSessionHasErrors('note');
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
        $this->get(URL::temporarySignedRoute('order-process.review-preview', now()->addMinute(), ['manifest' => $manifest->id]))->assertOk()->assertSee('كتاب اختبار');
        $two->productionComponents()->where('production_stage', 'book_cover')->update(['prompt_template' => 'Changed cover']);
        $this->reply($run);
        $this->assertNull($manifest->fresh()->approved_at);
        $this->assertSame('blocked', $run->fresh()->status);
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
}
