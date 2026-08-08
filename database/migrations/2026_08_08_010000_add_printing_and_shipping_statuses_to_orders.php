<?php

use App\Support\OrderWorkflowStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('printing_status', 32)->default(OrderWorkflowStatus::PRINTING_NOT_STARTED)->after('status')->index();
            $table->string('shipping_status', 32)->default(OrderWorkflowStatus::SHIPPING_NOT_READY)->after('printing_status')->index();
            $table->foreignId('workflow_status_updated_by_user_id')->nullable()->after('shipping_status')->constrained('users')->nullOnDelete();
            $table->timestamp('workflow_status_updated_at')->nullable()->after('workflow_status_updated_by_user_id');
        });

        Schema::table('order_status_logs', function (Blueprint $table): void {
            $table->string('status_type', 20)->default('order')->after('order_id')->index();
        });

        DB::table('orders')->orderBy('id')->chunkById(250, function ($orders): void {
            foreach ($orders as $order) {
                $printingStatus = match ($order->status) {
                    'approved_for_print' => OrderWorkflowStatus::PRINTING_READY,
                    'printing' => OrderWorkflowStatus::PRINTING_IN_PROGRESS,
                    'shipped', 'delivered' => OrderWorkflowStatus::PRINTING_COMPLETED,
                    'cancelled' => OrderWorkflowStatus::PRINTING_ON_HOLD,
                    default => OrderWorkflowStatus::PRINTING_NOT_STARTED,
                };
                $shippingStatus = match ($order->status) {
                    'shipped' => OrderWorkflowStatus::SHIPPING_SHIPPED,
                    'delivered' => OrderWorkflowStatus::SHIPPING_DELIVERED,
                    'cancelled' => OrderWorkflowStatus::SHIPPING_CANCELLED,
                    default => OrderWorkflowStatus::SHIPPING_NOT_READY,
                };

                DB::table('orders')->where('id', $order->id)->update([
                    'printing_status' => $printingStatus,
                    'shipping_status' => $shippingStatus,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_status_logs', function (Blueprint $table): void {
            $table->dropIndex(['status_type']);
            $table->dropColumn('status_type');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['workflow_status_updated_by_user_id']);
            $table->dropIndex(['printing_status']);
            $table->dropIndex(['shipping_status']);
            $table->dropColumn([
                'printing_status',
                'shipping_status',
                'workflow_status_updated_by_user_id',
                'workflow_status_updated_at',
            ]);
        });
    }
};
