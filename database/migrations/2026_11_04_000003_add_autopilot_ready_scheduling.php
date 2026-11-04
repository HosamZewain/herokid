<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_checkout_states', function (Blueprint $table) {
            $table->unsignedInteger('ready_target_count')->default(0);
            $table->timestamp('ready_since')->nullable();
        });
        // Rebuild only observer metadata through the existing bounded sweep. No AI
        // calls, execution, or resetting previously covered/error states here.
        DB::table('ai_checkout_states')->whereNotIn('status', ['inactive', 'redirected'])
            ->whereNull('dirty_at')->update(['dirty_at' => now()]);
    }

    public function down(): void
    {
        // Forward-only; preserve scheduling/audit evidence on an application rollback.
    }
};
