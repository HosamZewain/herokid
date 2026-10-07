<?php

namespace Tests\Feature;

use App\Jobs\GeneratePublicImageVariants;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PublicImageVariant;
use App\Models\Setting;
use App\Services\Images\PublicCatalogImageOptimizer;
use App\Services\Images\PublicImageSource;
use App\Services\Images\PublicImageVariants;
use App\Support\PublicExperienceCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PublicImageOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        config(['media.public_disk' => 'public', 'media.processing_disk' => 'local']);
    }

    public function test_variants_are_smaller_webp_without_upscaling_and_original_is_preserved(): void
    {
        $path = $this->image();
        $original = Storage::disk('public')->get($path);
        $this->assertTrue(app(PublicCatalogImageOptimizer::class)->generate('public', $path));
        $record = PublicImageVariant::sole();
        $this->assertSame(hash('sha256', $original), $record->source_hash);
        $this->assertSame($original, Storage::disk('public')->get($path));
        $this->assertSame([320, 640, 960, 1200], array_column($record->variants, 'width'));
        foreach ($record->variants as $variant) {
            $bytes = Storage::disk('public')->get($variant['path']);
            $info = getimagesizefromstring($bytes);
            $this->assertSame('image/webp', $info['mime']);
            $this->assertSame($variant['width'], $info[0]);
            $this->assertEquals($variant['width'] / 2, $info[1]);
            $this->assertLessThan(strlen($original), strlen($bytes));
        }
        $this->assertSame([], Storage::disk('local')->allFiles('media-processing'));
    }

    public function test_optimization_is_idempotent_and_new_source_has_a_new_content_path(): void
    {
        $path = $this->image();
        $optimizer = app(PublicCatalogImageOptimizer::class);
        $optimizer->generate('public', $path);
        $before = PublicImageVariant::sole();
        $this->assertFalse($optimizer->generate('public', $path));
        $this->assertSame($before->toArray(), PublicImageVariant::sole()->toArray());
        $this->image($path, 'blue');
        $this->assertTrue($optimizer->generate('public', $path));
        $after = PublicImageVariant::sole();
        $this->assertSame($before->id, $after->id);
        $this->assertNotSame($before->variants[0]['path'], $after->variants[0]['path']);
        Storage::disk('public')->assertExists($before->variants[0]['path']);
    }

    public function test_already_small_images_are_recorded_without_larger_replacements(): void
    {
        $image = new \Imagick;
        $image->newImage(1, 1, new \ImagickPixel('red'), 'png');
        Storage::disk('public')->put('stories/tiny.png', $image->getImageBlob());
        $image->clear();
        $optimizer = app(PublicCatalogImageOptimizer::class);
        $this->assertTrue($optimizer->generate('public', 'stories/tiny.png'));
        foreach (PublicImageVariant::sole()->variants as $variant) {
            $this->assertSame(1, $variant['width']);
            $this->assertLessThan(strlen(Storage::disk('public')->get('stories/tiny.png')), $variant['bytes']);
        }
        $this->assertFalse($optimizer->generate('public', 'stories/tiny.png'));
    }

    public function test_animated_images_are_not_flattened_or_replaced(): void
    {
        $image = new \Imagick;
        foreach (['red', 'blue'] as $color) {
            $frame = new \Imagick;
            $frame->newImage(20, 20, new \ImagickPixel($color), 'gif');
            $image->addImage($frame);
            $frame->clear();
        }
        $original = $image->getImagesBlob();
        Storage::disk('public')->put('stories/animated.gif', $original);
        $image->clear();
        try {
            app(PublicCatalogImageOptimizer::class)->generate('public', 'stories/animated.gif');
            $this->fail('Animation must stay original.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('unsupported', $error->getMessage());
            $this->assertSame($original, Storage::disk('public')->get('stories/animated.gif'));
            $this->assertSame(0, PublicImageVariant::count());
        }
    }

    public function test_private_and_external_sources_are_rejected(): void
    {
        foreach (['orders/photo.jpg', 'child-photos/photo.jpg', 'store/products/../private.jpg', '/stories/photo.jpg'] as $path) {
            $this->assertFalse(PublicImageSource::allowed('public', $path));
        }
        $this->assertFalse(PublicImageSource::allowed('local', 'stories/photo.jpg'));
        $this->assertNull(PublicImageSource::fromUrl('https://other.example/storage/stories/photo.jpg'));
        $this->assertNull(PublicImageSource::fromUrl('/storage/stories/%2e%2e/private.jpg'));
        $this->assertSame('stories/photo.jpg', PublicImageSource::fromUrl(Storage::disk('public')->url('stories/photo.jpg').'?v=1')['path']);
        $this->expectException(RuntimeException::class);
        app(PublicCatalogImageOptimizer::class)->generate('local', 'stories/photo.jpg');
    }

    public function test_unsupported_files_fail_safely_and_clean_processing(): void
    {
        Storage::disk('public')->put('stories/not-an-image.txt', 'Do not encode this');
        try {
            app(PublicCatalogImageOptimizer::class)->generate('public', 'stories/not-an-image.txt');
            $this->fail('Invalid image must not be encoded.');
        } catch (\Throwable $error) {
            $this->assertSame(0, PublicImageVariant::count());
            $this->assertSame([], Storage::disk('local')->allFiles('media-processing'));
            Storage::disk('public')->assertExists('stories/not-an-image.txt');
        }
    }

    public function test_pixel_and_byte_limits_reject_sources_without_touching_originals(): void
    {
        $path = $this->image();
        foreach (['max_pixels' => 100, 'max_bytes' => 10] as $key => $limit) {
            $previous = config('public_images.'.$key);
            config(['public_images.'.$key => $limit]);
            try {
                app(PublicCatalogImageOptimizer::class)->generate('public', $path);
                $this->fail('Unsafe image accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('safe', $error->getMessage());
            }
            config(['public_images.'.$key => $previous]);
        }
        $this->assertSame(0, PublicImageVariant::count());
        Storage::disk('public')->assertExists($path);
    }

    public function test_transparency_and_aspect_ratio_are_preserved(): void
    {
        $image = new \Imagick;
        $image->newImage(800, 400, new \ImagickPixel('transparent'), 'png');
        $draw = new \ImagickDraw;
        $draw->setFillColor('red');
        $draw->rectangle(200, 100, 600, 300);
        $image->drawImage($draw);
        $image->addNoiseImage(\Imagick::NOISE_GAUSSIAN, \Imagick::CHANNEL_RED | \Imagick::CHANNEL_GREEN | \Imagick::CHANNEL_BLUE);
        // Include harmless metadata to ensure the compressed variant is smaller.
        $image->setImageProperty('comment', str_repeat('public sample ', 3000));
        Storage::disk('public')->put('stories/alpha.png', $image->getImageBlob());
        $image->clear();
        app(PublicCatalogImageOptimizer::class)->generate('public', 'stories/alpha.png');
        $variant = PublicImageVariant::sole()->variants[0];
        $result = new \Imagick;
        $result->readImageBlob(Storage::disk('public')->get($variant['path']));
        $this->assertLessThan(0.01, $result->getImagePixelColor(0, 0)->getColor(true)['a']);
        $this->assertEquals(2, $result->getImageWidth() / $result->getImageHeight());
        $result->clear();
    }

    public function test_db_failure_removes_only_new_derivatives(): void
    {
        $path = $this->image();
        PublicImageVariant::creating(fn () => throw new RuntimeException('synthetic database failure'));
        try {
            app(PublicCatalogImageOptimizer::class)->generate('public', $path);
            $this->fail('Expected database failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('synthetic database failure', $error->getMessage());
            $this->assertSame([], Storage::disk('public')->allFiles('display-images'));
            Storage::disk('public')->assertExists($path);
            $this->assertSame([], Storage::disk('local')->allFiles('media-processing'));
        } finally {
            PublicImageVariant::flushEventListeners();
        }
    }

    public function test_failed_persistent_upload_does_not_publish_metadata(): void
    {
        $path = $this->image();
        $persistent = Mockery::mock(Storage::disk('public'));
        $persistent->shouldReceive('put')->once()->andReturnFalse();
        $processing = Storage::disk('local');
        Storage::partialMock()->shouldReceive('disk')->with('public')->andReturn($persistent);
        Storage::shouldReceive('disk')->with('local')->andReturn($processing);
        try {
            app(PublicCatalogImageOptimizer::class)->generate('public', $path);
            $this->fail('Expected storage failure.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('persist', $error->getMessage());
            $this->assertSame(0, PublicImageVariant::count());
            $this->assertSame([], $persistent->allFiles('display-images'));
            $this->assertTrue($persistent->exists($path));
        }
    }

    public function test_configured_public_disk_uses_streams_and_local_processing(): void
    {
        Storage::fake('public_cloud');
        config(['media.public_disk' => 'public_cloud']);
        $path = $this->image(disk: 'public_cloud');
        $persistent = Mockery::mock(Storage::disk('public_cloud'));
        $persistent->shouldNotReceive('path');
        $processing = Storage::disk('local');
        Storage::partialMock()->shouldReceive('disk')->with('public_cloud')->andReturn($persistent);
        Storage::shouldReceive('disk')->with('local')->andReturn($processing);
        app(PublicCatalogImageOptimizer::class)->generate('public_cloud', $path);
        $this->assertSame('public_cloud', PublicImageVariant::sole()->source_disk);
        $this->assertTrue($persistent->exists(PublicImageVariant::sole()->variants[0]['path']));
        $this->assertSame([], $processing->allFiles('media-processing'));
    }

    public function test_metadata_is_batched_and_rendering_does_not_write_or_queue(): void
    {
        $path = $this->image();
        app(PublicCatalogImageOptimizer::class)->generate('public', $path);
        $url = Storage::disk('public')->url($path);
        $service = app(PublicImageVariants::class);
        DB::enableQueryLog();
        $service->prime([$url, $url, Storage::disk('public')->url('stories/no-variants.jpg')]);
        for ($i = 0; $i < 10; $i++) {
            $this->assertNotEmpty($service->presentation($url)['srcset']);
            $this->assertSame('', $service->presentation(Storage::disk('public')->url('stories/no-variants.jpg'))['srcset']);
        }
        $this->assertCount(1, array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'public_image_variants')));
        DB::disableQueryLog();
        $html = Blade::render('<x-public-image :src="$url" sizes="320px" loading="lazy" />', compact('url'));
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('sizes="320px"', $html);
        $this->assertStringContainsString('data-public-image-original="'.$url.'"', $html);
        $this->assertSame(1, PublicImageVariant::count());
        Queue::assertNothingPushed();
    }

    public function test_public_get_preserves_source_paths_prices_and_original_api_image_urls(): void
    {
        $path = $this->image('store/products/display.png');
        $product = $this->product(['featured_image' => $path, 'gallery_images' => [$path]]);
        app(PublicCatalogImageOptimizer::class)->generate('public', $path);
        $originalUrl = $product->featured_image_url;
        $before = $product->fresh()->getAttributes();
        foreach ([route('home'), route('shop.index'), route('shop.product.show', $product)] as $url) {
            $this->get($url)->assertOk()->assertSee('display-images/v1/', false)->assertSee($product->name_ar);
        }
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertSame($originalUrl, $product->fresh()->featured_image_url);
        $this->assertSame(12000, $product->fresh()->price_cents);
        $this->assertSame(1, PublicImageVariant::count());
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_group_assignments')->count());
    }

    public function test_catalog_image_writes_queue_asynchronously_but_unrelated_changes_do_not(): void
    {
        DB::partialMock()->shouldReceive('afterCommit')->andReturnUsing(fn ($callback) => $callback());
        $product = $this->product(['featured_image' => 'store/products/new.png']);
        Queue::assertPushed(GeneratePublicImageVariants::class, fn ($job) => $job->connection === config('public_images.queue_connection') && $job->connection !== 'sync');
        $product->update(['name_ar' => 'اسم جديد', 'price_cents' => 15000]);
        Queue::assertPushed(GeneratePublicImageVariants::class, 1);
        $product->update(['gallery_images' => ['store/products/gallery/new.png']]);
        Queue::assertPushed(GeneratePublicImageVariants::class, 3);
        $this->assertSame(0, PublicImageVariant::count());
    }

    public function test_unavailable_queue_does_not_prevent_a_catalog_edit(): void
    {
        DB::partialMock()->shouldReceive('afterCommit')->andReturnUsing(fn ($callback) => $callback());
        Bus::partialMock()->shouldReceive('dispatch')->andThrow(new RuntimeException('synthetic queue failure'));
        $product = $this->product(['featured_image' => 'store/products/sample.png']);
        $this->assertSame('store/products/sample.png', $product->fresh()->featured_image);
        $this->assertSame(0, PublicImageVariant::count());
    }

    public function test_backfill_is_dry_run_by_default_bounded_and_idempotent(): void
    {
        $path = $this->image('store/products/backfill.png');
        $this->product(['featured_image' => $path]);
        $this->artisan('images:optimize-catalog')->expectsOutputToContain('No files or records changed')->assertSuccessful();
        $this->assertSame(0, PublicImageVariant::count());
        $this->artisan('images:optimize-catalog --apply --sync --limit=1')->assertSuccessful();
        $this->assertSame(1, PublicImageVariant::count());
        $before = PublicImageVariant::sole()->toArray();
        $this->artisan('images:optimize-catalog --apply --sync --limit=1')->assertSuccessful();
        $this->assertSame($before, PublicImageVariant::sole()->toArray());
        $this->artisan('images:optimize-catalog --limit=0')->assertExitCode(2);
    }

    public function test_bundled_art_has_smaller_manifest_variants_and_responsive_preload(): void
    {
        $manifest = json_decode(file_get_contents(public_path('images/optimized/manifest.json')), true);
        foreach ($manifest as $source => $variants) {
            foreach ($variants as $variant) {
                $this->assertFileExists(public_path($variant['path']));
                $this->assertLessThan(filesize(public_path($source)), filesize(public_path($variant['path'])));
            }
        }
        $this->get(route('home'))->assertOk()->assertSee('imagesrcset=', false)->assertSee('images/optimized/', false);
    }

    public function test_copy_migration_preserves_custom_settings_and_updates_only_old_defaults(): void
    {
        Setting::whereIn('key', array_keys(PublicExperienceCopy::DEFAULTS))->delete();
        foreach (PublicExperienceCopy::LEGACY as $key => $value) {
            Setting::create(compact('key', 'value'));
        }
        Setting::where('key', 'hiw_step1_title')->update(['value' => 'عنوان خاص بالإدارة']);
        $migration = require database_path('migrations/2026_10_08_000002_refresh_public_experience_copy.php');
        $migration->up();
        $this->assertSame('عنوان خاص بالإدارة', Setting::where('key', 'hiw_step1_title')->value('value'));
        $this->assertSame(PublicExperienceCopy::DEFAULTS['footer_brand_description'], Setting::where('key', 'footer_brand_description')->value('value'));
        $this->get(route('how-it-works'))->assertOk()->assertSee('عنوان خاص بالإدارة')->assertSee('المنتجات الجاهزة لا تحتاج هذه الخطوة.')->assertDontSee('رحلة قصتك من الفكرة');
        $this->get(route('home'))->assertOk()->assertSee(PublicExperienceCopy::DEFAULTS['footer_brand_description']);
        $migration->up();
        $this->assertSame('عنوان خاص بالإدارة', Setting::where('key', 'hiw_step1_title')->value('value'));
    }

    private function image(string $path = 'stories/sample.png', string $color = 'red', string $disk = 'public'): string
    {
        $image = new \Imagick;
        $image->newImage(1200, 600, new \ImagickPixel($color), 'png');
        $image->addNoiseImage(\Imagick::NOISE_GAUSSIAN, \Imagick::CHANNEL_RED | \Imagick::CHANNEL_GREEN | \Imagick::CHANNEL_BLUE);
        $image->setImageProperty('comment', str_repeat('public sample ', 3000));
        Storage::disk($disk)->put($path, $image->getImageBlob());
        $image->clear();

        return $path;
    }

    private function product(array $attributes = []): Product
    {
        $category = ProductCategory::firstOrCreate(['slug' => 'optimized-images'], ['name_ar' => 'منتجات', 'is_active' => true, 'show_in_store' => true]);

        return Product::create(array_merge(['product_category_id' => $category->id, 'name_ar' => 'منتج تجريبي', 'slug' => 'optimized-sample', 'price_cents' => 12000, 'is_active' => true, 'purchase_mode' => 'standalone', 'personalization_mode' => 'none'], $attributes));
    }
}
