<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_countries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 3)->nullable()->unique();
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('delivery_governorates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_country_id')->constrained('delivery_countries')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('delivery_fee', 10, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['delivery_country_id', 'name']);
        });

        $existingDeliveryFee = (float) (DB::table('settings')->where('key', 'delivery_fee')->value('value') ?? 75);
        $deliveryFee = $existingDeliveryFee > 0 ? $existingDeliveryFee : 75;
        $now = now();

        $egyptId = DB::table('delivery_countries')->insertGetId([
            'name' => 'Egypt',
            'code' => 'EG',
            'delivery_fee' => $deliveryFee,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $governorates = [
            'القاهرة',
            'الجيزة',
            'القليوبية',
            'الإسكندرية',
            'البحيرة',
            'مطروح',
            'دمياط',
            'الدقهلية',
            'كفر الشيخ',
            'الغربية',
            'المنوفية',
            'الشرقية',
            'بورسعيد',
            'الإسماعيلية',
            'السويس',
            'شمال سيناء',
            'جنوب سيناء',
            'بني سويف',
            'الفيوم',
            'المنيا',
            'أسيوط',
            'سوهاج',
            'قنا',
            'الأقصر',
            'أسوان',
            'البحر الأحمر',
            'الوادي الجديد',
        ];

        DB::table('delivery_governorates')->insert(array_map(fn (string $name): array => [
            'delivery_country_id' => $egyptId,
            'name' => $name,
            'delivery_fee' => null,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $governorates));
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_governorates');
        Schema::dropIfExists('delivery_countries');
    }
};
