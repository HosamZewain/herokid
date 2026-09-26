<?php

namespace App\Services\Customers;

use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerCsvExportService
{
    /**
     * Return one row per phone number, using the latest order as the source.
     *
     * Deleted orders are intentionally included: placing an order is the
     * historical fact that qualifies a customer for this export.
     *
     * @return Collection<int, array{name: string, phone: string}>
     */
    public function rows(): Collection
    {
        $rows = collect();
        $seenPhones = [];

        $orders = DB::table('orders')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->select([
                'orders.id',
                'orders.parent_name',
                'orders.delivery_details',
                'users.name as user_name',
                'users.phone as user_phone',
            ])
            ->orderByDesc('orders.id')
            ->cursor();

        foreach ($orders as $order) {
            $delivery = $this->deliveryDetails($order->delivery_details);
            $phone = $this->exportPhone(data_get($delivery, 'phone') ?: $order->user_phone);

            if ($phone === null) {
                continue;
            }

            $identity = $this->phoneIdentity($phone);
            if (isset($seenPhones[$identity])) {
                continue;
            }

            $seenPhones[$identity] = true;
            $rows->push([
                'name' => trim((string) ($order->parent_name ?: $order->user_name)),
                'phone' => $phone,
            ]);
        }

        return $rows;
    }

    private function deliveryDetails(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function exportPhone(mixed $value): ?string
    {
        $normalized = Phone::normalize(is_scalar($value) ? (string) $value : null);
        $digits = preg_replace('/\D/', '', (string) $normalized) ?: '';

        return $digits !== '' ? $digits : null;
    }

    private function phoneIdentity(string $phone): string
    {
        if (strlen($phone) === 11 && str_starts_with($phone, '0')) {
            return '20'.substr($phone, 1);
        }

        return $phone;
    }
}
