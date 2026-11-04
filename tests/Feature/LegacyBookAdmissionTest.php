<?php

namespace Tests\Feature;

use App\Models\CodexSceneRun;
use App\Models\LegacyBookCoverApproval;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPreview;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Services\CodexScenes\BookPageService;
use App\Services\CodexScenes\ManualSceneService;
use App\Services\CodexScenes\OrderGenerationBanner;
use App\Services\Orders\AutomationControl;
use App\Services\Orders\BookPromptSync;
use App\Services\Orders\LegacyBookAdmission;
use App\Services\Orders\OrderProductPreviewService;
use App\Services\Orders\ProductProductionComponentSnapshotService;
use App\Services\ProductAutomation\BookCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyBookAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private string $spool;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('s3_private');
        Storage::fake('local');
        Storage::fake('public');
        config(['media.private_disk' => 's3_private', 'product_review.enabled' => true, 'product_review.book_pages_enabled' => true,
            'codex_scenes.enabled' => true, 'order_process.enabled' => false, 'robodesk.enabled' => false]);
        $this->spool = sys_get_temp_dir().'/legacy-book-'.Str::uuid();
        mkdir($this->spool.'/outbox', 0770, true);
        file_put_contents($this->spool.'/outbox/health.json', json_encode(['authenticated' => true, 'supported_protocols' => [6]]));
        config(['codex_scenes.spool' => $this->spool]);
        $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        foreach (['orders.view', 'orders.update', 'orders.view_all', 'orders.automation.control', 'orders.photos.view', 'orders.production_prompt.manage'] as $permission) {
            $this->actor->permissions()->syncWithoutDetaching(Permission::firstOrCreate(['key' => $permission], ['name' => $permission])->id);
        }
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->spool);
        parent::tearDown();
    }

    private function fixture(int $pages = 40): OrderItem
    {
        $path = UploadedFile::fake()->image('child.png', 200, 300)->store('child', 's3_private');
        $order = Order::create(['order_number' => 'SYN-'.Str::random(8), 'checkout_group_key' => (string) Str::uuid(),
            'parent_name' => 'Synthetic', 'child_name' => 'طفل اختبار', 'child_age' => 5, 'child_gender' => 'boy',
            'status' => 'waiting_customer', 'printing_status' => 'not_started', 'shipping_status' => 'not_ready',
            'payment_status' => 'paid_in_full', 'paid_amount_cents' => 53500, 'uploaded_photos' => [$path], 'delivery_details' => ['phone' => '01000000001']]);
        $product = Product::create(['slug' => 'legacy-'.Str::random(8), 'name_ar' => 'كتاب تلوين', 'price_cents' => 10000, 'is_active' => true]);
        $variant = $product->variants()->create(['name_ar' => 'كتاب '.$pages.' صفحة', 'is_active' => true]);
        $product->productionComponents()->create(['stable_key' => 'old', 'name' => 'قديم', 'prompt_template' => 'Historical custom brief', 'quantity_per_item' => 1, 'is_active' => true]);
        $item = $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'variant_snapshot' => ['id' => $variant->id, 'name_ar' => $variant->name_ar], 'title' => $variant->name_ar, 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
        app(BookCatalog::class)->configure($product->fresh(), 20, [$variant->id => $pages]);
        app(OrderProductPreviewService::class)->upload($order, [UploadedFile::fake()->image('existing-cover.png', 400, 600)], null, $this->actor);

        return $item->fresh();
    }

    private function approve(OrderItem $item): LegacyBookCoverApproval
    {
        $service = app(LegacyBookAdmission::class);
        $offers = $service->offers($item);
        $this->assertArrayHasKey('token', $offers[0], json_encode($offers));

        return $service->approve($item, $this->actor, Request::create('/'), $offers[0]['token']);
    }

    public function test_existing_cover_and_forty_pages_can_be_explicitly_adopted_then_started_once(): void
    {
        $item = $this->fixture();
        $preview = OrderPreview::sole();
        $old = $item->productionComponents->sole();
        $files = Storage::disk('s3_private')->allFiles();
        $service = app(LegacyBookAdmission::class);
        $offers = $service->offers($item);
        $this->assertSame(40, $offers[0]['pages']);
        $this->assertNull($old->fresh()->retired_at);
        $this->assertCount(1, app(OrderGenerationBanner::class)->data($item->order)['legacyBooks']);
        $this->get(route('admin.orders.workspace.legacy-book', [$item->order, $item]))->assertOk()->assertSee('40 صفحة داخلية')->assertSee('تسجيل الموافقة وتجهيز الكتاب');
        if (getenv('LEGACY_BOOK_QA_DIR')) {
            $html = $this->get(route('admin.orders.workspace.legacy-book', [$item->order, $item]))->getContent();
            file_put_contents(getenv('LEGACY_BOOK_QA_DIR').'/legacy.html', $html);
            file_put_contents(getenv('LEGACY_BOOK_QA_DIR').'/cover.png', Storage::disk($preview->disk)->get($preview->file_path));
        }
        $this->approve($item);
        $this->assertNotNull($old->fresh()->retired_at);
        $this->assertSame('Historical custom brief', $old->fresh()->prompt_template);
        $this->assertSame(40, $item->fresh()->productionComponents->firstWhere('production_stage', 'book_interior')->quantity_per_item);
        $this->assertSame($files, Storage::disk('s3_private')->allFiles());
        $this->assertSame($preview->file_path, $preview->fresh()->file_path);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $this->assertDatabaseCount('codex_scene_runs', 0);
        $this->assertSame(53500, $item->order->fresh()->paid_amount_cents);
        $books = app(BookPageService::class);
        $this->assertNull($books->admissionReason($item->fresh()));
        $this->post(route('admin.orders.workspace.book-start', [$item->order, $item]), [
            'confirm' => 1, 'expected_updated_at' => $item->fresh()->updated_at->toISOString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $run = CodexSceneRun::sole();
        $this->assertSame(40, $run->scene_count);
        $this->assertSame($run->fingerprint, $books->fingerprint($run));
        app(ManualSceneService::class)->tick();
        $manifest = json_decode(file_get_contents($this->spool.'/inbox/'.$run->id.'/manifest.json'), true);
        $this->assertSame(40, $manifest['scene_count']);
        $this->assertSame(6, $manifest['protocol']);
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertNotNull($books->admissionReason($item->fresh()));
        $this->assertDatabaseCount('codex_scene_runs', 1);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_autopilot_guard_preserves_legacy_approval_without_starting_stale_work(): void
    {
        $item = $this->fixture();
        $approval = $this->approve($item);
        $before = $approval->fresh()->getRawOriginal();
        $files = Storage::disk('s3_private')->allFiles();
        $called = false;
        try {
            app(BookPageService::class)->start($item->fresh(), null, true, function () use (&$called) {
                $called = true;
                throw new \RuntimeException('EXECUTION_STALE_CONTEXT');
            });
            $this->fail('Stale legacy admission must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('EXECUTION_STALE_CONTEXT', $e->getMessage());
        }
        $this->assertTrue($called);
        $this->assertSame($before, $approval->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('s3_private')->allFiles());
        $this->assertDatabaseCount('codex_scene_runs', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_paid_status_is_not_consent_and_unpaid_approval_does_not_start_work(): void
    {
        $item = $this->fixture(20);
        $this->assertDatabaseCount('legacy_book_cover_approvals', 0);
        $item->order->updateQuietly(['paid_amount_cents' => 0, 'payment_status' => 'unpaid']);
        $this->approve($item->fresh());
        $this->assertStringContainsString('دفعة', app(BookPageService::class)->admissionReason($item->fresh()));
        app(BookPageService::class)->enroll();
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    public function test_stale_offer_and_double_submit_do_not_change_approval(): void
    {
        $item = $this->fixture();
        $token = app(LegacyBookAdmission::class)->offers($item)[0]['token'];
        $url = route('admin.orders.workspace.legacy-book-approve', [$item->order, $item]);
        $item->order->updateQuietly(['parent_notes' => 'new customer instruction']);
        $this->post($url, ['confirm' => 1, 'preview_token' => $token])->assertStatus(409);
        $this->assertDatabaseCount('legacy_book_cover_approvals', 0);
        $token = app(LegacyBookAdmission::class)->offers($item->fresh())[0]['token'];
        $this->post($url, ['confirm' => 1, 'preview_token' => $token])->assertRedirect(route('admin.orders.groups.show', $item->order));
        $this->post($url, ['confirm' => 1, 'preview_token' => $token])->assertStatus(409);
        $this->assertDatabaseCount('legacy_book_cover_approvals', 1);
    }

    public function test_changed_child_or_revoked_cover_blocks_start_and_worker_fingerprint(): void
    {
        $item = $this->fixture();
        $this->approve($item);
        $item->order->updateQuietly(['child_name' => 'طفل مختلف']);
        $this->assertStringContainsString('تغيرت', app(BookPageService::class)->admissionReason($item->fresh()));
        $item->order->updateQuietly(['child_name' => 'طفل اختبار']);
        OrderPreview::sole()->productGallery->update(['status' => 'revoked']);
        $this->assertNotNull(app(BookPageService::class)->admissionReason($item->fresh()));
        $this->assertDatabaseCount('codex_scene_runs', 0);
    }

    public function test_other_child_token_and_permissions_and_pause_are_enforced(): void
    {
        $item = $this->fixture();
        $other = $this->fixture();
        $token = app(LegacyBookAdmission::class)->offers($other)[0]['token'];
        $url = route('admin.orders.workspace.legacy-book-approve', [$item->order, $item]);
        $this->post($url, ['confirm' => 1, 'preview_token' => $token])->assertStatus(409);
        $token = app(LegacyBookAdmission::class)->offers($item)[0]['token'];
        DB::table('order_automation_controls')->insert(['checkout_key' => $item->order->checkoutGroupKey(), 'state' => 'paused', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->post($url, ['confirm' => 1, 'preview_token' => $token])->assertStatus(409);
        $this->actor->permissions()->detach(Permission::where('key', 'orders.automation.control')->value('id'));
        $this->actingAs($this->actor->fresh())->post($url, ['confirm' => 1, 'preview_token' => $token])->assertForbidden();
        $this->assertDatabaseCount('legacy_book_cover_approvals', 0);
    }

    public function test_printed_book_is_never_started_even_with_approval(): void
    {
        $item = $this->fixture();
        $this->approve($item);
        $item->order->updateQuietly(['printing_status' => 'completed']);
        $this->assertNotNull(app(BookPageService::class)->admissionReason($item->fresh()));
        $this->expectExceptionMessage('Book not eligible');
        app(BookPageService::class)->start($item->fresh(), null, true);
    }

    public function test_new_book_snapshot_captures_exact_variant_pages(): void
    {
        $old = $this->fixture(40);
        $item = $old->order->items()->create(['item_type' => 'product', 'product_id' => $old->product_id,
            'product_variant_id' => $old->product_variant_id, 'variant_snapshot' => $old->variant_snapshot,
            'title' => $old->title, 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
        $this->assertSame(40, $item->fresh()->variant_snapshot['book_page_count']);
        $this->assertSame(40, $item->fresh()->productionComponents->firstWhere('production_stage', 'book_interior')->quantity_per_item);
    }

    public function test_note_changes_can_be_reapproved_without_replacing_the_cover(): void
    {
        $item = $this->fixture();
        $first = $this->approve($item);
        $item->order->updateQuietly(['parent_notes' => 'Approved extra instruction']);
        $this->assertNotNull(app(BookPageService::class)->admissionReason($item->fresh()));
        $second = $this->approve($item->fresh());
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->order_preview_id, $second->order_preview_id);
        $this->assertDatabaseCount('legacy_book_cover_approvals', 2);
        $this->assertNull(app(BookPageService::class)->admissionReason($item->fresh()));
    }

    public function test_catalog_template_sync_keeps_existing_cover_consent(): void
    {
        $item = $this->fixture();
        $this->approve($item);
        $item->product->productionComponents()->where('production_stage', 'book_interior')->update(['prompt_template' => 'New coloring instruction for {{child_full_name}}']);
        app(BookPromptSync::class)->sync($item->product->fresh(), $this->actor->id);
        $this->assertNull(app(BookPageService::class)->admissionReason($item->fresh()));
        $run = app(BookPageService::class)->start($item->fresh(), null, true);
        $this->assertStringContainsString('New coloring instruction', $run->snapshot['prompt']);
        $this->assertDatabaseCount('legacy_book_cover_approvals', 1);
    }

    public function test_missing_cover_is_actionable_and_does_not_approve(): void
    {
        $item = $this->fixture();
        $preview = OrderPreview::sole();
        Storage::disk($preview->disk)->delete($preview->file_path);
        $offer = app(LegacyBookAdmission::class)->offers($item)[0];
        $this->assertArrayNotHasKey('token', $offer);
        $this->assertStringContainsString('أعد رفعها', $offer['error']);
        $this->assertDatabaseCount('legacy_book_cover_approvals', 0);
    }

    public function test_assignment_transfer_invalidates_an_open_staff_approval_form(): void
    {
        $item = $this->fixture();
        $this->actor->permissions()->detach(Permission::where('key', 'orders.view_all')->value('id'));
        $this->actor = $this->actor->fresh();
        DB::table('order_group_assignments')->insert(['checkout_group_key' => $item->order->checkoutGroupKey(), 'assigned_to_user_id' => $this->actor->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $token = app(LegacyBookAdmission::class)->offers($item)[0]['token'];
        $other = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        DB::table('order_group_assignments')->where('checkout_group_key', $item->order->checkoutGroupKey())->update(['assigned_to_user_id' => $other->id]);
        $this->actingAs($this->actor)->post(route('admin.orders.workspace.legacy-book-approve', [$item->order, $item]), ['confirm' => 1, 'preview_token' => $token])->assertForbidden();
        $this->assertDatabaseCount('legacy_book_cover_approvals', 0);
    }

    public function test_adopted_book_safe_pause_and_resume_keep_approval_and_assignment(): void
    {
        $item = $this->fixture();
        $approval = $this->approve($item);
        $run = app(BookPageService::class)->start($item->fresh(), null, true);
        $control = app(AutomationControl::class);
        $key = $item->order->checkoutGroupKey();
        $version = (int) DB::table('order_automation_controls')->where('checkout_key', $key)->value('version');
        $control->pause($item->order->fresh(), $this->actor, Request::create('/'), $version, 'Synthetic pause');
        $this->assertSame('paused', $run->fresh()->status);
        $version = (int) DB::table('order_automation_controls')->where('checkout_key', $key)->value('version');
        $control->resume($item->order->fresh(), $this->actor, Request::create('/'), $version);
        $this->assertSame('waiting_slot', $run->fresh()->status);
        $this->assertSame($approval->id, $run->fresh()->snapshot['legacy_approval_id']);
        $this->assertDatabaseCount('legacy_book_cover_approvals', 1);
        $this->assertDatabaseCount('codex_scene_runs', 1);
    }

    public function test_manually_refreshed_legacy_components_keep_cover_and_restore_forty_pages(): void
    {
        $item = $this->fixture(40);
        app(ProductProductionComponentSnapshotService::class)->refreshForItem($item);
        $item = $item->fresh();
        $item->productionComponents()->where('production_stage', 'book_interior')->update(['quantity_per_item' => 1]);
        $item = $item->fresh();
        $this->assertNull(data_get($item->variant_snapshot, 'book_page_count'));
        $ids = $item->productionComponents->pluck('id')->all();
        $this->assertCount(1, app(OrderGenerationBanner::class)->data($item->order)['legacyBooks']);
        $approval = $this->approve($item);
        $this->assertSame($ids, $item->fresh()->productionComponents->pluck('id')->all());
        $this->assertSame(40, $item->fresh()->variant_snapshot['book_page_count']);
        $this->assertSame(40, $item->fresh()->productionComponents->firstWhere('production_stage', 'book_interior')->quantity_per_item);
        $this->assertSame(1, collect($approval->configuration_before['components'])->firstWhere('production_stage', 'book_interior')['quantity_per_item']);
        $this->assertNull(app(BookPageService::class)->admissionReason($item->fresh()));
        $this->assertDatabaseCount('order_previews', 1);
    }

    public function test_legacy_catalog_key_collision_preserves_old_brief_and_starts_with_existing_cover(): void
    {
        $item = $this->fixture(20);
        $old = $item->productionComponents->sole();
        $catalog = $item->product->productionComponents()->where('production_stage', 'book_interior')->sole();
        $current = $catalog->only(['name', 'production_stage', 'prompt_template', 'quantity_per_item', 'sort_order', 'is_active']);
        $catalog->delete();
        $catalog = $item->product->productionComponents()->where('stable_key', $old->stable_key)->sole();
        $catalog->update($current);
        $before = $old->getAttributes();
        $preview = OrderPreview::sole();
        $files = Storage::disk('s3_private')->allFiles();
        $offers = app(LegacyBookAdmission::class)->offers($item->fresh());
        $this->assertSame(20, $offers[0]['pages']);
        $this->post(route('admin.orders.workspace.legacy-book-approve', [$item->order, $item]), [
            'confirm' => 1, 'preview_token' => $offers[0]['token'],
        ])->assertRedirect(route('admin.orders.groups.show', $item->order))->assertSessionHasNoErrors();
        $old = $old->fresh();
        $this->assertNotNull($old->retired_at);
        foreach (['id', 'stable_key', 'prompt_template', 'production_stage', 'quantity_per_item'] as $field) {
            $this->assertSame($before[$field], $old->getAttributes()[$field]);
        }
        $interior = $item->fresh()->productionComponents->firstWhere('production_stage', 'book_interior');
        $this->assertNotSame($old->stable_key, $interior->stable_key);
        $this->assertSame($catalog->id, $interior->product_production_component_id);
        $this->assertSame(20, $interior->quantity_per_item);
        $this->assertSame($before['prompt_template'], LegacyBookCoverApproval::sole()->configuration_before['components'][0]['prompt_template']);
        $run = app(BookPageService::class)->start($item->fresh(), null, true);
        $this->assertSame(20, $run->scene_count);
        $this->assertSame($preview->id, $run->snapshot['preview_id']);
        $this->assertSame($run->fingerprint, app(BookPageService::class)->fingerprint($run));
        $this->assertSame($files, Storage::disk('s3_private')->allFiles());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_retired_cover_keys_are_reserved_and_reapproval_keeps_the_new_unit_key(): void
    {
        $item = $this->fixture();
        $cover = $item->product->productionComponents()->where('production_stage', 'book_cover')->sole();
        foreach ([$cover->stable_key, 'legacy-book_cover-'.$cover->id] as $key) {
            $item->productionComponents()->create(['stable_key' => $key, 'name' => 'Historical component',
                'production_stage' => 'standard', 'quantity_per_item' => 1, 'prompt_template' => 'Retained history', 'retired_at' => now()]);
        }
        $old = DB::table('order_item_production_components')->where('order_item_id', $item->id)->whereNotNull('retired_at')->orderBy('id')->get()->toJson();
        $this->approve($item->fresh());
        $unit = $item->fresh()->productionComponents->firstWhere('production_stage', 'book_cover');
        $this->assertSame('legacy-book_cover-'.$cover->id.'-2', $unit->stable_key);
        $this->assertSame($old, DB::table('order_item_production_components')->where('order_item_id', $item->id)->whereIn('stable_key', [$cover->stable_key, 'legacy-book_cover-'.$cover->id])->orderBy('id')->get()->toJson());
        $item->order->updateQuietly(['parent_notes' => 'Reviewed additional instruction']);
        $this->approve($item->fresh());
        $this->assertSame($unit->id, $item->fresh()->productionComponents->firstWhere('production_stage', 'book_cover')->id);
        $this->assertSame($unit->stable_key, $item->fresh()->productionComponents->firstWhere('production_stage', 'book_cover')->stable_key);
        $this->assertDatabaseCount('legacy_book_cover_approvals', 2);
        $this->assertNull(app(BookPageService::class)->admissionReason($item->fresh()));
    }
}
