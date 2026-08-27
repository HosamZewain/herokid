<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'production_prompt_template')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->longText('production_prompt_template')->nullable()->after('personalization_fields');
            });
        }

        $promptPath = resource_path('prompts/school-sticker-production.md');
        $template = is_file($promptPath) ? file_get_contents($promptPath) : false;

        if (is_string($template) && trim($template) !== '') {
            DB::table('products')
                ->where('slug', 'school-sticker')
                ->whereNull('production_prompt_template')
                ->update(['production_prompt_template' => $template]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'production_prompt_template')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('production_prompt_template');
            });
        }
    }
};
