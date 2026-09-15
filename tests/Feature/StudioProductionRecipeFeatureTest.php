<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Orders\ProductProductionComponentSnapshotService;
use App\Support\StudioProductionRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudioProductionRecipeFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_is_additive_and_legacy_components_remain_valid(): void
    {
        foreach (['product_production_components', 'order_item_production_components'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['studio_enabled', 'studio_workflow', 'studio_recipe_version', 'studio_recipe']));
        }

        [$product, $component] = $this->productAndComponent(configureStudio: false);
        [$order, $item] = $this->orderAndItem($product);
        $snapshot = $item->productionComponents()->firstOrFail();

        $this->assertFalse($component->fresh()->studio_enabled);
        $this->assertNull($component->fresh()->studio_recipe);
        $this->assertFalse($snapshot->studio_enabled);
        $this->assertNull($snapshot->studio_recipe);
        $this->assertSame('new', $order->status);
    }

    public function test_admin_structured_form_saves_recipe_and_renders_settings(): void
    {
        [$product] = $this->productAndComponent(configureStudio: false);

        $this->actingAs($this->admin())->put(route('admin.products.update', $product), [
            ...$this->productPayload($product),
            'production_components_present' => 1,
            'production_components' => [$this->componentPayload()],
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.products.edit', $product));

        $component = $product->fresh()->productionComponents()->firstOrFail();
        $this->assertTrue($component->studio_enabled);
        $this->assertSame('personalized-card', $component->studio_workflow);
        $this->assertSame(1, $component->studio_recipe_version);
        $this->assertSame(60, $component->studio_recipe['canvas']['width_mm']);

        $this->actingAs($this->admin())->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('تفعيل هذا الجزء داخل HeroKid Studio')
            ->assertSee('name="production_components[0][studio_recipe][canvas][width_mm]"', false);
    }

    public function test_new_order_snapshots_recipe_and_later_product_edits_do_not_change_it(): void
    {
        [$product, $component] = $this->productAndComponent();
        [, $item] = $this->orderAndItem($product);
        $snapshot = $item->productionComponents()->firstOrFail();
        $snapshotId = $snapshot->id;

        $this->assertTrue($snapshot->studio_enabled);
        $this->assertSame('child-id-v1', $snapshot->studio_recipe['template_key']);

        $changed = $this->recipe();
        $changed['template_key'] = 'child-id-v2';
        $component->update(['studio_recipe' => $changed]);
        app(ProductProductionComponentSnapshotService::class)->captureForItem($item->fresh());

        $this->assertSame($snapshotId, $item->productionComponents()->firstOrFail()->id);
        $this->assertSame('child-id-v1', $item->productionComponents()->firstOrFail()->studio_recipe['template_key']);
    }

    public function test_admin_rejects_an_unknown_workflow_with_a_recipe_error(): void
    {
        [$product] = $this->productAndComponent(configureStudio: false);
        $payload = $this->componentPayload();
        $payload['studio_workflow'] = 'dangerous-custom-workflow';
        $payload['studio_recipe']['workflow'] = 'dangerous-custom-workflow';

        $this->actingAs($this->admin())->put(route('admin.products.update', $product), [
            ...$this->productPayload($product),
            'production_components_present' => 1,
            'production_components' => [$payload],
        ])->assertSessionHasErrors('production_components.0.studio_recipe');

        $this->assertFalse($product->productionComponents()->firstOrFail()->fresh()->studio_enabled);
    }

    public function test_product_duplication_copies_recipe_without_sharing_component_record(): void
    {
        [$source, $sourceComponent] = $this->productAndComponent();

        $this->actingAs($this->admin())->post(route('admin.products.store'), [
            ...$this->productPayload($source),
            'duplicate_source_id' => $source->id,
            'name_ar' => 'نسخة بطاقة',
            'slug' => 'studio-card-copy',
            'is_active' => 0,
        ])->assertSessionHasNoErrors();

        $copy = Product::where('slug', 'studio-card-copy')->firstOrFail();
        $copyComponent = $copy->productionComponents()->firstOrFail();
        $this->assertNotSame($sourceComponent->id, $copyComponent->id);
        $this->assertEquals($sourceComponent->studio_recipe, $copyComponent->studio_recipe);
        $this->assertTrue($copyComponent->studio_enabled);
    }

    public function test_studio_endpoint_returns_snapshot_recipe_and_safe_verbatim_production_fields(): void
    {
        [$product] = $this->productAndComponent();
        [$order, $item] = $this->orderAndItem($product, [
            'fields' => [
                'child_name' => ['value' => 'محمد أحمد'],
                'school_name' => ['value' => 'HeroKid School'],
                'class_name' => ['value' => 'KG 2 / أ'],
                'parent_phone_primary' => ['value' => '01012345678'],
                'parent_phone_secondary' => ['value' => '+971501234567'],
                'parent_notes' => ['value' => 'استخدم الصورة الثانية كما هي'],
            ],
        ]);
        $token = $this->agent()->createToken('studio-recipe', ['agent', 'agent:orders.read'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('production_units.0.unit_key', 'product:'.$item->id)
            ->assertJsonPath('production_units.0.studio_production.enabled', true)
            ->assertJsonPath('production_units.0.studio_production.workflow', 'personalized-card')
            ->assertJsonPath('production_units.0.studio_production.recipe_version', 1)
            ->assertJsonPath('production_units.0.studio_production.recipe_source', 'order-item-snapshot')
            ->assertJsonPath('production_units.0.studio_production.recipe.canvas.width_mm', 60)
            ->assertJsonPath('production_units.0.production_fields.0.value', 'محمد أحمد');

        $fields = collect($response->json('production_units.0.production_fields'))->keyBy('key');
        $this->assertSame('01012345678', $fields['parent_phone_primary']['value']);
        $this->assertSame('+971501234567', $fields['parent_phone_secondary']['value']);
        $this->assertSame('استخدم الصورة الثانية كما هي', $fields['special_notes']['value']);
        $this->assertStringNotContainsString('عنوان سري', $response->getContent());
        $this->assertFalse(OrderGroupAssignment::where('checkout_group_key', $order->checkout_group_key)->exists());
        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_disabled_snapshot_is_returned_without_recipe_and_missing_optional_fields_are_accepted(): void
    {
        [$product] = $this->productAndComponent(configureStudio: false);
        [$order] = $this->orderAndItem($product, []);
        $token = $this->agent()->createToken('legacy-product', ['agent', 'agent:orders.read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('production_units.0.studio_production.enabled', false)
            ->assertJsonCount(0, 'production_units.0.production_fields')
            ->assertJsonMissingPath('production_units.0.studio_production.recipe');
    }

    public function test_source_revision_changes_only_when_snapshot_recipe_changes(): void
    {
        [$product] = $this->productAndComponent();
        [$order, $item] = $this->orderAndItem($product);
        $token = $this->agent()->createToken('revision', ['agent', 'agent:orders.read'])->plainTextToken;
        $first = $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $unchanged = $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)->assertOk()->json();
        $this->assertSame($first['order']['source_revision'], $unchanged['order']['source_revision']);

        $recipe = $item->productionComponents()->firstOrFail()->studio_recipe;
        $recipe['output']['default_copies'] = 2;
        $item->productionComponents()->firstOrFail()->update(['studio_recipe' => $recipe]);
        $this->app['auth']->forgetGuards();
        $changed = $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)->assertOk()->json();
        $this->assertNotSame($first['order']['source_revision'], $changed['order']['source_revision']);
    }

    public function test_source_revision_changes_when_a_returned_snapshotted_production_field_changes(): void
    {
        [$product] = $this->productAndComponent();
        [$order, $item] = $this->orderAndItem($product, ['child_name' => 'سليم']);
        $token = $this->agent()->createToken('field-revision', ['agent', 'agent:orders.read'])->plainTextToken;
        $first = $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)->assertOk()->json('order.source_revision');

        $item->update(['personalization_snapshot' => ['child_name' => 'سليم أحمد']]);
        $this->app['auth']->forgetGuards();
        $second = $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)->assertOk()->json('order.source_revision');

        $this->assertNotSame($first, $second);
    }

    public function test_backfill_is_dry_run_by_default_idempotent_and_never_overwrites_existing_recipe(): void
    {
        [$product, $component] = $this->productAndComponent(configureStudio: false);
        [, $item] = $this->orderAndItem($product);
        $component->update([
            'studio_enabled' => true,
            'studio_workflow' => 'personalized-card',
            'studio_recipe_version' => 1,
            'studio_recipe' => $this->recipe(),
        ]);

        $this->artisan('studio:backfill-production-recipes')->assertSuccessful()->expectsOutputToContain('DRY RUN');
        $this->assertNull($item->productionComponents()->firstOrFail()->studio_recipe);
        $this->artisan('studio:backfill-production-recipes', ['--apply' => true])->assertSuccessful();
        $snapshot = $item->productionComponents()->firstOrFail();
        $this->assertSame('child-id-v1', $snapshot->studio_recipe['template_key']);

        $changed = $this->recipe();
        $changed['template_key'] = 'must-not-overwrite';
        $component->update(['studio_recipe' => $changed]);
        $this->artisan('studio:backfill-production-recipes', ['--apply' => true])->assertSuccessful();
        $this->assertSame('child-id-v1', $snapshot->fresh()->studio_recipe['template_key']);
    }

    private function productAndComponent(bool $configureStudio = true): array
    {
        $category = ProductCategory::create(['name_ar' => 'منتجات Studio', 'slug' => 'studio-'.uniqid(), 'is_active' => true, 'show_in_store' => true]);
        $product = Product::create(['product_category_id' => $category->id, 'name_ar' => 'بطاقة طفل', 'name_en' => 'Child Card', 'slug' => 'child-card-'.uniqid(), 'sku' => 'CARD', 'price_cents' => 10000, 'fulfillment_type' => 'physical', 'purchase_mode' => 'standalone', 'personalization_mode' => 'collect_child_details', 'inventory_mode' => 'no_tracking', 'is_active' => true]);
        $component = $product->productionComponents()->create([
            'stable_key' => 'main', 'name' => 'البطاقة', 'prompt_template' => 'Create {{product_name}} for {{child_full_name}}', 'quantity_per_item' => 1, 'sort_order' => 0, 'is_active' => true,
            'studio_enabled' => $configureStudio,
            'studio_workflow' => $configureStudio ? 'personalized-card' : null,
            'studio_recipe_version' => $configureStudio ? 1 : null,
            'studio_recipe' => $configureStudio ? $this->recipe() : null,
        ]);

        return [$product, $component];
    }

    private function orderAndItem(Product $product, array $personalization = []): array
    {
        $order = Order::create(['order_number' => 'HK-STUDIO-'.uniqid(), 'checkout_group_key' => 'GROUP-'.uniqid(), 'status' => 'new', 'uploaded_photos' => [], 'delivery_details' => ['address' => 'عنوان سري']]);
        $item = $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => $product->name_ar, 'sku' => $product->sku, 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000, 'personalization_mode' => 'collect_child_details', 'personalization_snapshot' => $personalization]);

        return [$order, $item->fresh()];
    }

    private function componentPayload(): array
    {
        return ['stable_key' => 'main', 'name' => 'البطاقة', 'prompt_template' => 'Create {{product_name}}', 'quantity_per_item' => 1, 'is_active' => 1, 'studio_enabled' => 1, 'studio_workflow' => 'personalized-card', 'studio_recipe_version' => 1, 'studio_recipe' => $this->recipe()];
    }

    private function recipe(): array
    {
        return StudioProductionRecipe::defaults();
    }

    private function productPayload(Product $product): array
    {
        return ['product_category_id' => $product->product_category_id, 'name_ar' => $product->name_ar, 'name_en' => $product->name_en, 'slug' => $product->slug, 'price' => 100, 'fulfillment_type' => 'physical', 'purchase_mode' => 'standalone', 'personalization_mode' => 'none', 'inventory_mode' => 'no_tracking', 'is_active' => 1];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function agent(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => true]);
    }
}
