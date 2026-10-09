<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Story;
use App\Support\ProductPersonalizationSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileCatalogContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_story_detail_works_beyond_the_first_thirty_items_and_in_english_ui(): void
    {
        for ($index = 0; $index < 35; $index++) {
            Story::create(['title' => 'قصة '.$index, 'slug' => 'mobile-deep-story-'.$index, 'language' => 'ar', 'gender' => 'both', 'age_range' => '6-9', 'price' => 149, 'active' => true]);
        }
        $this->getJson('/api/v1/catalog/story/mobile-deep-story-0?locale=en')
            ->assertOk()->assertJsonPath('data.slug', 'mobile-deep-story-0')
            ->assertJsonPath('data.personalization.mode', 'story')
            ->assertJsonPath('data.personalization.schema.fields.photos.min_files', 2)
            ->assertJsonPath('data.personalization.schema.fields.photos.max_files', 3);
    }

    public function test_product_detail_returns_the_actual_admin_schema_not_story_photo_defaults(): void
    {
        $category = ProductCategory::create(['name_ar' => 'هدايا', 'name_en' => 'Gifts', 'slug' => 'mobile-gift-contract', 'is_active' => true, 'show_in_store' => true]);
        $schema = ProductPersonalizationSchema::fromAdminInput([
            'child_name' => ['enabled' => true, 'required' => true, 'label' => 'الاسم الثلاثي'],
            'school_name' => ['enabled' => true, 'required' => true],
            'class_name' => ['enabled' => true, 'required' => false],
            'photos' => ['enabled' => true, 'required' => true, 'min_files' => 1, 'max_files' => 3],
        ]);
        $product = Product::create(['product_category_id' => $category->id, 'name_ar' => 'ستيكر', 'name_en' => 'Sticker', 'slug' => 'mobile-field-sticker', 'price_cents' => 34500, 'is_active' => true, 'fulfillment_type' => 'physical', 'purchase_mode' => 'standalone', 'personalization_mode' => 'collect_child_details', 'personalization_fields' => $schema, 'inventory_mode' => 'no_tracking']);
        $this->getJson('/api/v1/catalog/product/'.$product->slug)
            ->assertOk()->assertJsonPath('data.personalization.schema.fields.child_name.label', 'الاسم الثلاثي')
            ->assertJsonPath('data.personalization.schema.fields.school_name.required', true)
            ->assertJsonPath('data.personalization.schema.fields.child_age.enabled', false)
            ->assertJsonPath('data.personalization.schema.fields.photos.min_files', 1)
            ->assertJsonPath('data.details.available', true);
        $schema['fields']['photos']['max_files'] = 1;
        $product->update(['personalization_fields' => $schema]);
        $this->getJson('/api/v1/catalog/product/'.$product->slug)
            ->assertOk()->assertJsonPath('data.personalization.schema.fields.photos.max_files', 1)
            ->assertJsonPath('data.personalization.photo_max_bytes', 15 * 1024 * 1024)
            ->assertJsonPath('data.personalization.supported_languages', ['ar', 'en']);
        $product->update(['personalization_mode' => 'none']);
        $this->getJson('/api/v1/catalog/product/'.$product->slug)
            ->assertOk()->assertJsonPath('data.personalization.schema.fields.photos.enabled', false);
    }

    public function test_unavailable_items_are_not_exposed_by_direct_detail_links(): void
    {
        Story::create(['title' => 'Inactive', 'slug' => 'mobile-inactive-story', 'language' => 'ar', 'gender' => 'both', 'age_range' => '6-9', 'price' => 149, 'active' => false]);
        $this->getJson('/api/v1/catalog/story/mobile-inactive-story')->assertNotFound();
    }

    public function test_gallery_urls_use_the_configured_hybrid_public_disk(): void
    {
        config(['media.public_disk' => 's3_public', 'filesystems.disks.s3_public' => [
            'driver' => 's3', 'key' => 'qa-key', 'secret' => 'qa-secret', 'region' => 'eu-west-1',
            'bucket' => 'qa-public', 'url' => 'https://media.example.test',
        ]]);
        $story = Story::create(['title' => 'Gallery', 'slug' => 'hybrid-gallery', 'language' => 'ar', 'gender' => 'both',
            'age_range' => '6-9', 'price' => 149, 'active' => true, 'gallery_images' => ['stories/gallery.jpg']]);
        $this->getJson('/api/v1/catalog/story/'.$story->slug)->assertOk()
            ->assertJsonPath('data.details.gallery_images.0', 'https://media.example.test/stories/gallery.jpg');
    }

    public function test_interface_locale_does_not_filter_out_a_story_language(): void
    {
        foreach (['ar', 'en'] as $language) {
            Story::create(['title' => 'Story '.$language, 'slug' => 'locale-story-'.$language, 'language' => $language, 'gender' => 'both', 'age_range' => '6-9', 'price' => 149, 'active' => true]);
        }
        $this->getJson('/api/v1/catalog?type=stories&locale=en')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/catalog?type=stories&locale=en&lang=ar')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'locale-story-ar');
    }
}
