<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Story;
use App\Models\TemporaryPhotoUpload;
use App\Models\User;
use App\Models\VisitorCartItem;
use App\Support\ProductProductionPrompt;
use App\Support\StoryProductionPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SharedChildPackagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_package_keeps_one_checkout_and_independent_production_for_the_same_child(): void
    {
        Storage::fake('local');
        [$package, $story, $products] = $this->bundle();
        $response = $this->get(route('shop.package.show', $package))->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'name="child[child_name]"'));
        $response->assertDontSee('name="stories[0][child_name]"', false)->assertSee('اسم المدرسة');
        $input = $this->childInput();
        $this->post(route('cart.packages.store', $package), [
            ...$input, 'stories' => [['story_id' => $story->id, 'child_name' => 'Ignored override']],
            'price' => 1, 'regular_total_cents' => 999999,
        ])->assertSessionHasNoErrors()->assertRedirect(route('cart.index'));
        $cart = collect(session('cart.items'))->sole();
        $this->assertSame(70001, $cart['line_total_cents']);
        $this->assertSame('نور أحمد', $cart['package_stories'][0]['child_name']);
        $this->assertSame(2, count($cart['package_products'][0]['uploaded_photos']));
        $this->assertSame(1, count($cart['package_products'][1]['uploaded_photos']));
        $this->assertSame($cart['package_stories'][0]['uploaded_photos'][0], $cart['package_products'][1]['uploaded_photos'][0]);
        $this->assertStringNotContainsString('temporary-uploads/', json_encode(VisitorCartItem::where('item_type', 'package')->latest('id')->firstOrFail()->item_snapshot));

        $this->checkout();
        $orders = Order::with('items')->get();
        $this->assertCount(1, $orders->pluck('checkout_group_key')->unique());
        $this->assertTrue($orders->every(fn ($order) => $order->child_name === 'نور أحمد'));
        $items = $orders->flatMap->items;
        $this->assertCount(4, $items);
        $this->assertSame(70001, $items->sum('total_price_cents'));
        $this->assertTrue($items->every(fn ($item) => data_get($item->item_snapshot, 'package.id') === $package->id));
        foreach ($products as $product) {
            $item = $items->firstWhere('product_id', $product->id);
            $this->assertSame('مدرسة الأبطال', $item->personalization_snapshot['school_name']);
            $this->assertStringContainsString($product->name_ar.' نور أحمد', ProductProductionPrompt::renderForItem($item));
            $this->assertSame(1, $item->productionComponents()->count());
        }
        $this->assertSame(2, $items->firstWhere('product_id', $products[2]->id)->quantity);
        $this->assertSame(8, $products[2]->fresh()->stock_quantity);
        $this->assertSame(1, $orders->pluck('checkoutReference.id')->unique()->count());
        $this->assertStringContainsString('نور أحمد', StoryProductionPrompt::forOrder($orders->firstWhere('story_id', $story->id)));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $page = $this->actingAs($admin)->get(route('admin.orders.groups.show', $orders->first()))->assertOk();
        foreach ($products as $product) {
            $page->assertSee($product->name_ar.' نور أحمد');
        }
    }

    public function test_product_only_package_accepts_one_photo_according_to_product_settings(): void
    {
        Storage::fake('local');
        [$package, , $products] = $this->bundle();
        $package->update(['story_count' => 0]);
        $package->items()->where('product_id', '!=', $products[1]->id)->delete();
        $input = $this->childInput(1);
        $this->post(route('cart.packages.store', $package), $input)->assertSessionHasNoErrors()->assertRedirect(route('cart.index'));
        $cart = collect(session('cart.items'))->sole();
        $this->assertCount(0, $cart['package_stories']);
        $this->assertCount(1, $cart['package_products'][0]['uploaded_photos']);
        $this->checkout();
        $order = Order::firstOrFail();
        $this->assertSame('نور أحمد', $order->child_name);
        $this->assertCount(1, $order->uploaded_photos);
        $this->assertSame(70001, $order->items->sum('total_price_cents'));
    }

    public function test_shared_child_requires_all_component_fields_and_correct_story_count(): void
    {
        Storage::fake('local');
        [$package, $story] = $this->bundle();
        $input = $this->childInput();
        unset($input['child']['school_name']);
        $this->post(route('cart.packages.store', $package), [...$input, 'stories' => [['story_id' => $story->id]]])->assertSessionHasErrors('school_name');
        $input['child']['school_name'] = 'مدرسة الأبطال';
        $this->post(route('cart.packages.store', $package), $input)->assertSessionHasErrors('stories');
        $this->assertEmpty(session('cart.items'));
    }

    public function test_foreign_uploads_cannot_be_used_in_a_package(): void
    {
        Storage::fake('local');
        [$package, $story] = $this->bundle();
        $input = $this->childInput();
        $this->flushSession();
        $other = $this->getJson(route('photo-uploads.session'))->json('upload_session_token');
        $this->post(route('cart.packages.store', $package), [...$input, 'upload_session_token' => $other, 'stories' => [['story_id' => $story->id]]])->assertSessionHasErrors();
        $this->assertEmpty(session('cart.items'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_admin_computes_comparison_price_and_ignores_submitted_marketing_total(): void
    {
        [$package, $story, $products] = $this->bundle();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->put(route('admin.pricing.update', $package), [
            'name' => 'باكدج جديد', 'price' => 500, 'regular_price' => 90000,
            'story_count' => 1, 'story_scope' => 'specific', 'story_ids' => [$story->id],
            'products' => [$products[1]->id => ['quantity' => 2]],
            'active' => 1, 'show_in_store' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.pricing.index'));
        $package->refresh();
        $this->assertSame('800.00', $package->regular_price);
        $this->assertSame(38, $package->discountPercentage());
        $products[1]->update(['price_cents' => 25000]);
        $this->assertSame(90000, $package->fresh()->componentPriceCents());
    }

    public function test_inactive_variant_or_product_cannot_be_purchased(): void
    {
        [$package, , $products] = $this->bundle();
        $products[0]->update(['is_active' => false]);
        $this->post(route('cart.packages.store', $package), ['shared_child' => 1])->assertStatus(422);
        $this->assertEmpty(session('cart.items'));
    }

    public function test_multiple_stories_reuse_the_same_child_and_photos(): void
    {
        Storage::fake('local');
        [$package, $story] = $this->bundle();
        $package->update(['story_count' => 2]);
        $input = $this->childInput();
        $input['child']['photo_upload_ids'] = array_reverse($input['child']['photo_upload_ids']);
        $this->post(route('cart.packages.store', $package), [...$input, 'stories' => [['story_id' => $story->id], ['story_id' => $story->id]]])->assertSessionHasNoErrors()->assertRedirect(route('cart.index'));
        $cart = collect(session('cart.items'))->sole();
        $this->assertSame($cart['package_stories'][0]['uploaded_photos'], $cart['package_stories'][1]['uploaded_photos']);
        $this->assertSame($cart['package_stories'][0]['child_name'], $cart['package_stories'][1]['child_name']);
        $firstUpload = TemporaryPhotoUpload::where('public_id', $input['child']['photo_upload_ids'][0])->firstOrFail();
        $this->assertSame($firstUpload->path, $cart['package_products'][1]['uploaded_photos'][0]);
        $this->checkout();
        $this->assertSame(1, Order::pluck('checkout_group_key')->unique()->count());
        $this->assertSame(70001, (int) OrderItem::sum('total_price_cents'));
    }

    public function test_product_only_package_photo_cleanup_and_permission_boundaries(): void
    {
        Storage::fake('local');
        [$package, , $products] = $this->bundle();
        $package->update(['story_count' => 0]);
        $package->items()->where('product_id', '!=', $products[1]->id)->delete();
        $this->post(route('cart.packages.store', $package), $this->childInput(1))->assertSessionHasNoErrors();
        $cart = collect(session('cart.items'))->sole();
        $path = $cart['package_products'][0]['uploaded_photos'][0];
        Storage::disk('local')->assertExists($path);
        $this->delete(route('cart.destroy', $cart['key']))->assertRedirect();
        Storage::disk('local')->assertMissing($path);
        $employee = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $employee->permissions()->detach();
        $this->actingAs($employee)->get(route('admin.pricing.create'))->assertForbidden();
    }

    private function bundle(): array
    {
        $story = Story::create(['title' => 'قصة البطل', 'slug' => 'bundle-story', 'price' => 400, 'active' => true, 'language' => 'ar']);
        $package = PricingPackage::create(['name' => 'باكدج البطل', 'slug' => 'shared-child-bundle', 'price' => 700.01, 'story_count' => 1, 'applies_to_all_stories' => false, 'active' => true, 'show_in_store' => true]);
        $package->eligibleStories()->sync([$story->id]);
        $products = [];
        foreach (['تلوين', 'بوستر', 'بازل'] as $index => $name) {
            $product = Product::create([
                'product_category_id' => ProductCategory::firstOrFail()->id,
                'name_ar' => $name, 'slug' => 'bundle-product-'.$index, 'price_cents' => 20000,
                'is_active' => true, 'inventory_mode' => 'track_stock', 'stock_quantity' => 10,
                'personalization_mode' => 'collect_child_details', 'purchase_mode' => 'standalone',
                'personalization_fields' => ['fields' => [
                    'child_name' => ['enabled' => true, 'required' => true],
                    'school_name' => ['enabled' => true, 'required' => true],
                    'photos' => ['enabled' => true, 'required' => true, 'min_files' => $index === 1 ? 1 : 2, 'max_files' => $index === 1 ? 1 : 3],
                ]],
            ]);
            $product->productionComponents()->create(['stable_key' => 'main', 'name' => $name, 'prompt_template' => $name.' {{child_full_name}} {{school_name}}', 'quantity_per_item' => 1, 'is_active' => true, 'sort_order' => 0]);
            $package->items()->create(['product_id' => $product->id, 'quantity' => $index === 2 ? 2 : 1, 'sort_order' => $index]);
            $products[] = $product;
        }

        return [$package, $story, $products];
    }

    private function childInput(int $count = 2): array
    {
        $session = $this->getJson(route('photo-uploads.session'))->assertOk()->json();
        $token = $session['upload_session_token'];
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->postJson(route('photo-uploads.store'), ['upload_session_token' => $token, 'upload_batch_token' => $session['upload_batch_token'], 'photo' => UploadedFile::fake()->image('child-'.$i.'.png')])->assertCreated()->json('id');
        }

        return ['shared_child' => 1, 'upload_session_token' => $token, 'child' => ['child_name' => 'نور أحمد', 'child_age' => 8, 'child_gender' => 'girl', 'school_name' => 'مدرسة الأبطال', 'photo_upload_ids' => $ids]];
    }

    private function checkout(): void
    {
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $this->post(route('checkout.store'), ['parent_name' => 'ولي الأمر', 'phone' => '01000000000', 'delivery_country_id' => $country->id, 'delivery_governorate_id' => DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail()->id, 'city' => 'القاهرة', 'street' => 'شارع الاختبار', 'address_details' => 'عنوان الاختبار'])->assertSessionHasNoErrors()->assertRedirect(route('checkout.success'));
    }
}
