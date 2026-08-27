<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\ProductProductionPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProductProductionPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_sticker_order_shows_rendered_product_prompt_with_safe_photo_references(): void
    {
        Storage::fake('local');
        $product = $this->product('school-sticker', <<<'PROMPT'
Order: {{order_number}}
Child: {{child_full_name}}
Age: {{child_age}}
School: {{school_name}}
Class: {{class_name}}
Gender: {{child_gender}}
Photos: {{photos_count}}
{{child_image_references}}
PROMPT);
        $photoPath = 'orders/photos/sticker/child.png';
        Storage::disk('local')->put($photoPath, 'image');
        $order = Order::create([
            'order_number' => 'HK-STICKER-PROMPT',
            'child_name' => 'Roqaya Ahmed Ali',
            'child_gender' => 'girl',
            'uploaded_photos' => [$photoPath],
            'status' => 'new',
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 19500,
            'total_price_cents' => 19500,
            'personalization_mode' => 'collect_child_details',
            'item_snapshot' => ['production_prompt_template' => $product->production_prompt_template],
            'personalization_snapshot' => [
                'child_name' => 'Roqaya Ahmed Ali',
                'child_age' => 8,
                'school_name' => 'HeroKid School',
                'class_name' => 'Class 3A',
                'child_gender' => 'girl',
            ],
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.orders.show', $order));

        $response->assertOk()
            ->assertSee('Product Production Prompt')
            ->assertSee('ستيكر المدرسة')
            ->assertSee('نسخة محفوظة مع الطلب')
            ->assertSee('Roqaya Ahmed Ali')
            ->assertSee('Age: 8')
            ->assertSee('HeroKid School')
            ->assertSee('Class 3A')
            ->assertSee('/orders/'.$order->id.'/production-photos/0', false)
            ->assertDontSee($photoPath);
    }

    public function test_prompt_is_absent_for_products_without_a_template(): void
    {
        $product = $this->product('ready-product', null);
        $order = Order::create(['order_number' => 'HK-NO-PROMPT', 'uploaded_photos' => [], 'status' => 'new']);
        $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 10000,
            'total_price_cents' => 10000,
            'personalization_mode' => 'none',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('برومبت إنتاج: منتج');
    }

    public function test_product_edit_page_renders_the_prompt_editor(): void
    {
        $product = $this->product('school-sticker', 'Sticker prompt for {{child_full_name}}');

        $this->actingAs($this->admin())
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('برومبت إنتاج المنتج')
            ->assertSee('name="production_prompt_template"', false)
            ->assertSee('Sticker prompt for {{child_full_name}}');
    }

    public function test_product_edit_form_persists_an_updated_prompt_template(): void
    {
        $product = $this->product('school-sticker', 'Original prompt for {{child_full_name}}');

        $this->actingAs($this->admin())
            ->put(route('admin.products.update', $product), [
                'product_category_id' => $product->product_category_id,
                'name_ar' => $product->name_ar,
                'slug' => $product->slug,
                'price' => 195,
                'fulfillment_type' => 'physical',
                'purchase_mode' => 'standalone',
                'personalization_mode' => 'collect_child_details',
                'personalization_fields' => [
                    'child_name' => ['enabled' => 1, 'required' => 1, 'label' => 'اسم الطفل'],
                ],
                'production_prompt_template' => 'Updated product prompt for {{child_full_name}}, age {{child_age}}',
                'inventory_mode' => 'no_tracking',
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.products.edit', $product));

        $this->assertSame(
            'Updated product prompt for {{child_full_name}}, age {{child_age}}',
            $product->fresh()->production_prompt_template,
        );

        $this->actingAs($this->admin())
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('Updated product prompt for {{child_full_name}}, age {{child_age}}');
    }

    public function test_product_only_group_page_shows_the_sticker_prompt(): void
    {
        $product = $this->product('school-sticker', 'Sticker prompt for {{child_full_name}} at {{school_name}}');
        $order = Order::create([
            'order_number' => 'HK-GROUP-STICKER-PROMPT',
            'parent_name' => 'Parent',
            'child_name' => 'سليم محمد',
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 19500,
            'total_price_cents' => 19500,
            'personalization_mode' => 'collect_child_details',
            'item_snapshot' => ['production_prompt_template' => $product->production_prompt_template],
            'personalization_snapshot' => [
                'child_name' => 'سليم محمد',
                'school_name' => 'مدرسة النور',
            ],
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSee('برومبت إنتاج المنتج')
            ->assertSee('Sticker prompt for سليم محمد at مدرسة النور')
            ->assertSee('نسخ برومبت المنتج');
    }

    public function test_product_only_group_card_links_to_a_dedicated_product_production_page(): void
    {
        Storage::fake('local');
        $product = $this->product('school-sticker', 'Sticker prompt for {{child_full_name}} at {{school_name}}');
        $photoPath = 'orders/photos/sticker/child.png';
        Storage::disk('local')->put($photoPath, 'image');
        $order = Order::create([
            'order_number' => 'HK-STICKER-PRODUCTION-PAGE',
            'parent_name' => 'Parent',
            'uploaded_photos' => [$photoPath],
            'status' => 'new',
        ]);
        $item = $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 19500,
            'total_price_cents' => 19500,
            'personalization_mode' => 'collect_child_details',
            'item_snapshot' => ['production_prompt_template' => $product->production_prompt_template],
            'personalization_snapshot' => [
                'child_name' => 'سليم محمد',
                'school_name' => 'مدرسة النور',
            ],
        ]);

        $productionUrl = route('admin.orders.products.production', [$order, $item]);

        $this->actingAs($this->admin())
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSee('فتح صفحة إنتاج الاستيكر')
            ->assertSee($productionUrl, false);

        $this->actingAs($this->admin())
            ->get($productionUrl)
            ->assertOk()
            ->assertSee('إنتاج ستيكر المدرسة')
            ->assertSee('سليم محمد')
            ->assertSee('مدرسة النور')
            ->assertSee('Sticker prompt for سليم محمد at مدرسة النور')
            ->assertSee(route('admin.orders.photo', [$order, 0]), false)
            ->assertDontSee($photoPath);
    }

    public function test_product_production_page_rejects_an_item_from_another_order(): void
    {
        $product = $this->product('school-sticker', 'Sticker prompt for {{child_full_name}}');
        $order = Order::create(['order_number' => 'HK-STICKER-FIRST', 'status' => 'new']);
        $otherOrder = Order::create(['order_number' => 'HK-STICKER-SECOND', 'status' => 'new']);
        $item = $otherOrder->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 19500,
            'total_price_cents' => 19500,
            'personalization_mode' => 'collect_child_details',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.orders.products.production', [$order, $item]))
            ->assertNotFound();
    }

    public function test_order_snapshot_is_not_changed_when_product_template_changes(): void
    {
        $product = $this->product('snapshot-sticker', 'Saved template for {{child_full_name}}');
        $order = Order::create([
            'order_number' => 'HK-SNAPSHOT-PROMPT',
            'child_name' => 'سليم',
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        $item = $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 19500,
            'total_price_cents' => 19500,
            'personalization_mode' => 'collect_child_details',
            'item_snapshot' => ['production_prompt_template' => $product->production_prompt_template],
            'personalization_snapshot' => ['child_name' => 'سليم'],
        ]);

        $product->update(['production_prompt_template' => 'Changed template']);

        $this->assertSame('Saved template for سليم', ProductProductionPrompt::renderForItem($item->fresh()));
    }

    public function test_admin_can_edit_the_prompt_for_one_product_order(): void
    {
        $product = $this->product('school-sticker', 'Original for {{child_full_name}}');
        $order = Order::create(['order_number' => 'HK-EDIT-STICKER-PROMPT', 'status' => 'new']);
        $item = $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 19500,
            'total_price_cents' => 19500,
            'personalization_mode' => 'collect_child_details',
            'item_snapshot' => ['production_prompt_template' => $product->production_prompt_template],
            'personalization_snapshot' => ['child_name' => 'سليم'],
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.orders.products.production-prompt.update', [$order, $item]), [
                'production_prompt_template' => 'Updated for {{child_full_name}}',
            ])
            ->assertRedirect();

        $this->assertSame('Updated for سليم', ProductProductionPrompt::renderForItem($item->fresh()));
        $this->assertSame('Original for {{child_full_name}}', $product->fresh()->production_prompt_template);
    }

    public function test_admin_can_apply_the_latest_product_template_to_an_existing_order(): void
    {
        $product = $this->product('school-sticker', 'Old for {{child_full_name}}');
        $order = Order::create(['order_number' => 'HK-SYNC-STICKER-PROMPT', 'status' => 'new']);
        $item = $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 19500,
            'total_price_cents' => 19500,
            'personalization_mode' => 'collect_child_details',
            'item_snapshot' => ['production_prompt_template' => 'Frozen old for {{child_full_name}}'],
            'personalization_snapshot' => ['child_name' => 'ليلى'],
        ]);
        $product->update(['production_prompt_template' => 'Latest for {{child_full_name}}']);

        $this->actingAs($this->admin())
            ->post(route('admin.orders.products.production-prompt.use-current', [$order, $item]))
            ->assertRedirect();

        $this->assertSame('Latest for ليلى', ProductProductionPrompt::renderForItem($item->fresh()));
    }

    public function test_order_prompt_edit_rejects_unsupported_variables(): void
    {
        $product = $this->product('school-sticker', 'Original for {{child_full_name}}');
        $order = Order::create(['order_number' => 'HK-INVALID-STICKER-PROMPT', 'status' => 'new']);
        $item = $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 19500,
            'total_price_cents' => 19500,
            'item_snapshot' => ['production_prompt_template' => $product->production_prompt_template],
        ]);

        $this->actingAs($this->admin())
            ->from(route('admin.orders.products.production', [$order, $item]))
            ->put(route('admin.orders.products.production-prompt.update', [$order, $item]), [
                'production_prompt_template' => 'Invalid {{unknown_variable}}',
            ])
            ->assertSessionHasErrors('production_prompt_template');

        $this->assertSame('Original for {{child_full_name}}', data_get($item->fresh()->item_snapshot, 'production_prompt_template'));
    }

    public function test_default_sticker_prompt_uses_only_supported_variables(): void
    {
        $template = file_get_contents(resource_path('prompts/school-sticker-production.md'));

        $this->assertIsString($template);
        $this->assertSame([], ProductProductionPrompt::unsupportedVariables($template));
        $this->assertStringContainsString('21 large stickers', $template);
        $this->assertStringContainsString('48 small stickers', $template);
    }

    private function product(string $slug, ?string $template): Product
    {
        $category = ProductCategory::firstOrCreate(
            ['slug' => 'personalized-products'],
            ['name_ar' => 'منتجات مخصصة', 'is_active' => true, 'show_in_store' => true],
        );

        return Product::create([
            'product_category_id' => $category->id,
            'name_ar' => $slug === 'school-sticker' ? 'ستيكر المدرسة' : 'منتج',
            'slug' => $slug,
            'price_cents' => 19500,
            'personalization_mode' => $template ? 'collect_child_details' : 'none',
            'production_prompt_template' => $template,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
        ]);
    }
}
