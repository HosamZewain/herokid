<?php

use App\Services\Pricing\DefaultPackageInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_packages', function (Blueprint $table): void {
            $table->decimal('regular_price', 10, 2)->nullable()->after('price');
            $table->string('image_path')->nullable()->after('description');
        });

        app(DefaultPackageInstaller::class)->install();
    }

    public function down(): void
    {
        Schema::table('pricing_packages', function (Blueprint $table): void {
            $table->dropColumn(['regular_price', 'image_path']);
        });
    }
};
