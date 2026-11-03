<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore(['key' => 'book_approved_enrollment_after', 'value' => now()->toDateTimeString(), 'created_at' => now(), 'updated_at' => now()]);
        Schema::table('order_group_assignments', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('order_automation_controls', function (Blueprint $table) {
            $table->string('checkout_key')->primary();
            $table->string('state')->default('running')->index();
            $table->unsignedInteger('assignment_version')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_automation_controls');
        Schema::table('order_group_assignments', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
