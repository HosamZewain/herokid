<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductionLayoutJob;
use App\Models\Order;
use App\Models\ProductionPrintLayout;
use App\Models\ProductionProject;
use App\Models\Story;
use App\Models\User;
use App\Services\ProductionStudio\ProductionLayoutBuilder;
use App\Support\ProductionStudio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionStudioLayoutPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('production_studio.enabled', true);
        Storage::fake('local');
    }

    public function test_layout_workspace_replaces_placeholder_and_shows_clear_controls(): void
    {
        [$admin, $project] = $this->project();

        $this->actingAs($admin)
            ->get(route('admin.production-studio.show', $project))
            ->assertOk()
            ->assertSee('توليد ملفات الإخراج والطباعة')
            ->assertSee('معاينة 28 صفحة')
            ->assertSee('Reader Order PDF')
            ->assertSee('7 A3')
            ->assertDontSee('مكان مخصص للمرحلة القادمة');
    }

    public function test_layout_generation_is_blocked_before_paid_or_queued_work_when_assets_are_missing(): void
    {
        Queue::fake();
        [$admin, $project] = $this->project();
        $cover = $this->asset($project, 'cover_image', 'cover.png', true);
        foreach (range(1, 13) as $number) {
            $project->scenes()->create([
                'scene_number' => $number,
                'title' => "Scene {$number}",
                'story_text' => "نص المشهد رقم {$number}.",
            ]);
        }
        $project->load(['order.story', 'scenes.approvedFinalImage', 'assets']);
        $payload = $this->payload($project, $cover->id);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.production-studio.layout.generate', $project), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertStringContainsString('المشهد 1 لا يحتوي على صورة نهائية معتمدة', $response->json('message'));

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('production_print_layouts', 0);
    }

    public function test_authorized_admin_can_queue_layout_without_changing_order_status(): void
    {
        Queue::fake();
        [$admin, $project] = $this->readyProject();
        $orderStatus = $project->order->status;
        $payload = app(ProductionLayoutBuilder::class)->defaults($project);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.production-studio.layout.generate', $project), $payload)
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('layout.status', 'queued');

        $layout = ProductionPrintLayout::firstOrFail();
        $response->assertJsonPath('status_url', route('admin.production-studio.layout.status', [$project, $layout]));
        Queue::assertPushed(GenerateProductionLayoutJob::class, fn ($job): bool => $job->layoutId === $layout->id);
        $this->assertSame($orderStatus, $project->order->fresh()->status);
    }

    public function test_layout_job_generates_private_reader_print_manifest_and_proof_files(): void
    {
        [$admin, $project] = $this->readyProject();
        $layout = $project->printLayouts()->create([
            'version_number' => 1,
            'status' => 'queued',
            'settings_json' => app(ProductionLayoutBuilder::class)->defaults($project),
            'generated_by_user_id' => $admin->id,
        ]);

        (new GenerateProductionLayoutJob($layout->id))->handle(app(ProductionLayoutBuilder::class));
        $layout->refresh();

        $this->assertSame('ready', $layout->status, $layout->error_message ?? '');
        $this->assertSame(28, data_get($layout->manifest_json, 'page_count'));
        $this->assertSame(13, data_get($layout->manifest_json, 'scene_count'));
        $this->assertSame(7, data_get($layout->manifest_json, 'sheet_count'));
        $this->assertSame('4961 × 3508 px at 300 DPI', data_get($layout->manifest_json, 'canvas_pixels'));
        $this->assertSame(['left_page' => 1, 'right_page' => 28], data_get($layout->manifest_json, 'sheets.0.front'));
        $this->assertSame(['left_page' => 27, 'right_page' => 2], data_get($layout->manifest_json, 'sheets.0.back'));

        foreach ([$layout->reader_pdf_path, $layout->print_pdf_path, $layout->manifest_path, $layout->proof_checklist_path] as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $rightPageSize = getimagesize(Storage::disk('local')->path("production-studio/projects/{$project->id}/layout/v1/pages/page-02.jpg"));
        $leftPageSize = getimagesize(Storage::disk('local')->path("production-studio/projects/{$project->id}/layout/v1/pages/page-03.jpg"));
        $this->assertSame([2481, 3508], [$rightPageSize[0], $rightPageSize[1]]);
        $this->assertSame([2480, 3508], [$leftPageSize[0], $leftPageSize[1]]);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($layout->reader_pdf_path));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($layout->print_pdf_path));

        $readerContents = Storage::disk('local')->get($layout->reader_pdf_path);
        $printContents = Storage::disk('local')->get($layout->print_pdf_path);
        preg_match_all('/\/Type\s*\/Page\b/', $readerContents, $readerPages);
        preg_match_all('/\/Type\s*\/Page\b/', $printContents, $printPages);
        $this->assertCount(28, $readerPages[0]);
        $this->assertCount(14, $printPages[0]);
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[0 0 595\.\d+ 841\.\d+\]/', $readerContents);
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[0 0 1190\.\d+ 841\.\d+\]/', $printContents);
        $this->assertSame('quality_check', $project->fresh()->current_stage);
        $this->assertSame('under_review', $project->order->fresh()->status);
    }

    public function test_layout_files_are_private_and_require_download_permission(): void
    {
        [$admin, $project] = $this->project();
        $layout = $project->printLayouts()->create([
            'version_number' => 1,
            'status' => 'ready',
            'reader_pdf_path' => "production-studio/projects/{$project->id}/layout/v1/reader-order.pdf",
            'print_pdf_path' => "production-studio/projects/{$project->id}/layout/v1/print.pdf",
            'manifest_path' => "production-studio/projects/{$project->id}/layout/v1/manifest.csv",
            'proof_checklist_path' => "production-studio/projects/{$project->id}/layout/v1/proof.pdf",
        ]);
        Storage::disk('local')->put($layout->reader_pdf_path, '%PDF-private');

        $unauthorized = User::create([
            'name' => 'Limited Admin',
            'email' => 'limited-layout@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);
        $unauthorized->permissions()->detach();

        $this->actingAs($unauthorized)
            ->get(route('admin.production-studio.layout.download', [$project, $layout, 'reader']))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.production-studio.layout.download', [$project, $layout, 'reader']))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_feature_flag_blocks_layout_routes_server_side(): void
    {
        [$admin, $project] = $this->project();
        Config::set('production_studio.enabled', false);

        $this->actingAs($admin)
            ->get(route('admin.production-studio.layout.preview', $project))
            ->assertNotFound();
    }

    public function test_manifest_supports_rtl_and_ltr_binding_without_changing_page_count(): void
    {
        $builder = app(ProductionLayoutBuilder::class);
        $rtl = $builder->buildManifest(['binding_direction' => 'rtl', 'duplex_flip' => 'short_edge']);
        $ltr = $builder->buildManifest(['binding_direction' => 'ltr', 'duplex_flip' => 'short_edge']);

        $this->assertSame(['left_page' => 1, 'right_page' => 28], $rtl['sheets'][0]['front']);
        $this->assertSame(['left_page' => 28, 'right_page' => 1], $ltr['sheets'][0]['front']);
        $this->assertSame(28, $rtl['page_count']);
        $this->assertSame(14, $rtl['printed_sides']);
    }

    private function readyProject(): array
    {
        [$admin, $project] = $this->project();
        $this->asset($project, 'cover_image', 'cover.png', true);

        foreach (range(1, 13) as $number) {
            $scene = $project->scenes()->create([
                'scene_number' => $number,
                'title' => "Scene {$number}",
                'story_text' => "نص المشهد رقم {$number}.",
                'visual_direction' => 'مشهد قصصي واسع.',
                'child_action_pose' => 'الطفل يتحرك داخل المشهد.',
            ]);
            $asset = $this->asset($project, 'scene_image', "scene-{$number}.png", true, $scene->id);
            $scene->update(['approved_final_image_path' => $asset->file_path]);
        }

        $project->load(['order.story', 'scenes.approvedFinalImage', 'assets']);

        return [$admin, $project];
    }

    private function project(): array
    {
        $admin = User::create([
            'name' => 'Layout Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
        ]);
        $story = Story::create([
            'title' => 'قصة اختبار الإخراج',
            'slug' => 'layout-story-'.Str::random(6),
            'short_desc' => 'قصة اختبار.',
            'age_range' => '6-9 سنوات',
            'language' => 'ar',
            'price' => 299,
            'active' => true,
        ]);
        $order = Order::create([
            'order_number' => 'HK-LAYOUT-'.Str::upper(Str::random(5)),
            'story_id' => $story->id,
            'parent_name' => 'ولي الأمر',
            'child_name' => 'رقية',
            'child_age' => 7,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => ['phone' => '01111111111'],
            'uploaded_photos' => [],
            'status' => 'under_review',
        ]);
        $project = ProductionStudio::createProjectFromOrder($order, $admin);

        return [$admin, $project];
    }

    private function asset(ProductionProject $project, string $type, string $filename, bool $final = false, ?int $sceneId = null)
    {
        $path = "production-studio/projects/{$project->id}/generated/{$filename}";
        Storage::disk('local')->put($path, $this->pngBytes());

        return $project->assets()->create([
            'production_scene_id' => $sceneId,
            'asset_type' => $type,
            'label' => $filename,
            'file_path' => $path,
            'status' => 'approved',
            'is_final' => $final,
        ]);
    }

    private function payload(ProductionProject $project, int $coverId): array
    {
        return [
            'book_title' => 'قصة اختبار الإخراج',
            'cover_subtitle' => 'بطولة رقية',
            'cover_title_position' => 'top',
            'back_cover_text' => 'نص الغلاف الخلفي',
            'website' => 'hero-kid.com',
            'binding_direction' => 'rtl',
            'duplex_flip' => 'short_edge',
            'font_size' => 20,
            'text_panel_opacity' => 92,
            'cover_asset_id' => $coverId,
            'scenes' => $project->scenes->mapWithKeys(fn ($scene): array => [(string) $scene->id => [
                'text_content' => $scene->story_text,
                'text_side' => 'left',
                'text_position' => 'bottom',
            ]])->all(),
        ];
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(120, 80);
        $color = imagecolorallocate($image, 73, 85, 201);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
