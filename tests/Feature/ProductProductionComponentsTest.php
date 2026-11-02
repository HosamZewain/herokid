<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Orders\ProductProductionComponentSnapshotService;
use App\Services\Orders\ProductProductionPromptSyncService;
use App\Support\ProductProductionPrompt;
use App\Support\ProductVariantSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductProductionComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_ordered_independent_production_components(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->put(route('admin.products.update', $product), [
                ...$this->productPayload($product),
                'production_components_present' => 1,
                'production_components' => [
                    [
                        'stable_key' => 'large',
                        'name' => 'الملصقات الكبيرة',
                        'quantity_per_item' => 2,
                        'prompt_template' => 'Large {{component_name}} x {{component_quantity}} for {{child_full_name}}',
                        'is_active' => 1,
                    ],
                    [
                        'stable_key' => 'small',
                        'name' => 'الملصقات الصغيرة',
                        'quantity_per_item' => 5,
                        'prompt_template' => 'Small {{component_name}} x {{component_quantity}} for {{child_full_name}}',
                        'is_active' => 1,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.products.edit', $product));

        $components = $product->fresh()->productionComponents;
        $this->assertCount(2, $components);
        $this->assertSame(['الملصقات الكبيرة', 'الملصقات الصغيرة'], $components->pluck('name')->all());
        $this->assertSame([2, 5], $components->pluck('quantity_per_item')->all());
        $this->assertSame([0, 1], $components->pluck('sort_order')->all());
        $this->assertSame(['large', 'small'], $components->pluck('stable_key')->all());
        $this->assertNull($product->fresh()->production_prompt_template);

        $this->actingAs($this->admin())
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('برومبت إنتاج المنتج')
            ->assertSee('الملصقات الكبيرة')
            ->assertSee('الملصقات الصغيرة')
            ->assertSee('name="production_components[0][prompt_template]"', false);
    }

    public function test_component_templates_reject_unknown_variables(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->from(route('admin.products.edit', $product))
            ->put(route('admin.products.update', $product), [
                ...$this->productPayload($product),
                'production_components_present' => 1,
                'production_components' => [[
                    'name' => 'جزء',
                    'quantity_per_item' => 1,
                    'prompt_template' => 'Invalid {{customer_secret}}',
                    'is_active' => 1,
                ]],
            ])
            ->assertSessionHasErrors('production_components.0.prompt_template');

        $this->assertDatabaseCount('product_production_components', 0);
    }

    public function test_order_snapshots_component_structure_but_uses_current_product_prompt_automatically(): void
    {
        $product = $this->product();
        $large = $product->productionComponents()->create([
            'stable_key' => 'large',
            'name' => 'الكبير',
            'prompt_template' => 'كبير: {{child_full_name}} — {{component_quantity}}',
            'quantity_per_item' => 2,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $small = $product->productionComponents()->create([
            'stable_key' => 'small',
            'name' => 'الصغير',
            'prompt_template' => 'صغير: {{child_full_name}} — {{component_quantity}}',
            'quantity_per_item' => 5,
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $product->unsetRelation('productionComponents');
        $order = Order::create([
            'order_number' => 'HK-MULTI-COMPONENT',
            'child_name' => 'ليلى أحمد',
            'status' => 'new',
        ]);
        $item = $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 2,
            'unit_price_cents' => 10000,
            'total_price_cents' => 20000,
            'personalization_snapshot' => ['child_name' => 'ليلى أحمد'],
        ]);

        $prompts = ProductProductionPrompt::forItem($item->fresh());
        $this->assertCount(2, $prompts);
        $this->assertSame([
            'product:'.$item->id.':component:large',
            'product:'.$item->id.':component:small',
        ], $prompts->pluck('unit_key')->all());
        $this->assertSame([4, 10], $prompts->pluck('quantity')->all());
        $this->assertSame('كبير: ليلى أحمد — 4', $prompts[0]['prompt']);
        $this->assertSame('صغير: ليلى أحمد — 10', $prompts[1]['prompt']);
        $this->assertSame(['live_component_template', 'live_component_template'], $prompts->pluck('prompt_source')->all());
        $this->assertDatabaseCount('order_item_production_components', 2);

        $large->update(['prompt_template' => 'نسخة جديدة: {{child_full_name}}']);
        $small->delete();
        $historicalPrompts = ProductProductionPrompt::forItem($item->fresh());
        $this->assertCount(2, $historicalPrompts);
        $this->assertSame('نسخة جديدة: ليلى أحمد', $historicalPrompts[0]['prompt']);
        $this->assertSame('صغير: ليلى أحمد — 10', $historicalPrompts[1]['prompt']);
        $this->assertSame(['live_component_template', 'order_component_snapshot'], $historicalPrompts->pluck('prompt_source')->all());

        $this->actingAs($this->admin())
            ->get(route('admin.orders.products.production', [$order, $item]))
            ->assertOk()
            ->assertSee('product-production-prompt-'.$item->id.'-component-large', false)
            ->assertSee('product-production-prompt-'.$item->id.'-component-small', false)
            ->assertSee('الكبير')
            ->assertSee('الصغير');

        $this->actingAs($this->admin())
            ->post(route('admin.orders.products.production-prompt.use-current', [$order, $item]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $refreshed = ProductProductionPrompt::forItem($item->fresh());
        $this->assertCount(1, $refreshed);
        $this->assertSame('product:'.$item->id, $refreshed[0]['unit_key']);
        $this->assertSame('نسخة جديدة: ليلى أحمد', $refreshed[0]['prompt']);
    }

    public function test_admin_product_prompt_edit_updates_all_linked_orders_without_changing_other_order_data(): void
    {
        $product = $this->product();
        $component = $product->productionComponents()->create([
            'stable_key' => 'main',
            'name' => 'المنتج الرئيسي',
            'prompt_template' => 'النسخة القديمة: {{child_full_name}}',
            'quantity_per_item' => 1,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $otherProduct = $this->product();
        $otherProduct->productionComponents()->create([
            'stable_key' => 'main',
            'name' => 'منتج آخر',
            'prompt_template' => 'برومبت مختلف: {{child_full_name}}',
            'quantity_per_item' => 1,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $firstOrder = Order::create([
            'order_number' => 'HK-PROMPT-SYNC-ONE',
            'status' => 'new',
            'payment_status' => 'paid',
        ]);
        $firstItem = $firstOrder->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 10000,
            'total_price_cents' => 10000,
            'personalization_snapshot' => ['child_name' => 'آدم'],
        ]);
        $secondOrder = Order::create([
            'order_number' => 'HK-PROMPT-SYNC-TWO',
            'status' => 'ready_preview',
            'payment_status' => 'unpaid',
        ]);
        $secondItem = $secondOrder->items()->create([
            'item_type' => 'product_add_on',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 2,
            'unit_price_cents' => 10000,
            'total_price_cents' => 20000,
            'personalization_snapshot' => ['child_name' => 'ليلى'],
        ]);
        $otherOrder = Order::create(['order_number' => 'HK-PROMPT-SYNC-OTHER', 'status' => 'new']);
        $otherItem = $otherOrder->items()->create([
            'item_type' => 'product',
            'product_id' => $otherProduct->id,
            'title' => $otherProduct->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 10000,
            'total_price_cents' => 10000,
            'personalization_snapshot' => ['child_name' => 'سليم'],
        ]);

        $this->assertSame('النسخة القديمة: آدم', ProductProductionPrompt::renderForItem($firstItem->fresh()));
        $this->assertSame('النسخة القديمة: ليلى', ProductProductionPrompt::renderForItem($secondItem->fresh()));

        $this->actingAs($this->admin())
            ->put(route('admin.products.update', $product), [
                ...$this->productPayload($product),
                'production_components_present' => 1,
                'production_components' => [[
                    'stable_key' => 'main',
                    'name' => 'المنتج الرئيسي',
                    'quantity_per_item' => 1,
                    'prompt_template' => 'النسخة الجديدة: {{child_full_name}}',
                    'is_active' => 1,
                ]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.products.edit', $product));

        $this->assertSame('النسخة الجديدة: آدم', ProductProductionPrompt::renderForItem($firstItem->fresh()));
        $this->assertSame('النسخة الجديدة: ليلى', ProductProductionPrompt::renderForItem($secondItem->fresh()));
        $this->assertSame('برومبت مختلف: سليم', ProductProductionPrompt::renderForItem($otherItem->fresh()));
        $this->assertSame('النسخة الجديدة: {{child_full_name}}', $component->orderItemSnapshots()->pluck('prompt_template')->unique()->sole());
        $this->assertSame('new', $firstOrder->fresh()->status);
        $this->assertSame('paid', $firstOrder->payment_status);
        $this->assertSame('ready_preview', $secondOrder->fresh()->status);
        $this->assertSame('unpaid', $secondOrder->payment_status);
        $this->assertSame(10000, $firstItem->fresh()->total_price_cents);
        $this->assertSame(20000, $secondItem->fresh()->total_price_cents);
    }

    public function test_inactive_components_are_not_snapshotted_for_new_orders(): void
    {
        $product = $this->product();
        $product->productionComponents()->createMany([
            [
                'stable_key' => 'active',
                'name' => 'فعال',
                'prompt_template' => 'Active prompt',
                'quantity_per_item' => 1,
                'sort_order' => 0,
                'is_active' => true,
            ],
            [
                'stable_key' => 'disabled',
                'name' => 'متوقف',
                'prompt_template' => 'Disabled prompt',
                'quantity_per_item' => 1,
                'sort_order' => 1,
                'is_active' => false,
            ],
        ]);
        $order = Order::create(['order_number' => 'HK-ACTIVE-COMPONENT', 'status' => 'new']);
        $item = $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 10000,
            'total_price_cents' => 10000,
        ]);

        $prompts = ProductProductionPrompt::forItem($item->fresh());
        $this->assertCount(1, $prompts);
        $this->assertSame('active', $prompts[0]['component_key']);
        $this->assertSame('product:'.$item->id, $prompts[0]['unit_key']);
        $this->assertDatabaseCount('order_item_production_components', 1);
    }

    public function test_product_duplication_copies_component_definitions_without_sharing_records(): void
    {
        $source = $this->product();
        $source->productionComponents()->create([
            'stable_key' => 'poster',
            'name' => 'البوستر',
            'prompt_template' => 'Poster for {{child_full_name}}',
            'quantity_per_item' => 2,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), [
                ...$this->productPayload($source),
                'duplicate_source_id' => $source->id,
                'name_ar' => 'نسخة باقة استيكرات',
                'slug' => 'duplicated-component-bundle',
                'is_active' => 0,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $duplicate = Product::query()->where('slug', 'duplicated-component-bundle')->firstOrFail();
        $this->assertFalse($duplicate->is_active);
        $this->assertNotSame($source->id, $duplicate->id);
        $this->assertSame(['poster'], $duplicate->productionComponents->pluck('stable_key')->all());
        $this->assertSame('Poster for {{child_full_name}}', $duplicate->productionComponents->first()->prompt_template);
        $this->assertNotSame(
            $source->productionComponents()->firstOrFail()->id,
            $duplicate->productionComponents->first()->id,
        );
    }

    public function test_coloring_book_has_separate_cover_and_interior_prompts_and_variant_page_snapshot(): void
    {
        $product = $this->product();
        $components = [
            ['stable_key' => 'cover', 'name' => 'كفر', 'production_stage' => 'book_cover', 'quantity_per_item' => 1, 'prompt_template' => 'Cover {{child_full_name}} only', 'is_active' => 1],
            ['stable_key' => 'pages', 'name' => 'صفحات', 'production_stage' => 'book_interior', 'quantity_per_item' => 12, 'prompt_template' => '{{book_page_count}} pages for {{child_full_name}}', 'is_active' => 1],
        ];
        $this->actingAs($this->admin())->put(route('admin.products.update', $product), [
            ...$this->productPayload($product), 'production_components_present' => 1, 'production_components' => $components,
        ])->assertSessionHasNoErrors();
        $variant = $product->variants()->create(['name_ar' => 'نسخة 20 صفحة', 'book_page_count' => 20, 'is_active' => true]);
        $order = Order::create(['order_number' => 'BOOK-TEST', 'child_name' => 'رقية', 'status' => 'new']);
        $item = $order->items()->create(['item_type' => 'product', 'product_id' => $product->id,
            'product_variant_id' => $variant->id, 'variant_snapshot' => ProductVariantSnapshot::make($product, $variant),
            'title' => 'كتاب رقية', 'quantity' => 2, 'unit_price_cents' => 10000, 'total_price_cents' => 20000]);
        $units = ProductProductionPrompt::forItem($item->fresh());
        $this->assertSame(['book_cover', 'book_interior'], $units->pluck('production_stage')->all());
        $this->assertSame([2, 40], $units->pluck('quantity')->all());
        $this->assertSame('20 pages for رقية', $units[1]['prompt']);
        $this->assertSame('product:'.$item->id.':component:cover', $units[0]['unit_key']);
        $product->productionComponents()->update(['prompt_template' => 'changed', 'quantity_per_item' => 99]);
        $variant->update(['book_page_count' => 30]);
        app(ProductProductionPromptSyncService::class)->syncForProduct($product);
        app(ProductProductionComponentSnapshotService::class)->captureForItem($item->fresh());
        $units = ProductProductionPrompt::forItem($item->fresh());
        $this->assertSame('Cover رقية only', $units[0]['prompt']);
        $this->assertSame('20 pages for رقية', $units[1]['prompt']);
        $this->assertSame([1, 20], $units->pluck('quantity_per_item')->all());
        $this->assertSame(['order_component_snapshot', 'order_component_snapshot'], $units->pluck('prompt_source')->all());
    }

    public function test_incomplete_or_ambiguous_book_components_are_rejected_without_changing_product(): void
    {
        $product = $this->product();
        $cover = ['name' => 'كفر', 'production_stage' => 'book_cover', 'quantity_per_item' => 1, 'prompt_template' => 'Cover', 'is_active' => 1];
        $pages = ['name' => 'صفحات', 'production_stage' => 'book_interior', 'quantity_per_item' => 12, 'prompt_template' => 'Pages', 'is_active' => 1];
        foreach ([[$cover], [$pages], [$cover, $cover, $pages], [$cover, $pages, ['name' => 'Other', 'quantity_per_item' => 1, 'prompt_template' => 'Other', 'is_active' => 1]]] as $components) {
            $this->actingAs($this->admin())->put(route('admin.products.update', $product), [
                ...$this->productPayload($product), 'production_components_present' => 1, 'production_components' => $components,
            ])->assertSessionHasErrors('production_components');
        }
        foreach ([[2, 12, 0], [1, 1001, 1]] as [$covers, $count, $index]) {
            $cover['quantity_per_item'] = $covers;
            $pages['quantity_per_item'] = $count;
            $this->actingAs($this->admin())->put(route('admin.products.update', $product), [
                ...$this->productPayload($product), 'production_components_present' => 1, 'production_components' => [$cover, $pages],
            ])->assertSessionHasErrors('production_components.'.$index.'.quantity_per_item');
        }
        $this->assertDatabaseCount('product_production_components', 0);
    }

    public function test_changing_catalog_component_to_cover_does_not_replace_existing_standard_prompt(): void
    {
        $product = $this->product();
        $part = $product->productionComponents()->create(['stable_key' => 'main', 'name' => 'قديم', 'quantity_per_item' => 1, 'prompt_template' => 'Original', 'is_active' => 1]);
        $order = Order::create(['order_number' => 'BOOK-OLD', 'status' => 'new']);
        $item = $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => 'قديم', 'quantity' => 1, 'unit_price_cents' => 100, 'total_price_cents' => 100]);
        $part->update(['production_stage' => 'book_cover', 'prompt_template' => 'New cover']);
        app(ProductProductionPromptSyncService::class)->syncForProduct($product);
        $unit = ProductProductionPrompt::forItem($item->fresh())->sole();
        $this->assertSame('Original', $unit['prompt']);
        $this->assertSame('standard', $unit['production_stage']);
        $this->assertSame('product:'.$item->id, $unit['unit_key']);
    }

    public function test_book_stage_controls_are_rendered_on_both_existing_and_new_components(): void
    {
        $product = $this->product();
        $product->productionComponents()->create(['stable_key' => 'main', 'name' => 'جزء', 'quantity_per_item' => 1, 'prompt_template' => 'Test', 'is_active' => 1]);
        $this->actingAs($this->admin())->get(route('admin.products.edit', $product))->assertOk()
            ->assertSee('production_components[0][production_stage]', false)
            ->assertSee('production_components[__INDEX__][production_stage]', false)
            ->assertSee('book_page_count', false)->assertSee('كفر كتاب التلوين');
    }

    public function test_legacy_order_without_components_does_not_adopt_new_cover_or_lose_its_saved_prompt(): void
    {
        $product = $this->product();
        $order = Order::create(['order_number' => 'LEGACY-BOOK', 'status' => 'new']);
        $item = $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => 'كتاب قديم', 'quantity' => 1, 'unit_price_cents' => 100, 'total_price_cents' => 100, 'item_snapshot' => ['production_prompt_template' => 'Saved original']]);
        $product->productionComponents()->create(['stable_key' => 'cover', 'production_stage' => 'book_cover', 'name' => 'كفر', 'quantity_per_item' => 1, 'prompt_template' => 'New cover', 'is_active' => 1]);
        $unit = ProductProductionPrompt::forItem($item->fresh())->sole();
        $this->assertSame('Saved original', $unit['prompt']);
        $this->assertSame('standard', $unit['production_stage']);
        $this->assertSame('product:'.$item->id, $unit['unit_key']);
    }

    private function product(): Product
    {
        $category = ProductCategory::create([
            'name_ar' => 'منتجات مخصصة',
            'slug' => 'multi-production-products-'.uniqid(),
            'is_active' => true,
            'show_in_store' => true,
        ]);

        return Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'باقة استيكرات',
            'name_en' => 'Sticker Bundle',
            'slug' => 'sticker-bundle-'.uniqid(),
            'price_cents' => 10000,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function productPayload(Product $product): array
    {
        return [
            'product_category_id' => $product->product_category_id,
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en,
            'slug' => $product->slug,
            'price' => $product->price_cents / 100,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
            'is_active' => 1,
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }
}
