<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->select('id', 'delivery_details')
            ->orderBy('id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $details = $this->decodeDetails($order->delivery_details);

                    if ($details === []) {
                        continue;
                    }

                    $details['country'] ??= 'Egypt';
                    $details['street'] ??= '';
                    $details['address_details'] ??= $details['address'] ?? '';

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['delivery_details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('orders')
            ->select('id', 'delivery_details')
            ->orderBy('id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $details = $this->decodeDetails($order->delivery_details);

                    unset($details['country'], $details['street'], $details['address_details']);

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['delivery_details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            });
    }

    private function decodeDetails(mixed $deliveryDetails): array
    {
        if (is_array($deliveryDetails)) {
            return $deliveryDetails;
        }

        if (! is_string($deliveryDetails) || $deliveryDetails === '') {
            return [];
        }

        $decoded = json_decode($deliveryDetails, true);

        return is_array($decoded) ? $decoded : [];
    }
};
