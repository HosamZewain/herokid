<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileMinimalRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $values = []): array
    {
        return array_replace(['name' => 'Test Parent', 'login' => '01012345678', 'password' => 'SafePassword123!',
            'password_confirmation' => 'SafePassword123!', 'device_name' => 'Local QA'], $values);
    }

    public function test_phone_only_account_has_no_fake_email_and_can_login_in_equivalent_formats(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registration())
            ->assertCreated()->assertJsonPath('data.user.email', null)->assertJsonPath('data.user.phone', '01012345678')
            ->assertJsonPath('data.user.phone_verified', false);
        foreach (['010 1234 5678', '+201012345678', '٠١٠١٢٣٤٥٦٧٨'] as $phone) {
            $this->postJson('/api/v1/auth/login', ['login' => $phone, 'password' => 'SafePassword123!', 'device_name' => 'QA'])
                ->assertOk()->assertJsonPath('data.user.id', $response->json('data.user.id'));
        }
    }

    public function test_email_only_and_legacy_registration_payloads_still_work(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration(['login' => ' PARENT@EXAMPLE.COM ']))
            ->assertCreated()->assertJsonPath('data.user.email', 'parent@example.com')->assertJsonPath('data.user.phone', null);
        $legacy = $this->registration(['email' => 'legacy@example.com', 'phone' => '01112345678']);
        unset($legacy['login']);
        $this->postJson('/api/v1/auth/register', $legacy)->assertCreated()->assertJsonPath('data.user.phone', '01112345678');
    }

    public function test_international_registration_is_stored_canonically_and_cannot_be_registered_locally_again(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration(['login' => '00201012345678']))
            ->assertCreated()->assertJsonPath('data.user.phone', '01012345678')->assertJsonPath('data.user.email', null);
        $this->postJson('/api/v1/auth/register', $this->registration())
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_otp_stays_disabled_and_missing_password_cannot_create_an_account_or_session(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '01012345678'])->assertNotFound();
        $this->postJson('/api/v1/auth/otp/verify', [])->assertNotFound();
        $payload = $this->registration();
        unset($payload['password'], $payload['password_confirmation']);
        $this->postJson('/api/v1/auth/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/login', ['login' => '01012345678', 'device_name' => 'QA'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_existing_website_phone_cannot_be_registered_again_with_a_country_prefix(): void
    {
        $account = User::factory()->create(['phone' => '01012345678', 'email' => null]);
        $this->postJson('/api/v1/auth/register', $this->registration(['login' => '+201012345678']))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $account->id, 'email' => null]);
    }

    public function test_invalid_identifier_missing_identifier_and_weak_password_are_rejected(): void
    {
        foreach (['', 'not a phone', '123', 'broken@@email'] as $identifier) {
            $this->postJson('/api/v1/auth/register', $this->registration(['login' => $identifier]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/register', $this->registration(['password' => '123', 'password_confirmation' => '123']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_contact_arrays_are_rejected_before_normalization(): void
    {
        foreach (['login', 'email', 'phone'] as $field) {
            $this->postJson('/api/v1/auth/register', $this->registration([$field => ['bad-type']]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_phone_only_customer_can_update_profile_without_being_forced_to_add_email(): void
    {
        $user = User::factory()->create(['phone' => '01012345678', 'email' => null]);
        $token = $user->createToken('QA', ['mobile'])->plainTextToken;
        $this->withToken($token)->patchJson('/api/v1/me', ['name' => 'Updated Parent', 'phone' => '01012345678'])
            ->assertOk()->assertJsonPath('data.user.email', null)->assertJsonPath('data.user.name', 'Updated Parent');
    }

    public function test_website_account_password_is_required_and_ambiguous_phone_accounts_are_not_guessed(): void
    {
        User::factory()->create(['phone' => '01012345678', 'password' => 'WebsitePassword123!']);
        $payload = ['login' => '+201012345678', 'password' => 'WrongPassword!', 'device_name' => 'QA'];
        $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable()->assertJsonValidationErrors('login');
        $payload['password'] = 'WebsitePassword123!';
        $this->postJson('/api/v1/auth/login', $payload)->assertOk();
        User::factory()->create(['phone' => '+201012345678', 'password' => 'WebsitePassword123!']);
        $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable()->assertJsonValidationErrors('login');
    }
}
