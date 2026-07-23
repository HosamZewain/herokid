<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('child_identity_requests', function (Blueprint $table): void {
            $table->unsignedTinyInteger('child_age')->nullable()->change();
            $table->longText('prompt_override')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        DB::table('child_identity_requests')->whereNull('child_age')->update(['child_age' => 1]);

        Schema::table('child_identity_requests', function (Blueprint $table): void {
            $table->dropColumn('prompt_override');
            $table->unsignedTinyInteger('child_age')->nullable(false)->change();
        });
    }
};
