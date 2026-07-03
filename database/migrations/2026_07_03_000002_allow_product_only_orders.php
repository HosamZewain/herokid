<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('story_id')->nullable()->change();
            $table->string('child_name')->nullable()->change();
            $table->integer('child_age')->nullable()->change();
            $table->string('child_gender')->nullable()->change();
            $table->string('language')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('story_id')->nullable(false)->change();
            $table->string('child_name')->nullable(false)->change();
            $table->integer('child_age')->nullable(false)->change();
            $table->string('child_gender')->nullable(false)->change();
            $table->string('language')->default('ar')->nullable(false)->change();
        });
    }
};
