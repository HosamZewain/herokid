<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep every consent revision; run/control versions serialize submissions.
        Schema::table('order_preview_staff_approvals', function (Blueprint $table) {
            $table->index('manifest_id', 'staff_preview_manifest_history');
        });
        Schema::table('order_preview_staff_approvals', function (Blueprint $table) {
            $table->dropUnique('order_preview_staff_approvals_manifest_id_unique');
        });
    }

    public function down(): void
    {
        // Retain all attestations; do not collapse customer approval history.
    }
};
