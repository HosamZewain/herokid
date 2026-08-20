<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMobileOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_operator_can_manage_remote_config_and_promotions(): void
    {
        $admin = $this->admin(['settings.mobile.view', 'settings.mobile.manage']);

        $this->actingAs($admin)->get(route('admin.mobile-operations.index'))
            ->assertOk()->assertSee('تشغيل تطبيق الهاتف')->assertSee('طلبات الخصوصية');

        $this->actingAs($admin)->put(route('admin.mobile-operations.config.update'), [
            'mobile_minimum_supported_version' => '1.0.0',
            'mobile_latest_version' => '1.1.0',
            'mobile_force_update' => '0',
            'mobile_maintenance_mode' => '0',
            'mobile_home_banner_title_ar' => 'طفلك هو البطل',
            'mobile_home_banner_title_en' => 'Your child is the hero',
            'mobile_home_banner_subtitle_ar' => '',
            'mobile_home_banner_subtitle_en' => '',
            'mobile_home_banner_image_url' => '',
            'mobile_home_banner_deep_link' => '/catalog',
        ])->assertRedirect();

        $this->assertDatabaseHas('settings', ['key' => 'mobile_latest_version', 'value' => '1.1.0']);
        $this->getJson('/api/v1/bootstrap')->assertOk()
            ->assertJsonPath('data.app.latest_version', '1.1.0')
            ->assertJsonPath('data.home.banner.title_ar', 'طفلك هو البطل');

        $this->actingAs($admin)->post(route('admin.mobile-operations.promo-codes.store'), [
            'code' => 'mobile10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'minimum_subtotal' => 200,
            'per_user_limit' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('mobile_promo_codes', [
            'code' => 'MOBILE10',
            'discount_value' => 1000,
            'minimum_subtotal_cents' => 20000,
            'is_active' => true,
        ]);
    }

    public function test_view_only_operator_cannot_mutate_mobile_operations(): void
    {
        $admin = $this->admin(['settings.mobile.view']);

        $this->actingAs($admin)->get(route('admin.mobile-operations.index'))->assertOk();
        $this->actingAs($admin)->post(route('admin.mobile-operations.promo-codes.store'), [])->assertForbidden();
    }

    public function test_privacy_request_can_enter_processing_but_cannot_be_marked_complete_from_dashboard(): void
    {
        $admin = $this->admin(['settings.mobile.view', 'settings.mobile.manage']);
        $customer = User::factory()->create(['role' => 'customer']);
        $privacy = PrivacyRequest::query()->create([
            'user_id' => $customer->id,
            'request_type' => 'account_deletion',
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->actingAs($admin)->patch(route('admin.mobile-operations.privacy-requests.update', $privacy), [
            'status' => 'in_progress',
        ])->assertRedirect();
        $this->assertDatabaseHas('privacy_requests', ['id' => $privacy->id, 'status' => 'in_progress']);

        $this->actingAs($admin)->patch(route('admin.mobile-operations.privacy-requests.update', $privacy), [
            'status' => 'completed',
        ])->assertSessionHasErrors('status');
    }

    private function admin(array $permissions): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));

        return $admin;
    }
}
