<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cart\FreeShippingOfferService;
use App\Services\Cart\ShippingDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_percent_rounding_floor_and_disabled_configuration(): void
    {
        $service = app(ShippingDiscountService::class);
        $this->assertSame(10000, $service->feeCents(10000));
        foreach ([['fixed', 3000, 10000, 7000], ['fixed', 3000, 15000, 12000],
            ['percent', 3000, 10000, 7000], ['percent', 3000, 15000, 10500],
            ['percent', 1250, 9999, 8749], ['fixed', 3000, 2000, 0],
            ['percent', 10000, 15000, 0], ['fixed', 3000, 0, 0]] as [$type, $value, $base, $expected]) {
            $this->assertSame($expected, $service->feeCents($base, ['enabled' => true, 'type' => $type, 'value' => $value]));
        }
        $this->assertSame(15000, $service->feeCents(15000, ['enabled' => false, 'type' => 'fixed', 'value' => 3000]));
    }

    public function test_admin_permissions_validation_and_audit(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->permissions()->sync(Permission::where('key', 'store.discount_codes.view')->pluck('id'));
        $payload = ['shipping_enabled' => 1, 'shipping_type' => 'fixed', 'shipping_value' => 30];
        $this->actingAs($user)->put(route('admin.discount-codes.shipping-discount'), $payload)->assertForbidden();
        $user->permissions()->sync(Permission::whereIn('key', ['store.discount_codes.view', 'store.discount_codes.manage'])->pluck('id'));
        $this->actingAs($user->fresh())->put(route('admin.discount-codes.shipping-discount'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.discount-codes.index'));
        $this->assertSame(['enabled' => true, 'type' => 'fixed', 'value' => 3000], app(ShippingDiscountService::class)->configuration());
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'shipping_discount.updated']);
        $this->get(route('admin.discount-codes.index'))->assertOk()->assertSee('خصم عام على الشحن')->assertSee('حفظ خصم الشحن');
        foreach ([['shipping_value' => -1], ['shipping_type' => 'percent', 'shipping_value' => 101], ['shipping_value' => 1.234], ['shipping_type' => 'invalid']] as $invalid) {
            $this->put(route('admin.discount-codes.shipping-discount'), array_merge($payload, $invalid))->assertSessionHasErrorsIn('shippingDiscount');
        }
        $this->put(route('admin.discount-codes.shipping-discount'), array_merge($payload, ['shipping_type' => 'percent', 'shipping_value' => 12.5]))->assertSessionHasNoErrors();
        $this->assertSame(1250, app(ShippingDiscountService::class)->configuration()['value']);
        $this->put(route('admin.discount-codes.shipping-discount'), array_merge($payload, ['shipping_enabled' => 0]))->assertSessionHasNoErrors();
        $this->assertSame(10000, app(ShippingDiscountService::class)->feeCents(10000));
    }

    public function test_free_shipping_takes_precedence_without_over_discounting(): void
    {
        Setting::updateOrCreate(['key' => ShippingDiscountService::KEY], ['value' => json_encode(['enabled' => true, 'type' => 'fixed', 'value' => 3000])]);
        Setting::updateOrCreate(['key' => FreeShippingOfferService::KEY], ['value' => json_encode([
            'enabled' => true, 'minimum_cents' => 100000, 'starts_at' => now()->subDay()->toISOString(), 'ends_at' => now()->addDay()->toISOString(),
        ])]);
        $service = app(FreeShippingOfferService::class);
        $this->assertEquals(70, $service->quote(99999, 0, 10000)['delivery_fee']);
        $this->assertEquals(30, $service->quote(99999, 0, 10000)['shipping_discount']);
        $this->assertEquals(0, $service->quote(100000, 0, 10000)['delivery_fee']);
        $this->assertEquals(100, $service->quote(100000, 0, 10000)['shipping_discount']);
        $this->assertEquals(70, $service->quote(100000, 1, 10000)['delivery_fee']);
    }

    public function test_bootstrap_and_cached_guide_show_discounted_fees_with_country_fallback(): void
    {
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $country->update(['delivery_fee' => 150, 'active' => true]);
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail();
        $governorate->update(['name' => 'محافظة الاختبار', 'delivery_fee' => null, 'active' => true]);
        $this->getJson('/api/feeds/robodesk/guide')->assertOk();
        Setting::updateOrCreate(['key' => ShippingDiscountService::KEY], ['value' => json_encode(['enabled' => true, 'type' => 'fixed', 'value' => 3000])]);
        $data = collect($this->getJson('/api/v1/bootstrap')->assertOk()->json('data.delivery_countries'))->firstWhere('id', $country->id);
        $this->assertEquals(120, $data['delivery_fee']);
        $this->assertEquals(120, collect($data['governorates'])->firstWhere('id', $governorate->id)['delivery_fee']);
        $articles = collect($this->getJson('/api/feeds/robodesk/guide')->assertOk()->json('data'));
        $this->assertStringContainsString('محافظة الاختبار: 120 ج.م', $articles->pluck('rag_text')->implode("\n"));
        $this->assertEquals(150, $country->fresh()->delivery_fee);
        $this->assertNull($governorate->fresh()->delivery_fee);
    }
}
