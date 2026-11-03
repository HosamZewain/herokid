<?php

namespace Tests\Feature;

use App\DTOs\Ai\GptImageRequest;
use App\DTOs\Ai\GptImageResult;
use App\Exceptions\GptImageException;
use App\Jobs\GenerateProcessIdentity;
use App\Models\AdminActivityLog;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRevision;
use App\Models\OrderAutomationRun;
use App\Models\OrderIdentityRegeneration;
use App\Models\OrderPaymentReview;
use App\Models\OrderProcessEvent;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\OrderStatusDefinition;
use App\Models\Permission;
use App\Models\Product;
use App\Models\RoboDeskConversationSetting;
use App\Models\Story;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\GptImageClient;
use App\Services\Cart\CartTrackingService;
use App\Services\ChildIdentity\ChildIdentityAttemptService;
use App\Services\OrderAutomation\Process\ProcessDefinition;
use App\Services\OrderAutomation\Process\ProcessIdentityPreflight;
use App\Services\OrderAutomation\Process\ProcessIdentityService;
use App\Services\OrderAutomation\Process\ProcessOrderContext;
use App\Services\OrderAutomation\Process\ProcessPaymentReview;
use App\Services\OrderAutomation\Process\ProcessResponsePoller;
use App\Services\OrderAutomation\Process\ProcessRuntime;
use App\Services\OrderAutomation\Process\ProcessSimulator;
use App\Services\OrderAutomation\Process\ProcessTemplates;
use App\Services\OrderAutomation\WorkflowInputs;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\OrderIdentityRegenerationService;
use App\Services\Orders\OrderPaymentService;
use App\Services\Orders\OrderReviewLinks;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderProcessIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private OrderAutomation $workflow;

    private Story $story;

    private string $png;

    private bool $sendUncertain = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->utc()->startOfDay());
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Storage::fake('local');
        Storage::fake('public');
        config(['media.private_disk' => 'local', 'order_process.enabled' => true, 'order_process.after_order_id' => 0,
            'order_process.confirmation_owner' => 'legacy', 'order_process.identity_enabled' => true,
            'order_process.identity_template_ready' => true, 'order_process.identity_budget_usd' => 1,
            'robodesk.enabled' => false, 'robodesk.confirmation.enabled' => false]);
        $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actor->permissions()->sync(Permission::whereIn('key', ['order_automation.view', 'order_automation.manage', 'order_automation.activate', 'order_automation.execute', 'orders.view', 'orders.update', 'orders.photos.view'])->pluck('id'));
        $this->story = Story::create(['title' => 'قصة اختبار', 'slug' => 'identity-process-test', 'language' => 'ar', 'gender' => 'both', 'price' => 100, 'active' => true]);
        ob_start();
        imagepng(imagecreatetruecolor(2, 2));
        $this->png = ob_get_clean();
        Storage::disk('local')->put('child-photos/identity.png', $this->png);
        $provider = AiProvider::firstOrCreate(['driver' => 'openai'], ['name' => 'OpenAI', 'display_name' => 'OpenAI']);
        $provider->update(['is_active' => true]);
        $model = $provider->models()->firstOrCreate(['code' => 'gpt-image-2'], ['display_name' => 'Synthetic model']);
        $model->update(['is_active' => true, 'generation_capabilities_json' => ['character_sheet'], 'estimated_cost_amount' => '0.1', 'estimated_cost_currency' => 'USD']);
        app(AiProviderCredentialService::class)->save($provider, 'synthetic-not-a-real-key');
        OrderStatusDefinition::create(['type' => 'order', 'key' => ProcessIdentityService::APPROVED_ORDER_STATUS, 'label_ar' => 'هوية مؤكدة وجاهزة للتوليد', 'color' => 'teal', 'behavior' => 'standard', 'is_active' => true]);
        $d = app(ProcessDefinition::class)->validate(ProcessDefinition::defaults('identity'));
        $this->workflow = OrderAutomation::create(['name' => 'مسار هوية وهمي', 'draft' => $d]);
        $r = OrderAutomationRevision::create(['automation_id' => $this->workflow->id, 'definition' => $d, 'checksum' => hash('sha256', json_encode($d)), 'published_by' => $this->actor->id, 'created_at' => now()]);
        $this->workflow->update(['revision_id' => $r->id]);
        app(ProcessRuntime::class)->activate($this->workflow, $this->actor, true);
        RoboDeskConversationSetting::create(['id' => 1, 'enabled' => true, 'email' => 'synthetic@example.test', 'password' => 'test-only']);
        Http::fake(['https://hero-kid.robodesk.ai/*' => fn ($request) => str_contains($request->url(), 'messagesByPhone')
            ? Http::response(['phone' => '201000000001', 'interactions' => [], 'messages' => []])
            : ($this->sendUncertain ? Http::response('unknown', 500) : Http::response(['_id' => 'identity-interaction', 'channelId' => 'wamid.identity']))]);
    }

    private function enroll(int $count = 1): OrderProcessRun
    {
        $key = 'SYNTHETIC-'.Str::uuid();
        DB::transaction(function () use ($count, $key) {
            for ($i = 0; $i < $count; $i++) {
                $order = Order::create(['order_number' => 'TEST-'.Str::random(8), 'checkout_group_key' => $key, 'story_id' => $this->story->id, 'parent_name' => 'عميل اختبار',
                    'child_name' => 'طفل '.$i, 'child_age' => 5, 'child_gender' => 'boy', 'status' => 'new', 'uploaded_photos' => ['child-photos/identity.png'],
                    'delivery_details' => ['checkout_group' => $key, 'phone' => '01000000001', 'delivery_fee' => 50]]);
                $order->items()->create(['item_type' => 'story', 'story_id' => $this->story->id, 'title' => 'قصة اختبار', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
                $order->update(['status' => 'new_confirmed']);
            }
        });
        app(ProcessRuntime::class)->tick();

        return OrderProcessRun::where('checkout_key', $key)->firstOrFail();
    }

    private function regenerationOrder(): Order
    {
        $run = $this->generated();
        $this->actor->permissions()->syncWithoutDetaching(Permission::whereIn('key', ['orders.production_prompt.manage', 'orders.assignment.manage', 'orders.assign'])->pluck('id'));
        $this->actingAs($this->actor);
        $order = $run->order->fresh();
        $this->post(route('admin.orders.groups.assignment.automation', $order), ['action' => 'take'])->assertSessionHasNoErrors();

        return $order;
    }

    public function test_pending_identity_is_visible_in_order_and_has_independent_attachment(): void
    {
        $order = $this->regenerationOrder();
        $attempt = $order->childIdentityRequest->attempts()->sole();
        $this->assertNull($order->child_identity_approved_attempt_id);
        $attachment = $order->attachments()->where('production_unit_key', 'identity:'.$attempt->id)->sole();
        $this->assertNotSame($attempt->output_storage_path, $attachment->path);
        $this->get(route('admin.orders.groups.show', $order))->assertOk()->assertSee('غير معتمدة')
            ->assertSee(route('admin.orders.identity-attempts.image', [$order, $attempt]), false);
        $this->get(route('admin.orders.identity-attempts.image', [$order, $attempt]))->assertOk()->assertStreamedContent($this->png);
        $attachment->delete();
        $this->assertTrue(Storage::disk($attempt->output_disk)->exists($attempt->output_storage_path));
    }

    public function test_identity_regeneration_uses_latest_photos_custom_prompt_and_keeps_history_unapproved(): void
    {
        $order = $this->regenerationOrder();
        $previous = $order->childIdentityRequest->attempts()->sole();
        $old = $previous->getAttributes();
        Storage::disk('local')->put('child-photos/new.png', $this->png.'new-photo');
        $order->updateQuietly(['uploaded_photos' => ['child-photos/new.png']]);
        $prompt = str_repeat('New blue astronaut costume. ', 4);
        $url = route('admin.orders.identity.regenerate', $order);
        $data = ['previous_attempt_id' => $previous->id, 'prompt' => $prompt, 'confirm' => 1];
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('order_identity_regenerations', 1);
        $run = OrderIdentityRegeneration::sole();
        $this->assertTrue(app(AutomationAssignment::class)->owns($order));
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->withArgs(function (GptImageRequest $r) use ($prompt) {
            $this->assertSame(1, $r->maxAttempts);
            $this->assertStringContainsString(trim($prompt), $r->prompt);
            $this->assertSame('data:image/png;base64,'.base64_encode($this->png.'new-photo'), $r->inputImages[0]);

            return true;
        })->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic-second', ['cost_usd' => 0.1], []));
        $service = app(OrderIdentityRegenerationService::class);
        $service->generate($run->id);
        $service->generate($run->id);
        $this->assertSame('succeeded', $run->fresh()->status);
        $this->assertSame($old, $previous->fresh()->getAttributes());
        $this->assertNull($order->fresh()->child_identity_approved_attempt_id);
        $this->assertSame(2, $order->attachments()->count());
        $this->assertSame('paused', OrderProcessRun::sole()->status);
        $this->assertFalse(app(AutomationAssignment::class)->owns($order));
        $links = app(OrderReviewLinks::class)->forOrders(collect([$order->fresh()]));
        $this->assertStringContainsString('order-identity-review', $links);
        preg_match('~https?://[^\s]+~u', $links, $match);
        $this->get($match[0])->assertOk()->assertContent($this->png);
        $this->get(route('orders.regenerated-identity-preview', $run))->assertForbidden();
        $this->post(route('admin.orders.identity-attempts.approve', [$order, $previous]), ['confirm' => 1])->assertUnprocessable();
        $this->post(route('admin.orders.identity-attempts.approve', [$order, $run->attempt]), ['confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame($run->attempt_id, $order->fresh()->child_identity_approved_attempt_id);
        $this->assertSame(0, $order->fresh()->paid_amount_cents);
    }

    public function test_paid_identity_output_remains_visible_when_attachment_copy_fails(): void
    {
        $order = $this->regenerationOrder();
        $service = app(OrderIdentityRegenerationService::class);
        $run = $service->request($order, $this->actor, $order->childIdentityRequest->attempts()->sole()->id, str_repeat('Original child with blue costume. ', 3));
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()
            ->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic-copy-failure', ['cost_usd' => 0.1], []));
        $this->mock(\App\Services\Orders\OrderIdentityMedia::class)->shouldReceive('attach')->once()->andThrow(new \RuntimeException('copy failed'));
        $service->generate($run->id);
        $service->generate($run->id);
        $run->refresh();
        $this->assertSame('review', $run->status);
        $this->assertSame('succeeded', $run->attempt->status);
        $this->assertSame('identity_attachment_failed', $run->error_code);
        $this->get(route('admin.orders.identity-attempts.image', [$order, $run->attempt]))->assertOk();
        $this->assertNull($order->fresh()->child_identity_approved_attempt_id);
        $this->assertSame(1, $order->attachments()->count());
    }

    public function test_identity_regeneration_blocks_changed_inputs_and_uncertain_retry(): void
    {
        $order = $this->regenerationOrder();
        $previous = $order->childIdentityRequest->attempts()->sole();
        $service = app(OrderIdentityRegenerationService::class);
        $run = $service->request($order, $this->actor, $previous->id, str_repeat('Use original child. ', 5));
        $order->updateQuietly(['child_name' => 'changed']);
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        $service->generate($run->id);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertSame('not_billable', $run->attempt->fresh()->billing_status);
        $next = $service->request($order->fresh(), $this->actor, $run->attempt_id, str_repeat('Use current child. ', 5));
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andThrow(new \RuntimeException('timeout'));
        $service->generate($next->id);
        $service->generate($next->id);
        $this->assertSame('uncertain', $next->fresh()->status);
        $this->assertSame('unknown', $next->attempt->fresh()->billing_status);
        $this->expectException(ValidationException::class);
        $service->request($order->fresh(), $this->actor, $next->attempt_id, str_repeat('Another attempt. ', 5));
    }

    public function test_regeneration_requires_takeover_and_rejects_foreign_attempt_media(): void
    {
        $run = $this->generated(2);
        $this->actingAs($this->actor);
        $tasks = OrderProcessIdentity::orderBy('id')->get();
        $this->get(route('admin.orders.identity-attempts.image', [$tasks[0]->order, $tasks[1]->attempt]))->assertNotFound();
        $this->post(route('admin.orders.identity.regenerate', $tasks[0]->order), ['previous_attempt_id' => $tasks[0]->attempt_id, 'prompt' => str_repeat('example ', 10), 'confirm' => 1])->assertForbidden();
        $this->actor->permissions()->syncWithoutDetaching(Permission::where('key', 'orders.production_prompt.manage')->pluck('id'));
        $this->actingAs($this->actor->fresh());
        $this->post(route('admin.orders.identity.regenerate', $tasks[0]->order), ['previous_attempt_id' => $tasks[0]->attempt_id, 'prompt' => str_repeat('example ', 10), 'confirm' => 1])->assertSessionHasErrors('regeneration');
    }

    public function test_unified_review_links_include_pending_identity_and_remove_stale_run_without_approval(): void
    {
        $run = $this->generated();
        $order = OrderProcessIdentity::firstOrFail()->order;
        $service = app(OrderReviewLinks::class);
        $text = $service->forOrders(collect([$order]));
        $this->assertStringContainsString('هوية الطفل', $text);
        preg_match('~https?://[^\s]+~u', $text, $match);
        $this->get($match[0])->assertOk()->assertContent($this->png);
        $this->assertNull($order->fresh()->child_identity_approved_attempt_id);
        $this->assertDatabaseCount('order_process_responses', 0);
        $order->update(['child_name' => 'تغيير بعد التوليد']);
        $this->assertSame('', $service->forOrders(collect([$order->fresh()])));
    }

    public function test_confirmed_checkout_is_reserved_during_enrollment_and_released_when_not_matched(): void
    {
        $order = null;
        DB::transaction(function () use (&$order) {
            $order = Order::create(['order_number' => 'TEST-RESERVED', 'checkout_group_key' => 'RESERVED', 'story_id' => $this->story->id,
                'status' => 'new', 'uploaded_photos' => ['child-photos/identity.png'], 'delivery_details' => ['phone' => '01000000001']]);
            $order->update(['status' => 'new_confirmed']);
        });
        $assignment = app(AutomationAssignment::class);
        $this->assertTrue($assignment->owns($order));
        $this->workflow->update(['enabled' => false]);
        $this->assertFalse($assignment->owns($order));
        $this->workflow->update(['enabled' => true]);
        OrderProcessEvent::where('checkout_key', 'RESERVED')->update(['status' => 'processed']);
        $this->assertFalse($assignment->owns($order));
    }

    private function generated(int $count = 1): OrderProcessRun
    {
        $run = $this->enroll($count);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->times($count)->withArgs(function (GptImageRequest $request) {
            $this->assertSame(1, $request->maxAttempts);
            $this->assertCount(1, $request->inputImages);
            $this->assertStringContainsString('data:image/png;base64,', $request->inputImages[0]);
            $this->assertStringNotContainsString('https://', $request->prompt);

            return true;
        })->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic-receipt', ['cost_usd' => 0.1], []));
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_identity', $run->fresh()->status, $run->fresh()->error_code ?? '');
        foreach (OrderProcessIdentity::all() as $task) {
            app(ProcessIdentityService::class)->generate($task->id);
            app(ProcessIdentityService::class)->generate($task->id);
            $this->assertSame('succeeded', $task->fresh()->status, $task->fresh()->error_code ?? '');
        }
        app(ProcessRuntime::class)->advance($run->id);

        return $run->fresh();
    }

    private function reply(OrderProcessRun $run, string $text = 'تأكيد الهوية', ?string $replyTo = 'wamid.identity'): void
    {
        $conversation = WhatsAppConversation::create(['account_key' => $run->account_key, 'phone_hash' => $run->phone_hash, 'phone' => '+201000000001']);
        $base = ['remote_id' => 'template', 'remote_hash' => hash('sha256', 'template'), 'fingerprint' => hash('sha256', 'template'), 'direction' => 'outbound', 'body' => 'معاينة هوية', 'kind' => 'text', 'sent_at' => now(),
            'provider_metadata' => ['contract_fields_present' => true, 'channelId' => 'wamid.identity', 'replyTo' => null, 'templateName' => ProcessTemplates::IDENTITY, 'interactionId' => 'identity-interaction']];
        $conversation->messages()->create($base);
        $this->travel(3)->seconds();
        $conversation->messages()->create(array_replace($base, ['remote_id' => 'reply', 'remote_hash' => hash('sha256', 'reply'), 'fingerprint' => hash('sha256', 'reply'), 'direction' => 'inbound', 'body' => $text, 'sent_at' => now(),
            'provider_metadata' => ['contract_fields_present' => true, 'channelId' => 'wamid.reply', 'replyTo' => $replyTo, 'templateName' => null, 'interactionId' => 'identity-interaction']]));
        app(ProcessResponsePoller::class)->process($conversation);
        app(ProcessResponsePoller::class)->process($conversation);
    }

    public function test_general_rollout_uses_new_event_boundary_and_explicit_confirmed_cohort_once(): void
    {
        $trial = $this->enroll();
        $events = [];
        foreach (['selected', 'unselected'] as $label) {
            $order = $trial->order->replicate();
            $order->order_number = 'ROLLOUT-'.$label;
            $order->checkout_group_key = 'ROLLOUT-'.$label;
            $order->save();
            $events[$label] = OrderProcessEvent::create(['event_key' => 'old-'.$label, 'type' => 'status_entered',
                'order_id' => $order->id, 'checkout_key' => $order->checkout_group_key, 'data' => ['status' => 'new_confirmed'], 'status' => 'pending']);
        }
        $boundary = OrderProcessEvent::max('id');
        $this->workflow->update(['process_after_event_id' => $boundary]);
        config(['order_process.enrollment_mode' => 'all_new', 'order_process.after_order_id' => 0]);
        app(ProcessRuntime::class)->tick();
        $this->assertDatabaseCount('order_process_runs', 1);
        for ($i = 0; $i < 2; $i++) {
            OrderProcessEvent::firstOrCreate(['event_key' => 'owner-rollout-selected'], [
                'type' => 'status_entered', 'order_id' => $events['selected']->order_id,
                'checkout_key' => $events['selected']->checkout_key, 'data' => ['status' => 'new_confirmed'], 'status' => 'pending']);
            app(ProcessRuntime::class)->tick();
        }
        $this->assertDatabaseCount('order_process_runs', 2);
        $this->assertDatabaseHas('order_process_runs', ['order_id' => $events['selected']->order_id]);
        $this->assertDatabaseMissing('order_process_runs', ['order_id' => $events['unselected']->order_id]);
        $this->assertSame('queued', $trial->fresh()->status);
        $future = $this->enroll();
        $this->assertDatabaseCount('order_process_runs', 3);
        $this->assertGreaterThan($boundary, $future->event_id);
        $this->assertDatabaseCount('order_process_identities', 0);
        Http::assertNothingSent();
    }

    public function test_multi_child_generation_is_deduplicated_private_unapproved_and_accounted(): void
    {
        $run = $this->generated(2);
        $this->assertSame('preview', $run->node_id);
        $this->assertDatabaseCount('order_process_identities', 2);
        foreach (OrderProcessIdentity::all() as $task) {
            $a = $task->attempt;
            $this->assertSame('automation', $a->initiated_by);
            $this->assertSame('0.100000', $a->cost_usd);
            $this->assertSame(hash('sha256', $this->png), $a->output_checksum);
            $this->assertNull($a->identityRequest->approved_attempt_id);
            $this->assertNull($a->identityRequest->consent_accepted_at);
            $this->assertNull($task->order->child_identity_approved_attempt_id);
            Storage::disk('local')->assertExists($a->output_storage_path);
        }
        Http::assertNothingSent();
        Queue::assertPushed(GenerateProcessIdentity::class, 2);
    }

    public function test_preview_template_reply_approves_exact_outputs_once_without_marking_paid(): void
    {
        $run = $this->generated(2);
        app(ProcessRuntime::class)->advance($run->id);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'start/sendMsg') && $r['language'] === 'ar-EG' && $r['template']['templateName'] === ProcessTemplates::IDENTITY
            && count($r['template']['data']) === 8 && is_numeric($r['template']['data'][6]) && is_numeric($r['template']['data'][7]) && ! isset($r['procedureId']));
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_response', $run->fresh()->status);
        $previewUrl = app(ProcessIdentityService::class)->previewUrl($run);
        $this->reply($run);
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_staff', $run->fresh()->status);
        app(ProcessResponsePoller::class)->process(WhatsAppConversation::firstOrFail());
        app(ProcessRuntime::class)->advance($run->id);
        $this->get($previewUrl)->assertOk();
        $this->assertSame(ProcessIdentityService::APPROVED_ORDER_STATUS, $run->fresh()->context['status']);
        $this->assertDatabaseCount('order_process_responses', 1);
        foreach (OrderProcessIdentity::all() as $task) {
            $this->assertSame($task->attempt_id, $task->order->child_identity_approved_attempt_id);
            $this->assertSame($task->attempt_id, $task->attempt->identityRequest->approved_attempt_id);
            $this->assertSame(0, (int) $task->order->paid_amount_cents);
            $this->assertSame(ProcessIdentityService::APPROVED_ORDER_STATUS, $task->order->status);
            $this->assertSame(1, $task->order->statusLogs()->where('status', ProcessIdentityService::APPROVED_ORDER_STATUS)->count());
            $audit = AdminActivityLog::where('subject_id', $task->order_id)->where('action', 'automation.identity_approved')->get();
            $this->assertCount(1, $audit);
            $this->assertNull($audit->first()->user_id);
            $this->assertSame('Automation', data_get($audit->first()->properties, 'actor_name'));
        }
    }

    public function test_pinned_three_story_two_child_checkout_with_personalized_product_completes_as_one_process(): void
    {
        $first = $this->preflightCandidate();
        $orders = collect([$first]);
        foreach ([1, 2] as $index) {
            $copy = $first->replicate();
            $copy->order_number = 'TEST-'.Str::random(8);
            $copy->saveQuietly();
            $item = $first->items()->first()->replicate();
            $item->order_id = $copy->id;
            $item->save();
            $orders->push($copy);
        }
        foreach ($orders as $index => $order) {
            $story = Story::create(['title' => 'قصة تركيب '.$index, 'slug' => 'mixed-trial-'.$index,
                'language' => 'ar', 'gender' => 'girl', 'price' => 100, 'active' => true]);
            $order->forceFill(['story_id' => $story->id, 'child_name' => $index < 2 ? 'طفلة أ' : 'طفلة ب',
                'child_age' => $index < 2 ? 7 : 3, 'child_gender' => 'girl'])->saveQuietly();
            $order->items()->update(['story_id' => $story->id, 'title' => $story->title]);
        }
        $youngest = $orders->last();
        ob_start();
        $secondImage = imagecreatetruecolor(3, 3);
        imagepng($secondImage);
        $secondPhoto = ob_get_clean();
        Storage::disk('local')->put('child-photos/second-child.png', $secondPhoto);
        $youngest->forceFill(['uploaded_photos' => ['child-photos/second-child.png']])->saveQuietly();
        $productOrder = $youngest->replicate();
        $productOrder->order_number = 'TEST-'.Str::random(8);
        $productOrder->story_id = null;
        $productOrder->saveQuietly();
        $product = Product::create(['name_ar' => 'بازل اختبار', 'slug' => 'synthetic-linked-puzzle', 'is_active' => true]);
        $puzzle = $productOrder->items()->create(['item_type' => 'product', 'product_id' => $product->id,
            'personalization_mode' => 'collect_child_details', 'title' => 'بازل اختبار',
            'quantity' => 1, 'unit_price_cents' => 5000, 'total_price_cents' => 5000,
            'personalization_snapshot' => ['child_name' => 'طفلة ب', 'child_age' => 3, 'child_gender' => 'girl']]);
        config(['order_process.enrollment_mode' => 'trial', 'order_process.trial_order_id' => $first->id,
            'order_process.trial_checkout_order_ids' => $orders->pluck('id')->push($productOrder->id)->implode(','),
            'order_process.trial_automation_id' => $this->workflow->id]);
        $report = app(ProcessIdentityPreflight::class)->inspect($first->id, $this->workflow->id, $this->actor->id);
        $this->assertTrue($report['candidate_valid'], json_encode($report['blockers']));
        $this->assertTrue($report['gates']['trial_scope_pinned']);
        $this->assertSame(3, $report['identity_count']);
        $this->assertSame(4, $report['checkout_order_count']);
        $youngest->forceFill(['uploaded_photos' => ['child-photos/missing.png']])->saveQuietly();
        $this->assertContains('identity_inputs_unavailable', app(ProcessIdentityPreflight::class)->inspect($first->id, $this->workflow->id, $this->actor->id)['blockers']);
        $youngest->forceFill(['uploaded_photos' => ['child-photos/second-child.png']])->saveQuietly();
        $this->assertEqualsWithDelta(0.3, $report['estimated_usd_total'], 0.00001);
        config(['order_process.identity_budget_usd' => 0.2]);
        $this->assertFalse(app(ProcessIdentityPreflight::class)->inspect($first->id, $this->workflow->id, $this->actor->id)['candidate_valid']);
        config(['order_process.identity_budget_usd' => 1]);
        // Simulate the real confirmation event; fixtures already have confirmed status.
        DB::transaction(function () use ($first): void {
            $first->update(['status' => 'new']);
            $first->update(['status' => 'new_confirmed']);
        });
        app(ProcessRuntime::class)->tick();
        $run = OrderProcessRun::where('checkout_key', $first->checkoutGroupKey())->sole();
        $prompts = [];
        $this->mock(GptImageClient::class)->shouldReceive('generate')->times(3)->withArgs(function (GptImageRequest $request) use (&$prompts, $secondPhoto) {
            $prompts[] = $request->prompt;
            $expected = str_contains($request->prompt, 'طفلة ب') ? $secondPhoto : $this->png;
            $this->assertSame(['data:image/png;base64,'.base64_encode($expected)], $request->inputImages);

            return $request->maxAttempts === 1;
        })->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic-multi', ['cost_usd' => 0.1], []));
        app(ProcessRuntime::class)->advance($run->id);
        foreach ($run->identities as $task) {
            app(ProcessIdentityService::class)->generate($task->id);
            app(ProcessIdentityService::class)->generate($task->id);
        }
        $this->assertCount(3, $prompts);
        $this->assertSame(2, collect($prompts)->filter(fn ($prompt) => str_contains($prompt, 'طفلة أ'))->count());
        $this->assertSame(1, collect($prompts)->filter(fn ($prompt) => str_contains($prompt, 'طفلة ب'))->count());
        app(ProcessRuntime::class)->advance($run->id);
        $url = app(ProcessIdentityService::class)->previewUrl($run->fresh());
        $this->get($url)->assertOk()->assertSee('قصة تركيب 0')->assertSee('قصة تركيب 1')->assertSee('قصة تركيب 2')->assertSee('طفلة أ')->assertSee('طفلة ب');
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_response', $run->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/start/sendMsg')
            && str_contains($request['template']['data'][4], 'بازل اختبار'));
        $this->reply($run);
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_staff', $run->fresh()->status, $run->fresh()->error_code ?? '');
        $this->assertSame(1, $run->steps()->where('type', 'whatsapp')->sole()->messageAttempts()->count());
        $this->assertSame(3, $run->identities()->count());
        foreach ($orders as $order) {
            $this->assertSame(ProcessIdentityService::APPROVED_ORDER_STATUS, $order->fresh()->status);
            $this->assertNotNull($order->fresh()->child_identity_approved_attempt_id);
            $this->assertSame(0, (int) $order->fresh()->paid_amount_cents);
        }
        $this->assertSame($productOrder->id, $puzzle->fresh()->order_id);
        $this->assertSame('new_confirmed', $productOrder->fresh()->status);
        $this->assertNull($productOrder->fresh()->child_identity_approved_attempt_id);
        $this->assertSame(0, (int) $productOrder->fresh()->paid_amount_cents);
        $this->assertSame('طفلة ب', $puzzle->fresh()->personalization_snapshot['child_name']);
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/start/sendMsg')));
        $this->get($url)->assertOk();
        config(['order_process.payment_review_enabled' => true]);
        $conversation = WhatsAppConversation::where('phone_hash', $run->phone_hash)->sole();
        $conversation->messages()->create(['remote_id' => 'multi-attachment', 'remote_hash' => hash('sha256', 'multi-attachment'),
            'fingerprint' => hash('sha256', 'multi-attachment'), 'direction' => 'inbound', 'kind' => 'image',
            'attachments' => [['name' => 'synthetic.png']], 'sent_at' => now(),
            'provider_metadata' => ['interactionId' => 'identity-interaction', 'channelId' => 'wamid.multi-attachment']]);
        $reviewer = app(ProcessPaymentReview::class);
        $this->assertTrue($reviewer->needsFullWindow($run->context['phone']));
        $reviewer->process($conversation);
        $reviewer->process($conversation);
        $this->assertDatabaseCount('order_payment_reviews', 1);
        $this->assertDatabaseCount('order_payment_review_signals', 1);
        $this->assertSame($run->id, OrderPaymentReview::sole()->run_id);
        $this->assertSame(0, (int) Order::where('checkout_group_key', $run->checkout_key)->sum('paid_amount_cents'));
        $this->assertSame('waiting_staff', $run->fresh()->status);
    }

    public function test_disabling_approval_status_blocks_before_identity_or_status_is_changed(): void
    {
        $run = $this->generated();
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->reply($run);
        OrderStatusDefinition::where('key', ProcessIdentityService::APPROVED_ORDER_STATUS)->update(['is_active' => false]);
        app(ProcessRuntime::class)->advance($run->id);
        $task = OrderProcessIdentity::firstOrFail();
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertNull($task->order->child_identity_approved_attempt_id);
        $this->assertSame('preview_uploaded', $task->order->status);
        $this->assertSame(0, $task->order->statusLogs()->where('status', ProcessIdentityService::APPROVED_ORDER_STATUS)->count());
    }

    public function test_recorded_deposit_during_identity_review_keeps_preview_and_confirmation_valid(): void
    {
        $run = $this->generated();
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $order = OrderProcessIdentity::firstOrFail()->order;
        app(OrderPaymentService::class)->updateGroup(
            $order, 'partially_paid', 75, 'نقدي', $this->actor, request(),
        );
        $ledgerCount = DB::table('order_payment_events')->count();
        $this->assertGreaterThan(0, $ledgerCount);
        $this->mock(CartTrackingService::class)->shouldNotReceive('captureAttribution');
        $this->get(app(ProcessIdentityService::class)->previewUrl($run))->assertOk();
        $this->reply($run);
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_staff', $run->fresh()->status);
        $this->assertNotNull($order->fresh()->child_identity_approved_attempt_id);
        $this->assertSame(7500, (int) $order->fresh()->paid_amount_cents);
        $this->assertSame('partially_paid', $order->fresh()->payment_status);
        $this->assertSame(ProcessIdentityService::APPROVED_ORDER_STATUS, $order->fresh()->status);
        $this->assertSame($ledgerCount, DB::table('order_payment_events')->count());
        $this->assertDatabaseCount('order_process_responses', 1);
    }

    public function test_preview_message_uses_current_balance_after_payment_before_send(): void
    {
        $run = $this->generated();
        $order = OrderProcessIdentity::firstOrFail()->order;
        app(OrderPaymentService::class)->updateGroup(
            $order, 'partially_paid', 75, 'نقدي', $this->actor, request(),
        );
        app(ProcessRuntime::class)->advance($run->id);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'start/sendMsg')
            && $r['template']['data'][6] === '150.00' && $r['template']['data'][7] === '75.00');
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_payment_tolerance_does_not_allow_changed_price_discount_contact_or_identity_inputs(): void
    {
        $run = $this->generated();
        $order = OrderProcessIdentity::firstOrFail()->order;
        $original = $order->getAttributes();
        $changes = [
            ['discount_cents' => 100],
            ['delivery_details' => array_replace($order->delivery_details, ['phone' => '01000000002'])],
            ['delivery_details' => array_replace($order->delivery_details, ['delivery_fee' => 100])],
            ['child_name' => 'طفل آخر'],
            ['uploaded_photos' => ['child-photos/another.png']],
            ['status' => 'cancelled'],
        ];
        foreach ($changes as $change) {
            $order->forceFill($change)->saveQuietly();
            try {
                app(ProcessRuntime::class)->guard($run->fresh());
                $this->fail('Changed '.array_key_first($change).' was accepted.');
            } catch (\RuntimeException $e) {
                $this->assertSame('inputs_changed', $e->getMessage());
            } finally {
                $order->setRawAttributes($original)->saveQuietly();
            }
        }
        $item = $order->items()->firstOrFail();
        $item->update(['quantity' => 2, 'total_price_cents' => 20000]);
        $this->expectExceptionMessage('inputs_changed');
        app(ProcessRuntime::class)->guard($run->fresh());
    }

    public function test_preexisting_unversioned_context_keeps_strict_payment_guard(): void
    {
        $run = $this->generated();
        $contexts = app(ProcessOrderContext::class);
        $context = $contexts->snapshot($contexts->orders($run->checkout_key), 1);
        unset($context['fingerprint_version']);
        $run->update(['context' => $context + ['actor_id' => $this->actor->id]]);
        app(ProcessRuntime::class)->guard($run->fresh());
        $order = OrderProcessIdentity::firstOrFail()->order;
        app(OrderPaymentService::class)->updateGroup(
            $order, 'partially_paid', 75, 'نقدي', $this->actor, request(),
        );
        $this->expectExceptionMessage('inputs_changed');
        app(ProcessRuntime::class)->guard($run->fresh());
    }

    private function preflightCandidate(): Order
    {
        $run = $this->enroll();
        $source = Order::findOrFail($run->order_id);
        $order = $source->replicate();
        $key = 'SYNTHETIC-PREFLIGHT-'.Str::uuid();
        $order->order_number = 'TEST-'.Str::random(8);
        $order->checkout_group_key = $key;
        $order->delivery_details = array_replace($source->delivery_details, ['checkout_group' => $key]);
        $order->saveQuietly();
        foreach ($source->items as $item) {
            $copy = $item->replicate();
            $copy->order_id = $order->id;
            $copy->save();
        }

        return $order;
    }

    public function test_identity_preflight_is_read_only_redacted_and_not_an_activation_permit(): void
    {
        $order = $this->preflightCandidate();
        config(['order_process.enabled' => false, 'order_process.identity_enabled' => false, 'order_process.identity_template_ready' => false]);
        Queue::fake();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        $report = app(ProcessIdentityPreflight::class)->inspect($order->id, $this->workflow->id, $this->actor->id);
        $this->assertTrue($report['candidate_valid'], json_encode($report['blockers']));
        $this->assertFalse($report['execution_authorized']);
        $this->assertFalse($report['external_delivery_and_workers_verified']);
        $this->assertFalse($report['gates']['process_enabled']);
        $this->assertSame(1, $report['verified_photo_count']);
        $this->assertEquals(0.1, $report['estimated_usd_per_identity']);
        foreach (['01000000001', '201000000001', 'طفل', 'عميل', 'child-photos/', 'synthetic-not-a-real-key', 'https://'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($report, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame([], $writes);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->artisan('order-process:identity-preflight', ['order' => $order->id, '--automation' => $this->workflow->id, '--actor' => $this->actor->id])->assertSuccessful();
        $this->assertSame([], $writes);
        $this->assertFalse(config('order_process.enabled'));
    }

    public function test_preflight_definition_fingerprint_ignores_object_key_order_but_detects_content_changes(): void
    {
        $order = $this->preflightCandidate();
        $service = app(ProcessIdentityPreflight::class);
        $before = $service->inspect($order->id, $this->workflow->id, $this->actor->id);
        $revision = $this->workflow->revision;
        $definition = array_reverse($revision->definition, true);
        $definition['nodes'][0] = array_reverse($definition['nodes'][0], true);
        $revision->update(['definition' => $definition]);
        $reordered = $service->inspect($order->id, $this->workflow->id, $this->actor->id);
        $this->assertTrue($reordered['candidate_valid']);
        $this->assertSame($before['definition_fingerprint'], $reordered['definition_fingerprint']);
        $definition['nodes'][0]['config']['budget_usd'] = 0.5;
        $revision->update(['definition' => $definition]);
        $changed = $service->inspect($order->id, $this->workflow->id, $this->actor->id);
        $this->assertNotSame($before['definition_fingerprint'], $changed['definition_fingerprint']);
        Http::assertNothingSent();
    }

    public function test_identity_preflight_blocks_missing_permission_and_does_not_read_photos(): void
    {
        $order = $this->preflightCandidate();
        $this->actor->permissions()->detach(Permission::where('key', 'order_automation.execute')->value('id'));
        $this->mock(WorkflowInputs::class)->shouldNotReceive('capture');
        $report = app(ProcessIdentityPreflight::class)->inspect($order->id, $this->workflow->id, $this->actor->id);
        $this->assertFalse($report['candidate_valid']);
        $this->assertContains('actor_permissions_missing', $report['blockers']);
        $this->assertArrayNotHasKey('verified_photo_count', $report);
        Http::assertNothingSent();
    }

    public function test_identity_preflight_blocks_existing_process_and_corrupt_photo(): void
    {
        $run = $this->enroll();
        $service = app(ProcessIdentityPreflight::class);
        $report = $service->inspect($run->order_id, $this->workflow->id, $this->actor->id);
        $this->assertContains('existing_process_or_identity_claim', $report['blockers']);
        $order = $this->preflightCandidate();
        Storage::disk('local')->put('child-photos/identity.png', 'not an image');
        $report = $service->inspect($order->id, $this->workflow->id, $this->actor->id);
        $this->assertContains('identity_inputs_unavailable', $report['blockers']);
        $this->assertFalse($report['candidate_valid']);
        Http::assertNothingSent();
    }

    public function test_typed_identity_confirmation_uses_same_interaction_evidence(): void
    {
        $run = $this->generated();
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->reply($run, ' تأكيد الهوية ', null);
        $this->assertSame('approve', $run->fresh()->node_id);
    }

    public function test_revision_request_goes_to_staff_without_identity_approval(): void
    {
        $run = $this->generated();
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->reply($run, 'طلب تعديل الهوية');
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_staff', $run->fresh()->status);
        $this->assertNull(OrderProcessIdentity::first()->order->child_identity_approved_attempt_id);
    }

    public function test_uncertain_ai_result_is_never_resubmitted(): void
    {
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andThrow(new \RuntimeException('timeout synthetic'));
        $task = OrderProcessIdentity::firstOrFail();
        app(ProcessIdentityService::class)->generate($task->id);
        app(ProcessIdentityService::class)->generate($task->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('uncertain', $task->fresh()->status);
        $this->assertSame('unknown', $task->fresh()->attempt->billing_status);
        $this->assertSame('blocked', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_provider_failure_diagnostics_are_retained_without_sensitive_response_or_retry(): void
    {
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        $response = new \Illuminate\Http\Client\Response(new Response(429, [], '{"error":{"message":"private customer payload"}}'));
        $error = new GptImageException('private exception text', 'insufficient_quota', 'req_synthetic', true,
            new RequestException($response));
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andThrow($error);
        $task = OrderProcessIdentity::firstOrFail();
        app(ProcessIdentityService::class)->generate($task->id);
        app(ProcessIdentityService::class)->generate($task->id);
        $attempt = $task->fresh()->attempt;
        $this->assertSame('req_synthetic', $attempt->api_request_id);
        $this->assertSame(['provider_error_code' => 'insufficient_quota', 'provider_request_id' => 'req_synthetic', 'provider_http_status' => 429], $attempt->response_metadata['failure_diagnostics']);
        $this->assertStringNotContainsString('private', json_encode($attempt->response_metadata));
        $this->assertSame('unknown', $attempt->billing_status);
        $this->assertSame('uncertain', $task->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_provider_diagnostic_identifiers_reject_unstructured_and_overlong_values(): void
    {
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andThrow(
            new GptImageException('private payload', 'private customer email@example.com', str_repeat('x', 121), true));
        $task = OrderProcessIdentity::firstOrFail();
        app(ProcessIdentityService::class)->generate($task->id);
        $attempt = $task->fresh()->attempt;
        $this->assertNull($attempt->api_request_id);
        $this->assertSame(['provider_error_code' => null, 'provider_request_id' => null, 'provider_http_status' => null], $attempt->response_metadata['failure_diagnostics']);
        $this->assertSame('unknown', $attempt->billing_status);
        Http::assertNothingSent();
    }

    public function test_changed_photo_bytes_stop_generation_before_billable_call(): void
    {
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        Storage::disk('local')->put('child-photos/identity.png', 'changed');
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        app(ProcessIdentityService::class)->generate(OrderProcessIdentity::firstOrFail()->id);
        $this->assertSame('blocked', OrderProcessIdentity::first()->status);
    }

    public function test_changed_phone_stops_generation_and_no_message_is_sent(): void
    {
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        $run->order->update(['delivery_details' => ['phone' => '01000000002']]);
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        app(ProcessIdentityService::class)->generate(OrderProcessIdentity::firstOrFail()->id);
        $this->assertSame('blocked', OrderProcessIdentity::first()->status);
        Http::assertNothingSent();
    }

    public function test_unknown_or_insufficient_budget_never_creates_generation(): void
    {
        $run = $this->enroll(2);
        config(['order_process.identity_budget_usd' => 0.15]);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('identity_budget_required', $run->fresh()->error_code);
        $this->assertDatabaseCount('order_process_identities', 0);
        Queue::assertNotPushed(GenerateProcessIdentity::class);
    }

    public function test_expired_processing_lease_blocks_without_retry(): void
    {
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        OrderProcessIdentity::first()->update(['status' => 'processing', 'lease_until' => now()->subSecond()]);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertSame('identity_requires_review', $run->fresh()->error_code);
    }

    public function test_preview_signatures_expiry_tampering_and_no_customer_financial_data(): void
    {
        $run = $this->generated();
        $url = app(ProcessIdentityService::class)->previewUrl($run);
        $this->mock(CartTrackingService::class)->shouldNotReceive('captureAttribution');
        $this->get($url)->assertOk()->assertSee('طفل 0')->assertDontSee('01000000001')->assertDontSee('01111822277')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('order-process.identity-preview', $run))->assertForbidden();
        $this->get(str_replace('/'.$run->id.'?', '/999999?', $url))->assertNotFound();
        $task = OrderProcessIdentity::first();
        $image = URL::temporarySignedRoute('order-process.identity-image', now()->addMinute(), ['run' => $run->id, 'identity' => $task->id]);
        $this->get($image)->assertOk()->assertHeader('Content-Type', 'image/png');
        Storage::disk('local')->put($task->attempt->output_storage_path, 'changed');
        $this->get($image)->assertNotFound();
        $this->travel(36)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_simulation_covers_identity_approval_and_manual_payment_gate_without_external_actions(): void
    {
        $simulator = app(ProcessSimulator::class);
        $run = $simulator->start($this->workflow, $this->actor, (string) Str::uuid(), ['event' => 'status_entered', 'previous_status' => 'new', 'status' => 'new_confirmed', 'items' => [['type' => 'story', 'catalog_id' => $this->story->id]]]);
        $this->assertSame('waiting_response', $run->status);
        $run = $simulator->respond($run->fresh(), $run->fresh()->version, 'approve_identity');
        $this->assertTrue($run->context['identity_approved']);
        $this->assertSame(ProcessIdentityService::APPROVED_ORDER_STATUS, $run->context['status']);
        $this->assertSame('waiting_staff', $run->status);
        $this->assertDatabaseCount('order_process_identities', 0);
        Http::assertNothingSent();
    }

    public function test_live_activation_requires_independent_template_readiness(): void
    {
        app(ProcessRuntime::class)->activate($this->workflow, $this->actor, false);
        config(['order_process.identity_template_ready' => false]);
        $this->expectException(ValidationException::class);
        app(ProcessRuntime::class)->activate($this->workflow, $this->actor, true);
    }

    public function test_revoked_photo_access_blocks_paid_work(): void
    {
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        $this->actor->permissions()->detach(Permission::where('key', 'orders.photos.view')->value('id'));
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        app(ProcessIdentityService::class)->generate(OrderProcessIdentity::first()->id);
        $this->assertSame('blocked', OrderProcessIdentity::first()->status);
    }

    public function test_input_change_during_generation_preserves_output_but_does_not_publish_it(): void
    {
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andReturnUsing(function () use ($run) {
            $run->order->update(['child_name' => 'اسم مختلف']);

            return new GptImageResult($this->png, 'png', 'image/png', 'synthetic-receipt', ['cost_usd' => 0.1], []);
        });
        $task = OrderProcessIdentity::first();
        app(ProcessIdentityService::class)->generate($task->id);
        $task->refresh();
        $this->assertSame('uncertain', $task->status);
        $this->assertSame('succeeded', $task->attempt->status);
        $this->assertSame('0.100000', $task->attempt->cost_usd);
        $this->assertNull($task->order->child_identity_approved_attempt_id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('blocked', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_cannot_approve_without_consumed_identity_confirmation(): void
    {
        $run = $this->generated();
        // Even a malformed/administratively changed node pointer cannot invent a decision.
        $run->update(['node_id' => 'approve']);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('identity_approval_missing', $run->fresh()->error_code);
        $this->assertNull(OrderProcessIdentity::first()->order->child_identity_approved_attempt_id);
    }

    public function test_wrong_template_history_never_approves_identity(): void
    {
        $run = $this->generated();
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $conversation = WhatsAppConversation::create(['account_key' => $run->account_key, 'phone_hash' => $run->phone_hash, 'phone' => '+201000000001']);
        foreach (['outbound', 'inbound'] as $direction) {
            $conversation->messages()->create(['remote_id' => $direction, 'remote_hash' => hash('sha256', $direction), 'fingerprint' => hash('sha256', $direction),
                'direction' => $direction, 'body' => 'تأكيد الهوية', 'kind' => 'text', 'sent_at' => now(),
                'provider_metadata' => ['contract_fields_present' => true, 'channelId' => $direction === 'outbound' ? 'wamid.identity' : 'wamid.reply',
                    'replyTo' => $direction === 'inbound' ? 'wamid.identity' : null, 'templateName' => 'herokid_order_confirmation', 'interactionId' => 'identity-interaction']]);
            $this->travel(3)->seconds();
        }
        app(ProcessResponsePoller::class)->process($conversation);
        $this->assertSame('waiting_response', $run->fresh()->status);
        $this->assertDatabaseCount('order_process_responses', 0);
    }

    public function test_uncertain_template_send_never_repeats_or_approves(): void
    {
        $run = $this->generated();
        $this->sendUncertain = true;
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('uncertain', $run->fresh()->status);
        Http::assertSentCount(2);
        $this->assertNull(OrderProcessIdentity::first()->order->child_identity_approved_attempt_id);
    }

    public function test_missing_output_cannot_be_approved_after_customer_reply(): void
    {
        $run = $this->generated();
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->reply($run);
        $task = OrderProcessIdentity::first();
        Storage::disk('local')->delete($task->attempt->output_storage_path);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertNull($task->order->child_identity_approved_attempt_id);
    }

    public function test_invalid_template_mapping_and_missing_generation_are_rejected(): void
    {
        foreach (['mapping', 'generation'] as $mode) {
            $d = ProcessDefinition::defaults('identity');
            if ($mode === 'mapping') {
                $d['nodes'][1]['config']['variables'] = array_reverse(ProcessTemplates::IDENTITY_FIELDS);
            } else {
                array_shift($d['nodes']);
            }
            try {
                app(ProcessDefinition::class)->validate($d);
                $this->fail('Invalid graph accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_second_workflow_cannot_repeat_paid_generation_for_same_order(): void
    {
        $run = $this->generated();
        $run->update(['status' => 'cancelled', 'active_key' => null]);
        $second = $run->replicate(['enrollment_key', 'active_key']);
        $second->enrollment_key = hash('sha256', 'another workflow');
        $second->status = 'queued';
        $second->node_id = 'generate';
        $second->save();
        app(ProcessRuntime::class)->advance($second->id);
        $this->assertSame('identity_already_claimed', $second->fresh()->error_code);
        $this->assertDatabaseCount('order_process_identities', 1);
    }

    public function test_existing_legacy_identity_claim_prevents_new_paid_attempt(): void
    {
        $run = $this->enroll();
        OrderAutomationRun::create(['automation_id' => $this->workflow->id, 'revision_id' => $this->workflow->revision_id,
            'order_id' => $run->order_id, 'request_key' => 'synthetic-legacy', 'live_key' => 'story-identity:'.$run->order_id,
            'dry_run' => false, 'trial' => false, 'status' => 'queued', 'steps' => [], 'context' => []]);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('identity_already_claimed', $run->fresh()->error_code);
        $this->assertDatabaseCount('order_process_identities', 0);
    }

    public function test_manual_generation_cannot_duplicate_unresolved_process_attempt(): void
    {
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        $task = OrderProcessIdentity::first();
        $this->expectException(ValidationException::class);
        app(ChildIdentityAttemptService::class)->create($task->attempt->identityRequest, (string) Str::uuid(), 'admin', $this->actor);
    }

    public function test_unknown_cost_and_non_usd_estimate_block_before_generation(): void
    {
        $run = $this->enroll();
        AiModel::where('code', 'gpt-image-2')->update(['estimated_cost_currency' => 'EGP']);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('identity_budget_required', $run->fresh()->error_code);
        $this->assertDatabaseCount('order_process_identities', 0);
    }

    public function test_new_preset_editor_and_live_page_render_for_authorized_manager(): void
    {
        $this->actingAs($this->actor);
        $this->get(route('admin.order-automation.process.edit', $this->workflow))->assertOk()->assertSee(ProcessTemplates::IDENTITY)->assertSee('توليد هوية الطفل وحفظها');
        $this->get(route('admin.order-automation.process.live', $this->workflow))->assertOk();
        $this->post(route('admin.order-automation.process.create'), ['name' => 'مسار هوية إضافي', 'preset' => 'identity'])->assertRedirect();
    }

    public function test_failed_preview_delivery_is_visible_without_retry_or_autoapproval(): void
    {
        $run = $this->generated();
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->reply($run, 'رسالة عادية');
        $conversation = WhatsAppConversation::first();
        $template = $conversation->messages()->where('direction', 'outbound')->first();
        $metadata = $template->provider_metadata;
        $metadata['delivery'] = ['status' => 'failed', 'failureCategory' => 'Unreachable'];
        $template->update(['provider_metadata' => $metadata]);
        app(ProcessResponsePoller::class)->process($conversation);
        app(ProcessResponsePoller::class)->process($conversation);
        $this->assertSame('failed', data_get($run->steps()->where('type', 'whatsapp')->first()->receipt, 'delivery.status'));
        $this->assertSame('waiting_response', $run->fresh()->status);
        $this->assertNull(OrderProcessIdentity::first()->attempt->identityRequest->approved_attempt_id);
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/start/sendMsg')));
        $this->actingAs($this->actor)->get(route('admin.order-automation.process.live', $this->workflow))->assertOk()->assertSee('فشل التسليم');
    }

    public function test_staff_stop_cannot_release_uncertain_paid_identity_claim(): void
    {
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        OrderProcessIdentity::first()->update(['status' => 'uncertain']);
        $run->refresh()->update(['status' => 'blocked']);
        try {
            app(ProcessRuntime::class)->staffAction($run, $this->actor, $run->version, 'stop', 'Synthetic stop');
            $this->fail('Uncertain paid attempt must retain its claim');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertNotNull($run->fresh()->active_key);
    }
}
