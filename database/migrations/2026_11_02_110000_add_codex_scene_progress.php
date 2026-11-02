<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Support\OrderStatusRegistry;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('codex_scene_runs', fn (Blueprint $table) => $table->json('scene_progress')->nullable());
        DB::table('order_status_definitions')->insertOrIgnore([
            'type' => 'order', 'key' => 'pending_team_review', 'label_ar' => 'بانتظار مراجعة الفريق',
            'color' => 'orange', 'behavior' => 'standard', 'sort_order' => 45,
            'is_active' => true, 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        OrderStatusRegistry::clearCache();
    }

    public function down(): void
    {
        // Preserve referenced status and attempt evidence on code rollback.
    }
};
