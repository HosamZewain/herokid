<?php

namespace Tests\Feature;

use App\Models\ChildProfile;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileCustomerDataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_manage_addresses_with_one_default(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mobile']);
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorates = DeliveryGovernorate::where('delivery_country_id', $country->id)->take(2)->get();

        $first = $this->postJson('/api/v1/addresses', $this->addressPayload($country->id, $governorates[0]->id, 'المنزل'))
            ->assertCreated()->assertJsonPath('data.is_default', true);
        $second = $this->postJson('/api/v1/addresses', array_merge($this->addressPayload($country->id, $governorates[1]->id, 'العمل'), ['is_default' => true]))
            ->assertCreated()->assertJsonPath('data.is_default', true);

        $this->getJson('/api/v1/addresses')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $second->json('data.id'));
        $this->assertDatabaseHas('customer_addresses', ['uuid' => $first->json('data.id'), 'is_default' => false]);
        $this->deleteJson('/api/v1/addresses/'.$second->json('data.id'))->assertNoContent();
        $this->assertDatabaseHas('customer_addresses', ['uuid' => $first->json('data.id'), 'is_default' => true]);
    }

    public function test_favorites_are_idempotent_and_only_allow_public_catalog_items(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mobile']);
        $story = $this->story('favorite-story', true);
        $hidden = $this->story('hidden-favorite-story', false);

        $this->postJson('/api/v1/favorites', ['type' => 'story', 'id' => $story->id])->assertCreated();
        $this->postJson('/api/v1/favorites', ['type' => 'story', 'id' => $story->id])->assertOk();
        $this->postJson('/api/v1/favorites', ['type' => 'story', 'id' => $hidden->id])->assertNotFound();
        $this->getJson('/api/v1/favorites')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item.slug', 'favorite-story')
            ->assertJsonPath('data.0.item.type', 'story');
        $this->deleteJson('/api/v1/favorites/story/'.$story->id)->assertNoContent();
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_customer_can_update_profile_and_changed_email_becomes_unverified(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($user, ['mobile']);

        $this->patchJson('/api/v1/me', [
            'name' => 'Updated Parent',
            'email' => 'UPDATED@EXAMPLE.TEST',
            'phone' => '010 1234 5678',
        ])->assertOk()
            ->assertJsonPath('data.user.name', 'Updated Parent')
            ->assertJsonPath('data.user.email', 'updated@example.test')
            ->assertJsonPath('data.user.email_verified', false);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'updated@example.test', 'phone' => '01012345678', 'email_verified_at' => null]);
    }

    public function test_encrypted_drafts_use_optimistic_versions_across_devices(): void
    {
        $user = User::factory()->create();
        $child = ChildProfile::create(['user_id' => $user->id, 'name' => 'Omar', 'age' => 7]);
        Sanctum::actingAs($user, ['mobile']);
        $created = $this->postJson('/api/v1/drafts', [
            'draft_type' => 'personalization',
            'child_profile_id' => $child->uuid,
            'payload' => ['story_id' => 12, 'dedication' => 'إلى عمر'],
        ])->assertCreated()->assertJsonPath('data.version', 1);
        $draftId = $created->json('data.id');

        $this->patchJson('/api/v1/drafts/'.$draftId, [
            'version' => 1,
            'child_profile_id' => $child->uuid,
            'payload' => ['story_id' => 12, 'dedication' => 'إلى بطلنا عمر'],
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/drafts/'.$draftId, [
            'version' => 1,
            'payload' => ['story_id' => 99],
        ])->assertConflict()->assertJsonPath('data.version', 2);
        $raw = (string) $this->getConnection()->table('mobile_drafts')->where('uuid', $draftId)->value('payload');
        $this->assertStringNotContainsString('إلى بطلنا عمر', $raw);
    }

    public function test_order_timeline_is_owner_scoped_and_does_not_expose_child_photo_paths(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $story = $this->story('mobile-order-story', true);
        $order = Order::create([
            'order_number' => 'HK-2026-MOB01',
            'user_id' => $user->id,
            'parent_name' => $user->name,
            'story_id' => $story->id,
            'child_name' => 'مريم',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => ['phone' => '201000000000', 'city' => 'القاهرة', 'street' => 'شارع آمن', 'total' => 224],
            'uploaded_photos' => ['private/child/photo.png'],
            'status' => 'generating',
        ]);
        $order->items()->create([
            'item_type' => 'story',
            'story_id' => $story->id,
            'title' => $story->title,
            'unit_price_cents' => 14900,
            'quantity' => 1,
            'total_price_cents' => 14900,
            'personalization_snapshot' => ['child_name' => 'مريم', 'storage_path' => 'private/child/photo.png'],
        ]);
        $order->statusLogs()->create(['status' => 'generating', 'notes' => 'بدأ إعداد المحتوى.']);

        Sanctum::actingAs($user, ['mobile']);
        $response = $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.customer_status', 'content_production')
            ->assertJsonPath('data.timeline.0.status', 'content_production');
        $this->assertStringNotContainsString('private/child/photo.png', $response->getContent());

        Sanctum::actingAs($other, ['mobile']);
        $this->getJson('/api/v1/orders/'.$order->id)->assertNotFound();
    }

    private function addressPayload(int $countryId, int $governorateId, string $label): array
    {
        return [
            'label' => $label,
            'recipient_name' => 'ولي الأمر',
            'phone' => '01012345678',
            'delivery_country_id' => $countryId,
            'delivery_governorate_id' => $governorateId,
            'city' => 'القاهرة',
            'street' => 'شارع HeroKid',
            'details' => 'عمارة ١، الدور ٢',
        ];
    }

    private function story(string $slug, bool $active): Story
    {
        return Story::create([
            'title' => 'قصة '.$slug,
            'slug' => $slug,
            'short_desc' => 'قصة مخصصة',
            'language' => 'ar',
            'gender' => 'both',
            'age_range' => '6-9',
            'price' => 149,
            'active' => $active,
        ]);
    }
}
