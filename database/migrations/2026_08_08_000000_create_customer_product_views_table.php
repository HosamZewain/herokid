<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_product_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->nullable()->index();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('viewed_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'viewed_at']);
            $table->index(['product_id', 'viewed_at']);
        });

        DB::table('settings')->updateOrInsert(
            ['key' => 'unified_store_default_sort'],
            ['value' => 'best_selling', 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::forget('site_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_product_views');

        DB::table('settings')
            ->where('key', 'unified_store_default_sort')
            ->whereIn('value', ['best_selling', 'most_viewed'])
            ->update(['value' => 'featured', 'updated_at' => now()]);
        Cache::forget('site_settings');
    }
};
