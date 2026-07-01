<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\SiteImages;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_image_fields_use_local_site_assets(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertDontSee('images.unsplash.com')
            ->assertDontSee('Unsplash');

        foreach (SiteImages::settingsDefaults() as $key => $url) {
            $response
                ->assertSee('name="settings[' . $key . ']"', false)
                ->assertSee($url, false);
        }
    }

    public function test_settings_seeder_stores_local_site_image_assets(): void
    {
        $this->seed(SettingsSeeder::class);

        foreach (SiteImages::settingsDefaults() as $key => $url) {
            $this->assertDatabaseHas('settings', [
                'key' => $key,
                'value' => $url,
            ]);
        }

        $this->assertSame(0, Setting::where('value', 'like', '%images.unsplash.com%')->count());
    }
}
