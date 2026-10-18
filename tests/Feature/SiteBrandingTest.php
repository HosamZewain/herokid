<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Support\RequestSettings;
use App\Support\SiteBranding;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function settings(array $extra = []): array
    {
        return $extra + ['settings' => ['site_name' => 'HeroKid', 'site_email' => 'qa@example.test', 'whatsapp_number' => '01012345678', 'price_soft_cover' => 200, 'price_hard_cover' => 300]];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function upload(string $name = 'brand.png', int $width = 1200, int $height = 400): void
    {
        $this->post(route('admin.settings.update'), $this->settings([
            '_method' => 'PUT', 'site_logo_file' => UploadedFile::fake()->image($name, $width, $height),
        ]))->assertSessionHasNoErrors()->assertRedirect(route('admin.settings.index'));
    }

    public function test_new_default_logo_is_served_with_correct_dimensions_and_revalidation(): void
    {
        $logo = SiteBranding::presentation();
        $this->assertSame(384, $logo['width']);
        $this->assertSame(150, $logo['height']);
        $this->assertFileExists(public_path(SiteBranding::DEFAULT_PATH));
        $response = $this->get(route('branding.logo', ['width' => 384]))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('must-revalidate', $response->headers->get('Cache-Control'));
        $etag = $response->headers->get('ETag');
        $this->get(route('branding.logo', ['width' => 384]), ['If-None-Match' => $etag])->assertStatus(304);
        $this->get('/site-logo/999.png')->assertNotFound();
        $this->get('/site-logo/../private.png')->assertNotFound();
    }

    public function test_admin_can_preview_upload_and_persist_a_responsive_logo_without_changing_other_settings(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin())->get(route('admin.settings.index'))->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)->assertSee('name="site_logo_file"', false)->assertSee('site-logo-preview');
        $this->upload();
        $metadata = json_decode(Setting::where('key', 'site_logo')->value('value'), true);
        $this->assertSame('public', $metadata['disk']);
        $this->assertCount(3, $metadata['variants']);
        foreach ($metadata['variants'] as $variant) {
            Storage::disk('public')->assertExists($variant['path']);
            $dimensions = getimagesizefromstring(Storage::disk('public')->get($variant['path']));
            $this->assertSame($variant['width'], $dimensions[0]);
            $this->assertSame($variant['height'], $dimensions[1]);
            $this->assertEquals(3, $variant['width'] / $variant['height']);
        }
        $this->put(route('admin.settings.update'), $this->settings())->assertSessionHasNoErrors();
        $this->assertSame($metadata, json_decode(Setting::where('key', 'site_logo')->value('value'), true));
        $this->assertDatabaseHas('settings', ['key' => 'site_name', 'value' => 'HeroKid']);
    }

    public function test_replacing_and_resetting_logo_updates_previously_cached_urls_without_deleting_old_uploads(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());
        $url = route('branding.logo', ['width' => 384]);
        $defaultTag = $this->get($url)->headers->get('ETag');
        $this->upload();
        $old = json_decode(Setting::where('key', 'site_logo')->value('value'), true);
        $firstTag = $this->get($url, ['If-None-Match' => $defaultTag])->assertOk()->headers->get('ETag');
        $this->assertNotSame($defaultTag, $firstTag);
        $this->upload('next-brand.png');
        $nextTag = $this->get($url, ['If-None-Match' => $firstTag])->assertOk()->headers->get('ETag');
        $this->assertNotSame($firstTag, $nextTag);
        $this->get($url, ['If-None-Match' => $nextTag])->assertStatus(304);
        $this->put(route('admin.settings.update'), $this->settings(['reset_site_logo' => '1']))->assertSessionHasNoErrors();
        $this->assertSame($defaultTag, $this->get($url, ['If-None-Match' => $nextTag])->assertOk()->headers->get('ETag'));
        foreach ($old['variants'] as $variant) {
            Storage::disk('public')->assertExists($variant['path']);
        }
    }

    public function test_uploaded_logo_works_on_the_configured_public_disk_and_is_streamed_same_origin(): void
    {
        config(['media.public_disk' => 's3_public']);
        Storage::fake('s3_public');
        $this->actingAs($this->admin());
        $this->upload();
        $metadata = json_decode(Setting::where('key', 'site_logo')->value('value'), true);
        $this->assertSame('s3_public', $metadata['disk']);
        $response = $this->get(route('branding.logo', ['width' => 384]))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(Storage::disk('s3_public')->get($metadata['variants'][1]['path']), $response->streamedContent());
    }

    public function test_invalid_uploads_or_invalid_settings_do_not_replace_saved_logo(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());
        $this->upload();
        $before = Setting::where('key', 'site_logo')->value('value');
        foreach ([
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('logo.png', '<?php echo "not an image";'),
            UploadedFile::fake()->image('huge.png', 4097, 24),
            UploadedFile::fake()->create('large.png', 5121, 'image/png'),
        ] as $file) {
            $this->post(route('admin.settings.update'), $this->settings(['_method' => 'PUT', 'site_logo_file' => $file]))->assertSessionHasErrors('site_logo_file');
        }
        $this->post(route('admin.settings.update'), ['_method' => 'PUT', 'site_logo_file' => UploadedFile::fake()->image('brand.png', 200, 80)])->assertSessionHasErrors('settings.site_name');
        $this->assertSame($before, Setting::where('key', 'site_logo')->value('value'));
        $this->assertCount(3, Storage::disk('public')->allFiles('branding/logos'));
    }

    public function test_logo_cannot_be_updated_without_settings_permission_or_by_injecting_a_storage_path(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->put(route('admin.settings.update'), $this->settings())->assertForbidden();
        $admin = $this->admin();
        $admin->permissions()->detach(Permission::where('key', 'settings.site.update')->value('id'));
        $this->actingAs($admin)->put(route('admin.settings.update'), $this->settings())->assertForbidden();
        $this->actingAs($this->admin());
        $payload = $this->settings();
        $payload['settings']['site_logo'] = '/private/customer-photo.png';
        $this->put(route('admin.settings.update'), $payload)->assertSessionHasErrors('settings.site_logo');
        $this->assertDatabaseMissing('settings', ['key' => 'site_logo']);
    }

    public function test_site_pages_and_structured_data_use_one_logo_source(): void
    {
        foreach (['home', 'about', 'shop.index', 'login', 'cart.index', 'story-library.index', 'app.download'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('/site-logo/384.png', false)
                ->assertDontSee('/images/logo-192.png', false)->assertDontSee('/images/logo-320.png', false);
        }
        $this->get(route('home'))->assertSee('"url":"'.SiteBranding::url().'"', false);
    }

    public function test_corrupt_or_unsafe_stored_metadata_falls_back_without_accessing_arbitrary_files(): void
    {
        foreach ([
            ['disk' => 'public', 'variants' => [['path' => [], 'width' => 384, 'height' => 150]]],
            ['disk' => 'local', 'variants' => [['path' => 'orders/photos/private.png', 'width' => 384, 'height' => 150]]],
            ['disk' => 'public', 'variants' => [['path' => 'branding/logos/'.Str::uuid().'/../../private.png', 'width' => 384, 'height' => 150]]],
        ] as $bad) {
            Setting::updateOrCreate(['key' => 'site_logo'], ['value' => json_encode($bad)]);
            RequestSettings::forget();
            $this->assertNull(SiteBranding::source(384)['disk']);
            $this->get(route('branding.logo', ['width' => 384]))->assertOk();
        }
    }

    public function test_storage_failure_preserves_current_logo_and_conflicting_actions_are_rejected(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());
        $this->upload();
        $before = Setting::where('key', 'site_logo')->value('value');
        $this->post(route('admin.settings.update'), $this->settings([
            '_method' => 'PUT', 'reset_site_logo' => '1', 'site_logo_file' => UploadedFile::fake()->image('new.png', 200, 80),
        ]))->assertSessionHasErrors('site_logo_file');
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('public')->once()->andReturn($disk);
        $this->post(route('admin.settings.update'), $this->settings([
            '_method' => 'PUT', 'site_logo_file' => UploadedFile::fake()->image('new.png', 200, 80),
        ]))->assertSessionHasErrors('site_logo_file');
        $this->assertSame($before, Setting::where('key', 'site_logo')->value('value'));
    }
}
