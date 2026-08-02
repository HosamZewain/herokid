<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_packages', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('name');
            $table->unsignedTinyInteger('story_count')->default(0)->after('features');
            $table->boolean('show_in_store')->default(true)->after('active');
            $table->boolean('show_on_homepage')->default(true)->after('show_in_store');
        });

        DB::table('pricing_packages')->orderBy('id')->get()->each(function ($package): void {
            $base = Str::slug((string) $package->name) ?: 'package-'.$package->id;
            $slug = $base;
            $suffix = 2;

            while (DB::table('pricing_packages')->where('slug', $slug)->where('id', '!=', $package->id)->exists()) {
                $slug = $base.'-'.$suffix++;
            }

            DB::table('pricing_packages')->where('id', $package->id)->update(['slug' => $slug]);
        });

        Schema::table('pricing_packages', function (Blueprint $table): void {
            $table->unique('slug');
        });

        Schema::create('pricing_package_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pricing_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['pricing_package_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_package_products');
        Schema::table('pricing_packages', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'story_count', 'show_in_store', 'show_on_homepage']);
        });
    }
};
