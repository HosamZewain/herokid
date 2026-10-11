<?php

namespace Tests\Feature;

use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDeletionPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_public_clear_private_cached_and_has_no_advertising_trackers(): void
    {
        config(['services.meta_pixel.id' => '123456', 'services.google_analytics.id' => 'G-TEST']);
        $response = $this->get('/account-deletion')->assertOk()
            ->assertSee('طلب حذف حساب HeroKid وبياناته')
            ->assertSee('ما الذي يشمله طلب الحذف؟')
            ->assertSee('ما الذي قد يُحتفظ به، ولماذا؟')
            ->assertSee('30 يومًا')
            ->assertSee('إرسال طلب حذف الحساب')
            ->assertSee('name="_token"', false)
            ->assertDontSee('connect.facebook.net')
            ->assertDontSee('googletagmanager.com/gtag');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/privacy')->assertOk()->assertSee(route('account-deletion.show'), false)->assertDontSee('privacy@herokid.sa');
        $this->get('/about')->assertOk()->assertSee('حذف الحساب والبيانات');
    }

    public function test_verified_guest_can_request_deletion_with_email_without_logging_in_or_deleting_data(): void
    {
        $user = User::factory()->create(['email' => 'parent@example.test', 'password' => 'Strong-password-123']);
        $token = $user->createToken('test-mobile', ['mobile'])->accessToken;
        $this->from('/account-deletion')->post('/account-deletion', [
            'login' => ' PARENT@example.test ', 'password' => 'Strong-password-123', 'confirmation' => 'DELETE_MY_ACCOUNT',
            'user_id' => 9999, 'status' => 'completed', 'due_at' => '2000-01-01',
        ])->assertRedirect('/account-deletion')->assertSessionHas('account_deletion_receipt');
        $record = PrivacyRequest::sole();
        $this->assertSame($user->id, $record->user_id);
        $this->assertSame('pending', $record->status);
        $this->assertSame('account_deletion', $record->request_type);
        $this->assertSame(now()->addDays(30)->toDateString(), $record->due_at->toDateString());
        $this->assertSame(['account', 'child_profiles', 'reusable_child_media', 'sessions', 'devices'], $record->scope);
        $this->assertNotNull($user->fresh()->deletion_requested_at);
        $this->assertNull($record->completed_at);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'parent@example.test']);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
        $this->assertGuest();
        $this->get('/account-deletion')->assertOk()->assertSee($record->uuid)
            ->assertSee('هذا تأكيد استلام الطلب')->assertDontSee('Strong-password-123');
        $this->get('/account-deletion')->assertOk()->assertDontSee($record->uuid);
        $this->assertArrayNotHasKey('password', $record->getAttributes());
    }

    public function test_phone_only_customer_can_use_equivalent_egyptian_phone_formats(): void
    {
        $user = User::factory()->create(['email' => null, 'phone' => '01012345678', 'password' => 'Strong-password-123']);
        foreach (['+20 101 234 5678', '00201012345678', '٠١٠١٢٣٤٥٦٧٨'] as $phone) {
            $this->post('/account-deletion', ['login' => $phone, 'password' => 'Strong-password-123', 'confirmation' => 'DELETE_MY_ACCOUNT'])
                ->assertRedirect('/account-deletion')->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('privacy_requests', 1);
        $this->assertSame($user->id, PrivacyRequest::sole()->user_id);
    }

    public function test_wrong_password_missing_confirmation_and_unknown_account_cannot_submit(): void
    {
        $user = User::factory()->create(['email' => 'parent@example.test', 'password' => 'Strong-password-123']);
        $this->from('/account-deletion')->post('/account-deletion', [
            'login' => $user->email, 'password' => 'wrong', 'confirmation' => 'DELETE_MY_ACCOUNT',
        ])->assertRedirect('/account-deletion')->assertSessionHasErrors('confirmation');
        $this->assertNull(session()->getOldInput('password'));
        $this->post('/account-deletion', ['login' => $user->email, 'password' => 'Strong-password-123'])
            ->assertSessionHasErrors('confirmation');
        $this->post('/account-deletion', ['login' => 'unknown@example.test', 'password' => 'Strong-password-123', 'confirmation' => 'DELETE_MY_ACCOUNT'])
            ->assertSessionHasErrors('login');
        $this->assertDatabaseCount('privacy_requests', 0);
        $this->assertNull($user->fresh()->deletion_requested_at);
    }

    public function test_duplicate_legacy_phone_accounts_are_not_guessed(): void
    {
        User::factory()->create(['phone' => '01012345678', 'password' => 'Strong-password-123']);
        User::factory()->create(['phone' => '+201012345678', 'password' => 'Strong-password-123']);
        $this->post('/account-deletion', ['login' => '01012345678', 'password' => 'Strong-password-123', 'confirmation' => 'DELETE_MY_ACCOUNT'])
            ->assertSessionHasErrors('login');
        $this->assertDatabaseCount('privacy_requests', 0);
    }

    public function test_inactive_customer_can_request_deletion_without_regaining_access(): void
    {
        $user = User::factory()->create(['is_active' => false, 'password' => 'Strong-password-123']);
        $this->post('/account-deletion', ['login' => $user->email, 'password' => 'Strong-password-123', 'confirmation' => 'DELETE_MY_ACCOUNT'])
            ->assertRedirect('/account-deletion')->assertSessionHasNoErrors();
        $this->assertSame($user->id, PrivacyRequest::sole()->user_id);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertGuest();
    }

    public function test_web_and_mobile_share_the_same_open_request_including_admin_in_progress_status(): void
    {
        $user = User::factory()->create(['password' => 'Strong-password-123']);
        $data = ['login' => $user->email, 'password' => 'Strong-password-123', 'confirmation' => 'DELETE_MY_ACCOUNT'];
        $this->post('/account-deletion', $data)->assertRedirect('/account-deletion');
        $record = PrivacyRequest::sole();
        $due = $record->due_at->toISOString();
        $record->update(['status' => 'in_progress']);
        Sanctum::actingAs($user, ['mobile']);
        $this->postJson('/api/v1/privacy/requests', [...$data, 'request_type' => 'account_deletion'])
            ->assertOk()->assertJsonPath('data.id', $record->uuid)->assertJsonPath('data.status', 'in_progress');
        $this->post('/account-deletion', $data)->assertRedirect('/account-deletion');
        $this->assertDatabaseCount('privacy_requests', 1);
        $this->assertSame($due, $record->fresh()->due_at->toISOString());
    }

    public function test_guest_submissions_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/account-deletion', ['login' => 'unknown@example.test', 'password' => 'wrong', 'confirmation' => 'DELETE_MY_ACCOUNT'])
                ->assertStatus(302);
        }
        $this->post('/account-deletion', [])->assertStatus(429);
        $this->assertDatabaseCount('privacy_requests', 0);
    }
}
