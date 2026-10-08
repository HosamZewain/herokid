<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_attachments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('validity_days')->nullable()->default(null)->change();
            $table->timestamp('expires_at')->nullable()->change();
        });

        // Preserve every existing file, ID, ownership link and timestamp. Cancel only legacy expiry metadata.
        DB::table('order_attachments')->where(function ($query): void {
            $query->whereNotNull('expires_at')->orWhereNotNull('validity_days');
        })->update(['validity_days' => null, 'expires_at' => null]);
    }

    public function down(): void
    {
        // Intentionally irreversible: rollback must not reintroduce deadlines that could delete retained media.
        // Nullable columns remain compatible with older upload code that explicitly supplies a deadline.
    }
};
