<?php

namespace Tests\Feature;

use App\Contracts\MobileSocialIdentityVerifier;
use App\Models\User;
use App\Services\Mobile\MobileTokenIssuer;
use App\Services\Mobile\ProviderTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MobileAuthenticationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rolls_back_when_the_device_token_cannot_be_issued(): void
    {
        $issuer = Mockery::mock(MobileTokenIssuer::class);
        $issuer->shouldReceive('issue')->once()->andThrow(new RuntimeException('Token storage unavailable'));
        $this->app->instance(MobileTokenIssuer::class, $issuer);
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Rollback Parent',
                'email' => 'rollback@example.com',
                'phone' => '01099999999',
                'password' => 'SecurePassword123!',
                'password_confirmation' => 'SecurePassword123!',
                'device_name' => 'Broken device',
            ]);
            $this->fail('Registration should fail when a token cannot be issued.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Token storage unavailable', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_parent_can_request_and_consume_a_one_time_phone_code(): void
    {
        config(['services.mobile_otp.driver' => 'array']);

        $challenge = $this->postJson('/api/v1/auth/otp/request', [
            'phone' => '010 1234 5678',
        ])->assertAccepted()
            ->assertJsonStructure(['data' => ['challenge_id', 'expires_at', 'test_code']]);

        $uuid = $challenge->json('data.challenge_id');
        $code = $challenge->json('data.test_code');
        $stored = DB::table('mobile_otp_challenges')->where('uuid', $uuid)->first();
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('01012345678', $stored->phone_encrypted);

        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $uuid,
            'code' => '000000',
            'device_name' => 'Android test',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $login = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $uuid,
            'code' => $code,
            'name' => 'OTP Parent',
            'device_name' => 'Android test',
        ])->assertOk()
            ->assertJsonPath('data.user.phone', '01012345678')
            ->assertJsonPath('data.user.name', 'OTP Parent');

        $this->assertNotEmpty($login->json('data.token'));
        $this->assertDatabaseHas('users', ['phone' => '01012345678']);

        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $uuid,
            'code' => $code,
            'device_name' => 'Second device',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_social_login_uses_only_a_server_verified_identity(): void
    {
        $this->app->instance(MobileSocialIdentityVerifier::class, new class implements MobileSocialIdentityVerifier
        {
            public function verify(string $provider, string $identityToken): array
            {
                return [
                    'subject' => 'provider-user-123',
                    'email' => 'verified@example.com',
                    'email_verified' => true,
                    'name' => 'Verified Parent',
                ];
            }
        });

        $first = $this->postJson('/api/v1/auth/social', [
            'provider' => 'google',
            'id_token' => 'provider-signed-token',
            'name' => 'Untrusted Name',
            'device_name' => 'Pixel',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'verified@example.com')
            ->assertJsonPath('data.user.name', 'Verified Parent');

        $this->postJson('/api/v1/auth/social', [
            'provider' => 'google',
            'id_token' => 'provider-signed-token',
            'device_name' => 'iPad',
        ])->assertOk()->assertJsonPath('data.user.id', $first->json('data.user.id'));

        $this->assertSame(1, User::query()->where('email', 'verified@example.com')->count());
        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_google_verifier_rejects_wrong_audience_and_accepts_configured_client(): void
    {
        config(['services.mobile_oauth.google_client_ids' => ['configured-client-id']]);
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'iss' => 'https://accounts.google.com',
                'aud' => 'configured-client-id',
                'exp' => now()->addMinutes(5)->timestamp,
                'sub' => 'google-subject',
                'email' => 'parent@example.com',
                'email_verified' => 'true',
                'name' => 'Google Parent',
            ]),
        ]);

        $identity = app(ProviderTokenVerifier::class)->verify('google', 'signed-token');
        $this->assertSame('google-subject', $identity['subject']);
        $this->assertSame('parent@example.com', $identity['email']);

        config(['services.mobile_oauth.google_client_ids' => ['different-client']]);
        $this->expectException(ValidationException::class);
        app(ProviderTokenVerifier::class)->verify('google', 'signed-token');
    }
}
