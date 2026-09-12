<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_production_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('stable_key', 80);
            $table->string('name', 255);
            $table->longText('prompt_template');
            $table->unsignedInteger('quantity_per_item')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'stable_key'], 'product_production_components_product_key_unique');
            $table->index(['product_id', 'is_active', 'sort_order'], 'product_production_components_active_sort_idx');
        });

        Schema::create('order_item_production_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_production_component_id')->nullable();
            $table->string('stable_key', 80);
            $table->string('name', 255);
            $table->longText('prompt_template');
            $table->unsignedInteger('quantity_per_item')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['order_item_id', 'stable_key'], 'order_item_production_components_item_key_unique');
            $table->index(['order_item_id', 'sort_order'], 'order_item_production_components_sort_idx');
            $table->foreign('product_production_component_id', 'order_item_prod_components_source_fk')
                ->references('id')
                ->on('product_production_components')
                ->nullOnDelete();
        });

        $now = now();
        DB::table('products')
            ->whereNotNull('production_prompt_template')
            ->where('production_prompt_template', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($now): void {
                DB::table('product_production_components')->insert(
                    $products->map(fn ($product): array => [
                        'product_id' => $product->id,
                        'stable_key' => 'main',
                        'name' => $product->name_ar ?: $product->name_en ?: 'المنتج الرئيسي',
                        'prompt_template' => $product->production_prompt_template,
                        'quantity_per_item' => 1,
                        'sort_order' => 0,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                );
            });

        DB::table('order_items')
            ->whereNotNull('product_id')
            ->whereIn('item_type', ['product', 'product_add_on'])
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($now): void {
                $componentsByProduct = DB::table('product_production_components')
                    ->whereIn('product_id', $items->pluck('product_id')->filter()->unique())
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->groupBy('product_id');
                $snapshots = [];

                foreach ($items as $item) {
                    foreach ($componentsByProduct->get($item->product_id, collect()) as $component) {
                        $snapshots[] = [
                            'order_item_id' => $item->id,
                            'product_production_component_id' => $component->id,
                            'stable_key' => $component->stable_key,
                            'name' => $component->name,
                            'prompt_template' => $component->prompt_template,
                            'quantity_per_item' => $component->quantity_per_item,
                            'sort_order' => $component->sort_order,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($snapshots !== []) {
                    DB::table('order_item_production_components')->insert($snapshots);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_production_components');
        Schema::dropIfExists('product_production_components');
    }
};
