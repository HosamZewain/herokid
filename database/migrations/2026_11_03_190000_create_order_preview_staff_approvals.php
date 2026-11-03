<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_preview_staff_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->unique()->constrained('order_review_manifests');
            $table->foreignId('user_id')->constrained('users');
            $table->string('fingerprint', 64);
            $table->string('channel', 32);
            $table->text('note');
            $table->text('production_inputs');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Customer approvals are audit evidence, retained across code rollback.
    }
};
