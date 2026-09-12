<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\ProductProductionPrompt;
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

    public function test_order_snapshots_components_and_only_changes_them_after_explicit_refresh(): void
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
        $this->assertSame(['order_component_snapshot', 'order_component_snapshot'], $prompts->pluck('prompt_source')->all());
        $this->assertDatabaseCount('order_item_production_components', 2);

        $large->update(['prompt_template' => 'نسخة جديدة: {{child_full_name}}']);
        $small->delete();
        $historicalPrompts = ProductProductionPrompt::forItem($item->fresh());
        $this->assertCount(2, $historicalPrompts);
        $this->assertSame('كبير: ليلى أحمد — 4', $historicalPrompts[0]['prompt']);
        $this->assertSame('صغير: ليلى أحمد — 10', $historicalPrompts[1]['prompt']);

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
