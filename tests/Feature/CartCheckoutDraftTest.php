<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CartCheckoutDraftTest extends TestCase
{
    use RefreshDatabase;

    private function prepareCart(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('orders/cart/draft-child.png', 'synthetic');
        $story = Story::create(['title' => 'Draft test story', 'slug' => 'draft-test-story', 'language' => 'ar',
            'lesson_value' => 'test', 'price' => 100, 'active' => true]);
        $this->withSession(['cart.items' => ['first' => ['key' => 'first', 'story_id' => $story->id,
            'story_title' => $story->title, 'story_slug' => $story->slug, 'story_price' => 100,
            'child_name' => 'Synthetic Child', 'child_age' => 6, 'child_gender' => 'girl',
            'uploaded_photos' => ['orders/cart/draft-child.png']]]]);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
    }

    private function checkoutPayload(string $scope): array
    {
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail();

        return ['checkout_draft_scope' => $scope, 'parent_name' => 'Synthetic Parent', 'phone' => '01012345678',
            'delivery_country_id' => $country->id, 'delivery_governorate_id' => $governorate->id,
            'city' => 'Synthetic City', 'street' => 'Synthetic Street 12'];
    }

    public function test_draft_scope_survives_returning_to_cart_and_adding_or_removing_items(): void
    {
        $this->prepareCart();
        $response = $this->get(route('cart.index'))->assertOk();
        $scope = $response->viewData('checkoutDraftScope');
        $response->assertSee('data-checkout-draft-scope="'.$scope.'"', false);
        $cart = session('cart.items');
        $cart['second'] = [...$cart['first'], 'key' => 'second', 'child_name' => 'Second synthetic child'];
        $this->withSession(['cart.items' => $cart])->get(route('cart.index'))->assertViewHas('checkoutDraftScope', $scope);
        $this->delete(route('cart.destroy', 'second'))->assertRedirect(route('cart.index'));
        $this->get(route('cart.index'))->assertViewHas('checkoutDraftScope', $scope);
        $this->withSession(['cart.items' => []])->get(route('cart.index'))->assertViewHas('checkoutDraftScope', $scope);
        $this->assertSame(['owner' => 'guest', 'scope' => $scope], session('checkout.form_draft'));
    }

    public function test_draft_scope_changes_when_switching_from_guest_to_another_account(): void
    {
        $this->prepareCart();
        $guest = $this->get(route('cart.index'))->viewData('checkoutDraftScope');
        $firstUser = User::factory()->create();
        $first = $this->actingAs($firstUser)->get(route('cart.index'))->viewData('checkoutDraftScope');
        $second = $this->actingAs(User::factory()->create())->get(route('cart.index'))->viewData('checkoutDraftScope');
        $this->assertNotSame($guest, $first);
        $this->assertNotSame($first, $second);
    }

    public function test_validation_failure_preserves_draft_scope_and_exposes_old_input_precedence(): void
    {
        $this->prepareCart();
        $scope = $this->get(route('cart.index'))->viewData('checkoutDraftScope');
        $this->from(route('cart.index'))->post(route('checkout.store'), [...$this->checkoutPayload($scope), 'phone' => '123'])
            ->assertSessionHasErrors('phone')->assertRedirect(route('cart.index'));
        $this->get(route('cart.index'))->assertViewHas('checkoutDraftScope', $scope)
            ->assertSee('data-checkout-draft-old-fields=', false)->assertSee('value="123"', false);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNull(session('checkout.completed_draft_scope'));
    }

    public function test_successful_checkout_clears_only_its_draft_and_next_cart_uses_a_new_scope(): void
    {
        $this->prepareCart();
        $scope = $this->get(route('cart.index'))->viewData('checkoutDraftScope');
        $this->post(route('checkout.store'), $this->checkoutPayload($scope))
            ->assertSessionHasNoErrors()->assertRedirect(route('checkout.success'));
        $this->assertNull(session('checkout.form_draft'));
        $this->assertSame($scope, session('checkout.completed_draft_scope'));
        $this->get(route('checkout.success'))->assertOk()->assertSee('data-checkout-draft-completed="'.$scope.'"', false);
        $this->assertNotSame($scope, $this->get(route('cart.index'))->viewData('checkoutDraftScope'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(10000, (int) Order::firstOrFail()->items()->firstOrFail()->total_price_cents);
    }

    public function test_replayed_submission_does_not_clear_a_newer_cart_draft(): void
    {
        $this->prepareCart();
        $response = $this->get(route('cart.index'));
        $scope = $response->viewData('checkoutDraftScope');
        $token = $response->viewData('checkoutSubmissionToken');
        $payload = [...$this->checkoutPayload($scope), 'checkout_submission_token' => $token];
        $this->post(route('checkout.store'), $payload)->assertRedirect(route('checkout.success'));
        $next = $this->get(route('cart.index'))->viewData('checkoutDraftScope');
        $this->post(route('checkout.store'), $payload)->assertRedirect(route('checkout.success'));
        $this->assertSame($next, session('checkout.form_draft.scope'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_older_checkout_clients_without_draft_metadata_still_work(): void
    {
        $this->prepareCart();
        $payload = $this->checkoutPayload('');
        unset($payload['checkout_draft_scope']);
        $this->post(route('checkout.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('checkout.success'));
        $this->assertDatabaseCount('orders', 1);
    }
}
