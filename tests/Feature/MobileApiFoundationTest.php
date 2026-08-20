<?php

namespace Tests\Feature;

use App\Models\ChildProfile;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_bootstrap_is_public_and_returns_delivery_and_feature_configuration(): void
    {
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $country->update(['delivery_fee' => 75, 'active' => true]);
        DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail()->update([
            'delivery_fee' => null,
            'active' => true,
        ]);

        $this->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.default_locale', 'ar')
            ->assertJsonPath('data.features.child_identity', true)
            ->assertJsonPath('data.delivery_countries.0.code', 'EG')
            ->assertJsonPath('data.delivery_countries.0.currency', 'EGP')
            ->assertJsonPath('data.delivery_countries.0.governorates.0.delivery_fee', 75);
    }

    public function test_mobile_catalog_reuses_the_unified_story_and_product_sources(): void
    {
        $story = Story::create([
            'title' => 'بطل الفضاء',
            'slug' => 'space-hero-mobile',
            'short_desc' => 'قصة فضائية مخصصة',
            'language' => 'ar',
            'gender' => 'both',
            'age_range' => '6-9',
            'price' => 149,
            'active' => true,
        ]);
        $category = ProductCategory::create([
            'name_ar' => 'كتب أنشطة',
            'name_en' => 'Activity Books',
            'slug' => 'mobile-activities',
            'is_active' => true,
            'show_in_store' => true,
        ]);
        $product = Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'كتاب المتاهات',
            'name_en' => 'Maze Book',
            'slug' => 'maze-mobile',
            'short_description_ar' => 'أنشطة ممتعة',
            'short_description_en' => 'Fun activities',
            'price_cents' => 9000,
            'is_active' => true,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
        ]);

        $this->getJson('/api/v1/catalog?per_page=20')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => 'story:'.$story->id, 'slug' => 'space-hero-mobile'])
            ->assertJsonFragment(['id' => 'product:'.$product->id, 'slug' => 'maze-mobile'])
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/catalog/story/space-hero-mobile')
            ->assertOk()
            ->assertJsonPath('data.type', 'story')
            ->assertJsonPath('data.details.language', 'ar');

        $this->getJson('/api/v1/catalog?per_page=20&locale=en')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Maze Book')
            ->assertJsonPath('data.0.short_description', 'Fun activities')
            ->assertJsonPath('meta.filters.product_categories.0.name', 'Activity Books');
    }

    public function test_customer_can_register_use_token_and_manage_owned_child_profiles(): void
    {
        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'Hero Parent',
            'email' => 'parent@example.com',
            'phone' => '01012345678',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
            'device_name' => 'iPhone 16',
        ])->assertCreated()
            ->assertJsonPath('data.user.email', 'parent@example.com')
            ->assertJsonPath('data.token_type', 'Bearer');

        $token = $registration->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'iPhone 16']);

        $headers = ['Authorization' => 'Bearer '.$token];

        $created = $this->withHeaders($headers)->postJson('/api/v1/children', [
            'name' => 'Omar',
            'age' => 7,
            'gender' => 'boy',
            'interests' => ['space', 'football'],
            'preferred_language' => 'ar',
            'photo_reuse_consent' => true,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Omar')
            ->assertJsonPath('data.photo_reuse_consent', true);

        $childId = $created->json('data.id');

        $this->withHeaders($headers)->patchJson('/api/v1/children/'.$childId, [
            'age' => 8,
        ])->assertOk()->assertJsonPath('data.age', 8);

        $this->withHeaders($headers)->getJson('/api/v1/children')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $childId);

        $this->withHeaders($headers)->deleteJson('/api/v1/children/'.$childId)->assertNoContent();
        $this->assertSoftDeleted('child_profiles', ['uuid' => $childId]);
    }

    public function test_customer_cannot_read_another_customers_child_profile(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $child = ChildProfile::create([
            'user_id' => $owner->id,
            'name' => 'Private Child',
            'age' => 6,
        ]);

        $token = $other->createToken('Other device', ['mobile'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/children/'.$child->uuid)
            ->assertNotFound();
    }
}
