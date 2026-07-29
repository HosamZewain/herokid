<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Permission;
use App\Models\ProductionProject;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\OrderSceneTextService;
use App\Services\Stories\StorySceneParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorySceneTextHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_imports_exactly_thirteen_arabic_or_english_scenes(): void
    {
        $parser = app(StorySceneParser::class);
        $arabic = collect(range(1, 13))
            ->map(fn (int $number): string => "مشهد {$number}: عنوان {$number}\nنص المشهد {$number}")
            ->implode("\n");
        $english = collect(range(1, 13))
            ->map(fn (int $number): string => "Scene {$number}: Title {$number}\nScene text {$number}")
            ->implode("\n");

        $this->assertCount(13, $parser->parse($arabic));
        $this->assertCount(13, $parser->parse($english));
        $this->assertSame('عنوان 1', $parser->parse($arabic)[0]['title']);
        $this->assertSame('نص المشهد 1', $parser->parse($arabic)[0]['text_template']);
        $this->assertNull($parser->parse($arabic."\nمشهد 14: زائد"));
        $this->assertNull($parser->parse(str_replace('مشهد 13', 'مشهد 12', $arabic)));
    }

    public function test_story_save_persists_fixed_scene_slots_and_rejects_unknown_variables(): void
    {
        $admin = $this->admin(['stories.create', 'stories.update']);
        $payload = $this->storyPayload();
        $payload['gender'] = 'girl';
        $payload['scenes'] = $this->scenePayload();
        foreach (range(1, 12) as $sceneNumber) {
            $payload['scenes'][$sceneNumber]['alternate_text_template'] = 'نص بديل {{child_name}} للمشهد '.$sceneNumber;
        }

        $this->actingAs($admin)
            ->post(route('admin.stories.store'), $payload)
            ->assertRedirect(route('admin.stories.index'));

        $story = Story::where('slug', $payload['slug'])->firstOrFail();
        $this->assertCount(13, $story->sceneTemplates);
        $this->assertSame(12, $story->sceneTemplates->whereNotNull('text_template')->count());
        $this->assertSame(12, $story->sceneTemplates->whereNotNull('alternate_text_template')->count());
        $this->actingAs($admin)->get(route('admin.stories.edit', $story))
            ->assertOk()
            ->assertSee('نصوص المشاهد')
            ->assertSee('الأساسي: 12 من 13')
            ->assertSee('البديل: 12 من 13')
            ->assertSee('data-scene-import-alternate', false)
            ->assertSee('data-scene-alternate-template', false);

        $reordered = array_reverse($this->scenePayload(), preserve_keys: false);
        $updatePayload = array_merge($payload, ['scenes' => $reordered]);
        $this->actingAs($admin)
            ->put(route('admin.stories.update', $story), $updatePayload)
            ->assertRedirect(route('admin.stories.index'));
        $this->assertSame(
            'نص {{child_name}} للمشهد 1',
            $story->sceneTemplates()->where('scene_number', 1)->value('text_template'),
        );

        $payload['slug'] = 'invalid-scenes-'.Str::lower(Str::random(5));
        $payload['scenes'][4]['alternate_text_template'] = 'مرحبًا {{unknown_value}}';

        $this->actingAs($admin)
            ->post(route('admin.stories.store'), $payload)
            ->assertSessionHasErrors('scenes.4.alternate_text_template');
    }

    public function test_import_preview_requires_permission_and_never_saves_story_fields(): void
    {
        $admin = $this->admin(['stories.update']);
        $story = $this->story(['full_story' => 'النص الأصلي']);
        $content = collect(range(1, 13))
            ->map(fn (int $number): string => "Scene {$number}: Title {$number}\nText {$number}")
            ->implode("\n");

        $this->actingAs($admin)
            ->postJson(route('admin.stories.scene-import-preview'), ['full_story' => $content])
            ->assertOk()
            ->assertJsonCount(13, 'scenes')
            ->assertJsonPath('scenes.0.scene_number', 1)
            ->assertJsonPath('scenes.12.scene_number', 13);

        $this->assertSame('النص الأصلي', $story->fresh()->full_story);
    }

    public function test_checkout_stores_personalized_immutable_scene_snapshots(): void
    {
        Storage::fake('local');
        $story = $this->story();
        $this->addTemplates($story);
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)
            ->where('name', 'القاهرة')
            ->firstOrFail();

        $this->post(route('cart.store', $story->slug), [
            'child_name' => 'ليلى',
            'child_age' => 7,
            'child_gender' => 'girl',
            'privacy_consent' => '1',
            'next' => 'cart',
            'photos' => [$this->tinyPngUpload(), $this->tinyPngUpload('child-second.png')],
        ])->assertRedirect(route('cart.index'));

        $this->post(route('checkout.store'), [
            'parent_name' => 'ولي الأمر',
            'phone' => '201000000000',
            'delivery_country_id' => $egypt->id,
            'delivery_governorate_id' => $cairo->id,
            'city' => 'القاهرة',
            'street' => 'شارع الاختبار',
            'address_details' => 'عمارة 1',
        ])->assertRedirect(route('checkout.success'));

        $order = Order::with('sceneTextSnapshots')->firstOrFail();
        $this->assertCount(13, $order->sceneTextSnapshots);
        $this->assertSame(
            'ليلى عمرها 7 في قصة '.$story->title,
            $order->sceneTextSnapshots->first()->rendered_text,
        );
        $this->assertSame('original', $order->sceneTextSnapshots->first()->selected_text_variant);
        $this->assertSame('girl', $order->sceneTextSnapshots->first()->render_context_snapshot['child_gender']);
        $this->assertSame('both', $order->sceneTextSnapshots->first()->render_context_snapshot['story_gender']);

        $story->sceneTemplates()->where('scene_number', 1)->update(['text_template' => 'نص جديد']);
        $this->assertSame(
            'ليلى عمرها 7 في قصة '.$story->title,
            $order->sceneTextSnapshots()->where('scene_number', 1)->value('rendered_text'),
        );
    }

    public function test_gender_specific_snapshots_select_original_alternate_and_per_scene_fallback(): void
    {
        $story = $this->story(['gender' => 'girl']);
        $this->addTemplates($story, 'النص الأساسي {{child_name}}', 'النص البديل {{child_name}}');
        $story->sceneTemplates()->where('scene_number', 3)->update(['alternate_text_template' => null]);

        $girlOrder = $this->order($story, [
            'order_number' => 'HK-GIRL-ORIGINAL',
            'child_name' => 'ريم',
            'child_gender' => 'girl',
        ]);
        $boyOrder = $this->order($story, [
            'order_number' => 'HK-BOY-ALTERNATE',
            'child_name' => 'عمر',
            'child_gender' => 'boy',
        ]);
        $service = app(OrderSceneTextService::class);
        $service->snapshotForOrder($girlOrder, $story);
        $service->snapshotForOrder($boyOrder, $story);

        $this->assertSame(
            ['original'],
            $girlOrder->sceneTextSnapshots()->pluck('selected_text_variant')->unique()->values()->all(),
        );
        $this->assertSame('النص الأساسي ريم', $girlOrder->sceneTextSnapshots()->where('scene_number', 1)->value('rendered_text'));
        $this->assertSame('alternate', $boyOrder->sceneTextSnapshots()->where('scene_number', 1)->value('selected_text_variant'));
        $this->assertSame('النص البديل عمر', $boyOrder->sceneTextSnapshots()->where('scene_number', 1)->value('rendered_text'));
        $this->assertSame('original_fallback', $boyOrder->sceneTextSnapshots()->where('scene_number', 3)->value('selected_text_variant'));
        $this->assertSame('النص الأساسي عمر', $boyOrder->sceneTextSnapshots()->where('scene_number', 3)->value('rendered_text'));

        $presented = $service->present($boyOrder->fresh());
        $this->assertTrue($presented['has_gender_fallback']);
        $this->assertSame([3], $presented['gender_fallback_scene_numbers']);
        $this->assertSame('النص البديل — ولد', $presented['scenes'][0]['variant_label']);
        $this->assertSame('النص الأساسي بدل البديل', $presented['scenes'][2]['variant_label']);

        $story->update(['gender' => 'boy']);
        $story->sceneTemplates()->where('scene_number', 1)->update([
            'text_template' => 'تعديل لاحق',
            'alternate_text_template' => 'تعديل بديل لاحق',
        ]);
        $this->assertSame('alternate', $boyOrder->sceneTextSnapshots()->where('scene_number', 1)->value('selected_text_variant'));
        $this->assertSame('النص البديل عمر', $boyOrder->sceneTextSnapshots()->where('scene_number', 1)->value('rendered_text'));
    }

    public function test_boy_story_reverses_gender_mapping_and_both_story_always_uses_original(): void
    {
        $boyStory = $this->story(['gender' => 'boy']);
        $this->addTemplates($boyStory, 'ولد أساسي', 'بنت بديل');
        $girlOrder = $this->order($boyStory, ['order_number' => 'HK-BOY-STORY-GIRL', 'child_gender' => 'girl']);
        app(OrderSceneTextService::class)->snapshotForOrder($girlOrder, $boyStory);

        $this->assertSame('alternate', $girlOrder->sceneTextSnapshots()->first()->selected_text_variant);
        $this->assertSame('بنت بديل', $girlOrder->sceneTextSnapshots()->first()->rendered_text);

        $bothStory = $this->story(['gender' => 'both']);
        $this->addTemplates($bothStory, 'محايد أساسي', 'بديل غير مستخدم');
        $bothOrder = $this->order($bothStory, ['order_number' => 'HK-BOTH-STORY', 'child_gender' => 'boy']);
        app(OrderSceneTextService::class)->snapshotForOrder($bothOrder, $bothStory);

        $this->assertSame('original', $bothOrder->sceneTextSnapshots()->first()->selected_text_variant);
        $this->assertSame('محايد أساسي', $bothOrder->sceneTextSnapshots()->first()->rendered_text);
    }

    public function test_legacy_orders_choose_current_gender_variant_and_historical_snapshots_remain_original(): void
    {
        $story = $this->story(['gender' => 'girl']);
        $this->addTemplates($story, 'أساسي', 'بديل');
        $legacyOrder = $this->order($story, ['order_number' => 'HK-LIVE-GENDER', 'child_gender' => 'boy']);

        $live = app(OrderSceneTextService::class)->present($legacyOrder);
        $this->assertTrue($live['is_legacy_fallback']);
        $this->assertSame('بديل', $live['scenes'][0]['text']);
        $this->assertSame('alternate', $live['scenes'][0]['text_variant']);

        $historicalOrder = $this->order($story, ['order_number' => 'HK-HISTORICAL-SNAPSHOT', 'child_gender' => 'boy']);
        $historicalOrder->sceneTextSnapshots()->create([
            'scene_number' => 1,
            'title_snapshot' => 'عنوان قديم',
            'template_text_snapshot' => 'نص قديم',
            'rendered_text' => 'نص قديم',
            'selected_text_variant' => null,
            'render_context_snapshot' => ['child_name' => 'رنا'],
        ]);

        $historical = app(OrderSceneTextService::class)->present($historicalOrder->fresh());
        $this->assertFalse($historical['has_gender_fallback']);
        $this->assertSame('نسخة تاريخية', $historical['scenes'][0]['variant_label']);
        $this->assertSame('نص قديم', $historical['scenes'][0]['text']);
    }

    public function test_source_priority_is_production_then_snapshot_then_legacy_template(): void
    {
        $story = $this->story();
        $this->addTemplates($story);
        $order = $this->order($story);
        $service = app(OrderSceneTextService::class);
        $service->snapshotForOrder($order, $story);

        $project = ProductionProject::create([
            'order_id' => $order->id,
            'status' => 'draft',
            'current_stage' => 'intake',
        ]);
        $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'عنوان نهائي',
            'story_text' => 'النص النهائي من الاستوديو',
            'status' => 'draft',
        ]);
        $project->scenes()->create([
            'scene_number' => 2,
            'title' => 'فارغ',
            'story_text' => '',
            'status' => 'draft',
        ]);

        $presented = $service->present($order->fresh());
        $this->assertSame('production_scene', $presented['scenes'][0]['source']);
        $this->assertSame('النص النهائي من الاستوديو', $presented['scenes'][0]['text']);
        $this->assertSame('order_snapshot', $presented['scenes'][1]['source']);

        $legacyOrder = $this->order($story, ['order_number' => 'HK-LEGACY-001']);
        $legacy = $service->present($legacyOrder);
        $this->assertTrue($legacy['is_legacy_fallback']);
        $this->assertSame('story_template_fallback', $legacy['scenes'][0]['source']);
    }

    public function test_production_draft_seeds_from_order_snapshots_without_changing_story(): void
    {
        $admin = $this->admin(['production_studio.story_edit']);
        $story = $this->story(['full_story' => 'قصة كاملة غير مقسمة', 'gender' => 'girl']);
        $this->addTemplates(
            $story,
            '{{child_name}} عمرها {{child_age}} في قصة {{story_title}}',
            '{{child_name}} عمره {{child_age}} في قصة {{story_title}}',
        );
        $order = $this->order($story, ['child_name' => 'عمر', 'child_gender' => 'boy']);
        app(OrderSceneTextService::class)->snapshotForOrder($order, $story);
        $project = ProductionProject::create([
            'order_id' => $order->id,
            'status' => 'draft',
            'current_stage' => 'intake',
        ]);
        $originalStory = $story->only(['title', 'full_story', 'updated_at']);

        $this->actingAs($admin)
            ->post(route('admin.production-studio.story-versions.from-story', $project))
            ->assertRedirect();

        $this->assertCount(13, $project->scenes);
        $this->assertSame('عمر عمره 6 في قصة '.$story->title, $project->scenes->first()->story_text);
        $this->assertEquals($originalStory, $story->fresh()->only(['title', 'full_story', 'updated_at']));
    }

    public function test_order_page_renders_copy_handoff_and_product_only_orders_hide_it(): void
    {
        $admin = $this->admin(['orders.view', 'stories.update']);
        $story = $this->story();
        $this->addTemplates($story);
        $order = $this->order($story);
        app(OrderSceneTextService::class)->snapshotForOrder($order, $story);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('نصوص المشاهد')
            ->assertSee('المشاهد الجاهزة: 13/13')
            ->assertSee('data-scene-text-copy-all', false)
            ->assertSee('data-scene-text-copy', false)
            ->assertSee('readonly', false)
            ->assertSee('المشهد 13');

        $productOnly = Order::create([
            'order_number' => 'HK-PRODUCT-ONLY',
            'parent_name' => 'عميل',
            'story_id' => null,
            'delivery_details' => ['phone' => '201000000000'],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $productOnly))
            ->assertOk()
            ->assertDontSee('data-order-scene-texts', false);
    }

    public function test_order_page_warns_about_gender_fallback_and_production_text_clears_that_scene_warning(): void
    {
        $admin = $this->admin(['orders.view']);
        $story = $this->story(['gender' => 'girl']);
        $this->addTemplates($story, 'أساسي', 'بديل');
        $story->sceneTemplates()->whereIn('scene_number', [2, 5])->update(['alternate_text_template' => null]);
        $order = $this->order($story, ['child_gender' => 'boy']);
        $service = app(OrderSceneTextService::class);
        $service->snapshotForOrder($order, $story);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('بعض مشاهد النسخة البديلة غير مكتملة')
            ->assertSee('2، 5')
            ->assertSee('النص الأساسي بدل البديل');

        $project = ProductionProject::create([
            'order_id' => $order->id,
            'status' => 'draft',
            'current_stage' => 'intake',
        ]);
        $project->scenes()->create([
            'scene_number' => 2,
            'story_text' => 'نص إنتاج نهائي',
            'status' => 'draft',
        ]);

        $presented = $service->present($order->fresh());
        $this->assertSame([5], $presented['gender_fallback_scene_numbers']);
    }

    public function test_scene_handoff_query_count_does_not_grow_with_project_scene_count(): void
    {
        $story = $this->story();
        $this->addTemplates($story);
        $order = $this->order($story);
        app(OrderSceneTextService::class)->snapshotForOrder($order, $story);
        $project = ProductionProject::create([
            'order_id' => $order->id,
            'status' => 'draft',
            'current_stage' => 'intake',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(OrderSceneTextService::class)->present($order->fresh());
        $withoutScenes = count(DB::getQueryLog());

        foreach (range(1, 13) as $sceneNumber) {
            $project->scenes()->create([
                'scene_number' => $sceneNumber,
                'title' => 'Scene '.$sceneNumber,
                'story_text' => 'Text '.$sceneNumber,
                'status' => 'draft',
            ]);
        }

        DB::flushQueryLog();
        app(OrderSceneTextService::class)->present($order->fresh());
        $withScenes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($withoutScenes, $withScenes);
    }

    private function admin(array $permissions): User
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));

        return $admin->refresh();
    }

    private function story(array $overrides = []): Story
    {
        return Story::create(array_merge([
            'title' => 'رحلة النجوم',
            'slug' => 'scene-story-'.Str::lower(Str::random(6)),
            'language' => 'ar',
            'gender' => 'both',
            'price' => 349,
            'active' => true,
        ], $overrides));
    }

    private function order(Story $story, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'HK-SCENE-'.Str::upper(Str::random(6)),
            'parent_name' => 'ولي الأمر',
            'story_id' => $story->id,
            'child_name' => 'رنا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'status' => 'new',
            'delivery_details' => ['phone' => '201000000000'],
            'uploaded_photos' => [],
        ], $overrides));
    }

    private function addTemplates(
        Story $story,
        string $original = '{{child_name}} عمرها {{child_age}} في قصة {{story_title}}',
        ?string $alternate = null,
    ): void {
        foreach (range(1, 13) as $sceneNumber) {
            $story->sceneTemplates()->create([
                'scene_number' => $sceneNumber,
                'title' => 'عنوان '.$sceneNumber,
                'text_template' => $original,
                'alternate_text_template' => $alternate,
            ]);
        }
    }

    private function storyPayload(): array
    {
        return [
            'title' => 'قصة المشاهد',
            'slug' => 'scene-form-'.Str::lower(Str::random(6)),
            'language' => 'ar',
            'gender' => 'both',
            'price' => 349,
            'active' => 1,
        ];
    }

    private function scenePayload(): array
    {
        return collect(range(1, 13))->mapWithKeys(fn (int $sceneNumber): array => [
            $sceneNumber => [
                'scene_number' => $sceneNumber,
                'title' => 'عنوان '.$sceneNumber,
                'text_template' => $sceneNumber === 13 ? '' : 'نص {{child_name}} للمشهد '.$sceneNumber,
            ],
        ])->all();
    }

    private function tinyPngUpload(string $name = 'child.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'scene-photo-');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
        ));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
