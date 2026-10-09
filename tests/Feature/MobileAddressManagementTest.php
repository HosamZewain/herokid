<?php

namespace Tests\Feature;

use App\Models\CustomerAddress;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAddressManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->where('active', true)->firstOrFail();

        return ['recipient_name' => 'QA Parent', 'phone' => '٠١٠١٢٣٤٥٦٧٨', 'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id, 'city' => 'مدينة اختبار', 'street' => 'شارع اختبار', 'details' => 'عمارة ١ شقة ٢'];
    }

    public function test_create_and_partial_default_update_preserve_phone_and_exact_address(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mobile']);
        $id = $this->postJson('/api/v1/addresses', $this->payload())->assertCreated()->assertJsonPath('data.phone', '01012345678')->json('data.id');
        $this->patchJson('/api/v1/addresses/'.$id, ['label' => 'المنزل', 'is_default' => true])->assertOk()
            ->assertJsonPath('data.phone', '01012345678')->assertJsonPath('data.details', 'عمارة ١ شقة ٢')->assertJsonPath('data.label', 'المنزل');
    }

    public function test_invalid_phone_types_and_numbers_are_validation_errors_not_server_errors(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mobile']);
        foreach ([['invalid'], '123', 'not-a-phone'] as $phone) {
            $this->postJson('/api/v1/addresses', [...$this->payload(), 'phone' => $phone])->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_another_customer_cannot_edit_or_read_an_address(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mobile']);
        $id = $this->postJson('/api/v1/addresses', $this->payload())->assertCreated()->json('data.id');
        Sanctum::actingAs(User::factory()->create(), ['mobile']);
        $this->getJson('/api/v1/addresses/'.$id)->assertNotFound();
        $this->patchJson('/api/v1/addresses/'.$id, ['label' => 'Other'])->assertNotFound();
        $this->assertSame(null, CustomerAddress::where('uuid', $id)->firstOrFail()->label);
    }
}
