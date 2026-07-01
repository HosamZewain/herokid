<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertSee('Login');
        $response->assertSee('Register');
        $response->assertSee('رقم الموبايل أو البريد الإلكتروني');
    }

    public function test_new_users_can_register_with_phone_number(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'login' => '201000000000',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => null,
            'phone' => '201000000000',
        ]);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_new_users_can_register_with_email_address(): void
    {
        $response = $this->post('/register', [
            'name' => 'Email User',
            'login' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'name' => 'Email User',
            'email' => 'test@example.com',
            'phone' => null,
        ]);
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
