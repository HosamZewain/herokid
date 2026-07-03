<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('slug')->unique();
            $table->text('short_description_ar')->nullable();
            $table->text('short_description_en')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('icon')->nullable();
            $table->string('visual_accent')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('show_in_store')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('slug')->unique();
            $table->text('short_description_ar')->nullable();
            $table->text('short_description_en')->nullable();
            $table->longText('description_ar')->nullable();
            $table->longText('description_en')->nullable();
            $table->string('featured_image')->nullable();
            $table->json('gallery_images')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('sale_price_cents')->nullable();
            $table->string('sku')->nullable()->index();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('age_groups')->nullable();
            $table->json('features')->nullable();
            $table->string('fulfillment_type')->default('physical');
            $table->string('purchase_mode')->default('standalone');
            $table->string('personalization_mode')->default('none');
            $table->string('inventory_mode')->default('no_tracking');
            $table->integer('stock_quantity')->nullable();
            $table->unsignedInteger('production_lead_time_days')->default(0);
            $table->text('shipping_notes_ar')->nullable();
            $table->text('shipping_notes_en')->nullable();
            $table->string('seo_title_ar')->nullable();
            $table->string('seo_title_en')->nullable();
            $table->text('seo_description_ar')->nullable();
            $table->text('seo_description_en')->nullable();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('image')->nullable();
            $table->integer('price_adjustment_cents')->default(0);
            $table->unsignedInteger('price_override_cents')->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('attributes')->nullable();
            $table->timestamps();
        });

        Schema::create('homepage_store_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('subtitle_ar')->nullable();
            $table->text('subtitle_en')->nullable();
            $table->unsignedInteger('max_products')->default(4);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('cta_text_ar')->nullable();
            $table->string('cta_text_en')->nullable();
            $table->string('cta_url')->nullable();
            $table->timestamps();
        });

        Schema::create('product_upsell_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('source_story_id')->nullable()->constrained('stories')->nullOnDelete();
            $table->foreignId('source_story_category_id')->nullable()->constrained('story_categories')->nullOnDelete();
            $table->string('age_group')->nullable();
            $table->string('gender')->nullable();
            $table->string('trigger_scope')->default('story_added');
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['trigger_scope', 'is_active', 'priority']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('item_type');
            $table->foreignId('story_id')->nullable()->constrained('stories')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('linked_order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->string('linked_cart_item_key')->nullable();
            $table->string('title');
            $table->string('sku')->nullable();
            $table->unsignedInteger('unit_price_cents')->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('total_price_cents')->default(0);
            $table->string('personalization_mode')->nullable();
            $table->json('item_snapshot')->nullable();
            $table->json('variant_snapshot')->nullable();
            $table->json('personalization_snapshot')->nullable();
            $table->string('fulfillment_status')->default('pending');
            $table->timestamps();
        });

        DB::table('product_categories')->insert([
            [
                'name_ar' => 'كتب أنشطة وتعلّم',
                'name_en' => 'Activities and Learning Books',
                'slug' => 'activities-learning',
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name_ar' => 'قصص جاهزة للقراءة',
                'name_en' => 'Ready-to-Read Stories',
                'slug' => 'ready-stories',
                'sort_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name_ar' => 'هدايا مخصصة',
                'name_en' => 'Personalized Gifts',
                'slug' => 'personalized-gifts',
                'sort_order' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $categories = DB::table('product_categories')->pluck('id', 'slug');

        DB::table('homepage_store_sections')->insert([
            [
                'product_category_id' => $categories['activities-learning'] ?? null,
                'title_ar' => 'أنشطة ممتعة وتعلّم كل يوم',
                'title_en' => 'Fun Activities for Everyday Learning',
                'max_products' => 4,
                'sort_order' => 10,
                'cta_text_ar' => 'عرض الكل',
                'cta_text_en' => 'View all',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_category_id' => $categories['ready-stories'] ?? null,
                'title_ar' => 'قصص جاهزة للقراءة',
                'title_en' => 'Ready-to-Read Stories',
                'max_products' => 4,
                'sort_order' => 20,
                'cta_text_ar' => 'عرض الكل',
                'cta_text_en' => 'View all',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_category_id' => $categories['personalized-gifts'] ?? null,
                'title_ar' => 'هدايا تجعل القصة أجمل',
                'title_en' => 'Gifts That Make the Story Better',
                'max_products' => 4,
                'sort_order' => 30,
                'cta_text_ar' => 'عرض الكل',
                'cta_text_en' => 'View all',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('product_upsell_rules');
        Schema::dropIfExists('homepage_store_sections');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
