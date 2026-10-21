<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProductDeletionQaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== '/qa/product-delete.sqlite') {
            throw new \RuntimeException('Requires the disposable product deletion QA database.');
        }
        foreach (['manager' => ['store.products.view', 'store.products.delete'], 'editor' => ['store.products.view', 'store.products.update']] as $role => $permissions) {
            $user = User::factory()->create(['name' => 'Product QA '.$role, 'email' => 'product-'.$role.'@example.test', 'role' => 'admin', 'password' => 'product-delete-qa-only']);
            $user->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));
        }
        $category = ProductCategory::create(['name_ar' => 'تصنيف اختبار معزول', 'slug' => 'qa-delete', 'is_active' => true, 'show_in_store' => true]);
        foreach (['delete-me' => 'منتج اختبار الحذف', 'keep-me' => 'منتج اختبار يبقى محفوظًا'] as $slug => $name) {
            $product = Product::create(['name_ar' => $name, 'slug' => $slug, 'product_category_id' => $category->id, 'price_cents' => 15000, 'is_active' => true]);
            if ($slug === 'delete-me') {
                $order = Order::create(['order_number' => 'HK-QA-DELETE-ONLY']);
                $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => $name, 'quantity' => 1, 'unit_price_cents' => 15000, 'total_price_cents' => 15000]);
            }
        }
    }
}
