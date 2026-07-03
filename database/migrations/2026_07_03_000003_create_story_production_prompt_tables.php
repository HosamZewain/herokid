<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->longText('value')->nullable()->change();
            $table->foreignId('updated_by')->nullable()->after('value')->constrained('users')->nullOnDelete();
        });

        Schema::create('order_production_prompt_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('prompt_text');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('order_production_prompt_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->longText('prompt_text');
            $table->timestamp('template_updated_at')->nullable();
            $table->string('snapshot_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_production_prompt_snapshots');
        Schema::dropIfExists('order_production_prompt_overrides');

        Schema::table('settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->text('value')->nullable()->change();
        });
    }
};
