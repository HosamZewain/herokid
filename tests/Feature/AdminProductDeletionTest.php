<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $permissions): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));

        return $user->fresh();
    }

    private function product(): Product
    {
        $category = ProductCategory::create(['name_ar' => 'منتجات اختبار', 'slug' => 'deletion-products', 'is_active' => true, 'show_in_store' => true]);

        return Product::create(['product_category_id' => $category->id, 'name_ar' => 'منتج حذف تجريبي', 'slug' => 'safe-deletion-product',
            'price_cents' => 15000, 'is_active' => true, 'personalization_mode' => 'none', 'purchase_mode' => 'standalone',
            'inventory_mode' => 'no_tracking', 'production_prompt_template' => 'Make a card for {{child_name}}.']);
    }

    public function test_delete_controls_and_endpoint_require_delete_permission_not_just_edit(): void
    {
        $product = $this->product();
        $editor = $this->employee(['store.products.view', 'store.products.update']);
        $this->actingAs($editor)->get(route('admin.products.index'))->assertOk()->assertDontSee('data-product-delete', false);
        $this->deleteJson(route('admin.products.destroy', $product), ['confirmed' => true, 'reason' => 'Unauthorized attempt'])->assertForbidden();
        $this->assertNotNull(Product::find($product->id));
        $deleter = $this->employee(['store.products.view', 'store.products.delete']);
        $this->actingAs($deleter)->get(route('admin.products.index'))->assertOk()->assertSee('data-product-delete', false)
            ->assertSee('سبب الحذف')->assertSee('تأكيد حذف المنتج');
    }

    public function test_customer_cannot_delete_even_if_permission_is_attached(): void
    {
        $product = $this->product();
        $customer = User::factory()->create(['role' => 'customer']);
        $customer->permissions()->sync(Permission::where('key', 'store.products.delete')->pluck('id'));
        $this->actingAs($customer)->deleteJson(route('admin.products.destroy', $product), ['confirmed' => true, 'reason' => 'Customer attempt'])->assertForbidden();
        $this->assertNotNull(Product::find($product->id));
    }

    public function test_confirmation_and_meaningful_reason_are_enforced_by_server(): void
    {
        $product = $this->product();
        $this->actingAs($this->employee(['store.products.delete']));
        foreach ([[], ['reason' => 'Valid reason'], ['confirmed' => true], ['confirmed' => false, 'reason' => 'Valid reason'], ['confirmed' => true, 'reason' => '  '], ['confirmed' => true, 'reason' => 'ab'], ['confirmed' => true, 'reason' => str_repeat('a', 501)]] as $payload) {
            $this->deleteJson(route('admin.products.destroy', $product), $payload)->assertUnprocessable();
            $this->assertNotNull(Product::find($product->id));
            $this->assertTrue($product->fresh()->is_active);
        }
        $this->assertDatabaseMissing('admin_activity_logs', ['action' => 'store.product.deleted']);
    }

    public function test_confirmed_delete_hides_catalog_but_preserves_orders_variants_components_and_media(): void
    {
        Storage::fake('public');
        $product = $this->product();
        Storage::disk('public')->put('products/keep-history.png', 'test media');
        $product->update(['featured_image' => 'products/keep-history.png']);
        $variant = $product->variants()->create(['name_ar' => 'نسخة تاريخية', 'sku' => 'HISTORY', 'is_active' => true]);
        $component = $product->productionComponents()->create(['stable_key' => 'main', 'name' => 'بطاقة', 'prompt_template' => 'Make a card for {{child_name}}.', 'is_active' => true]);
        $order = Order::create(['order_number' => 'HK-QA-PRODUCT-DELETE', 'checkout_group_key' => 'QA-PRODUCT-DELETE', 'paid_amount_cents' => 15000]);
        $item = $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'title' => $product->name_ar, 'unit_price_cents' => 15000, 'quantity' => 2, 'total_price_cents' => 30000,
            'item_snapshot' => ['name_ar' => $product->name_ar], 'personalization_snapshot' => ['child_name' => 'طفل اختبار']]);
        $orderBefore = $order->fresh()->getRawOriginal();
        $itemBefore = $item->fresh()->getRawOriginal();
        $componentsBefore = $item->productionComponents()->get()->toArray();
        $paymentsBefore = $order->paymentEvents()->get()->toArray();
        $employee = $this->employee(['store.products.view', 'store.products.delete', 'orders.view', 'orders.photos.view', 'orders.production_prompt.manage']);
        $this->actingAs($employee)->delete(route('admin.products.destroy', $product), ['confirmed' => '1', 'reason' => 'إيقاف بيع هذا المنتج'])
            ->assertRedirect(route('admin.products.index'));
        $this->assertSoftDeleted($product);
        $this->assertNull(Product::find($product->id));
        $this->assertFalse(Product::withTrashed()->findOrFail($product->id)->is_active);
        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
        $this->assertSame($itemBefore, $item->fresh()->getRawOriginal());
        $this->assertSame($product->id, $item->fresh()->product->id);
        $this->assertSame($component->id, $item->fresh()->product->productionComponents->first()->id);
        $this->assertSame($componentsBefore, $item->productionComponents()->get()->toArray());
        $this->assertSame($paymentsBefore, $order->paymentEvents()->get()->toArray());
        $this->assertNotNull($variant->fresh());
        Storage::disk('public')->assertExists('products/keep-history.png');
        $log = AdminActivityLog::where('action', 'store.product.deleted')->sole();
        $this->assertSame($employee->id, $log->user_id);
        $this->assertSame('إيقاف بيع هذا المنتج', $log->properties['reason']);
        $this->assertSame($product->id, (int) $log->subject_id);
        $this->get(route('admin.products.index'))->assertOk()->assertDontSee($product->name_ar);
        $this->get(route('admin.products.edit', $product))->assertNotFound();
        $this->get(route('shop.product.show', $product))->assertNotFound();
        $this->getJson('/api/v1/catalog/product/'.$product->slug)->assertNotFound();
        $this->getJson('/api/v1/catalog?type=products&sort=best_selling')->assertOk()->assertJsonMissing(['slug' => $product->slug]);
        $this->post(route('cart.products.store', $product), ['quantity' => 1])->assertNotFound();
        $this->get(route('admin.orders.products.production', [$order, $item]))->assertOk();
        $this->deleteJson(route('admin.products.destroy', $product), ['confirmed' => true, 'reason' => 'Repeated request'])->assertNotFound();
        $this->assertSame(1, AdminActivityLog::where('action', 'store.product.deleted')->count());
    }

    public function test_old_order_stock_can_be_released_and_reserved_after_catalog_removal(): void
    {
        $product = $this->product();
        $product->update(['inventory_mode' => 'track_stock', 'stock_quantity' => 8]);
        $order = Order::create(['order_number' => 'HK-QA-ARCHIVED-STOCK']);
        $item = $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => $product->name_ar,
            'unit_price_cents' => 15000, 'quantity' => 2, 'total_price_cents' => 30000]);
        $this->actingAs($this->employee(['store.products.delete', 'orders.delete']));
        $this->delete(route('admin.products.destroy', $product), ['confirmed' => true, 'reason' => 'Remove old catalog item'])->assertRedirect();
        $this->delete(route('admin.orders.groups.destroy', $order->id), ['deletion_reason' => 'Isolated stock test', 'confirmation' => $order->checkoutGroupKey()])->assertRedirect();
        $this->assertSame(10, Product::withTrashed()->findOrFail($product->id)->stock_quantity);
        $this->assertNotNull($item->fresh()->stock_released_at);
        $this->post(route('admin.orders.groups.restore', $order->id))->assertRedirect();
        $this->assertSame(8, Product::withTrashed()->findOrFail($product->id)->stock_quantity);
        $this->assertNull($item->fresh()->stock_released_at);
        $this->assertSoftDeleted($product);
    }
}
