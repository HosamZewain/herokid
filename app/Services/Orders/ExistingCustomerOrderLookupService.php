<?php

namespace App\Services\Orders;

use App\Models\DeliveryCountry;
use App\Models\Order;
use App\Support\Phone;
use Illuminate\Support\Collection;

class ExistingCustomerOrderLookupService
{
    /**
     * Find the most recently used, distinct customer/delivery profiles for a phone number.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function search(string $phone, int $limit = 8): Collection
    {
        $canonicalPhone = Phone::forWhatsApp($phone);
        $phoneValues = Phone::equivalentValues($phone);

        if ($canonicalPhone === null || $phoneValues === []) {
            return collect();
        }

        $countries = DeliveryCountry::query()
            ->with(['activeGovernorates' => fn ($query) => $query->orderBy('name')])
            ->where('active', true)
            ->get();

        return Order::withTrashed()
            ->with([
                'checkoutReference:id,checkout_group_key,short_reference',
                'user:id,name',
            ])
            ->whereIn('delivery_details->phone', $phoneValues)
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->filter(fn (Order $order): bool => Phone::forWhatsApp(data_get($order->delivery_details, 'phone')) === $canonicalPhone)
            ->groupBy(fn (Order $order): string => $order->checkoutGroupKey())
            ->map(function (Collection $checkoutOrders) use ($countries): array {
                /** @var Order $order */
                $order = $checkoutOrders->sortByDesc('created_at')->first();
                $delivery = (array) ($order->delivery_details ?? []);
                $country = $countries->firstWhere('id', (int) data_get($delivery, 'delivery_country_id'))
                    ?? $countries->first(fn (DeliveryCountry $candidate): bool =>
                        trim((string) $candidate->name) === trim((string) data_get($delivery, 'country'))
                    );
                $governorate = $country?->activeGovernorates
                    ->firstWhere('id', (int) data_get($delivery, 'delivery_governorate_id'))
                    ?? $country?->activeGovernorates->first(fn ($candidate): bool =>
                        trim((string) $candidate->name) === trim((string) data_get($delivery, 'governorate'))
                    );

                return [
                    'order_id' => (int) $order->id,
                    'checkout_reference' => $order->checkoutReference?->short_reference ?: $order->checkoutGroupKey(),
                    'parent_name' => (string) ($order->parent_name ?: $order->user?->name ?: ''),
                    'phone' => (string) data_get($delivery, 'phone', ''),
                    'delivery_country_id' => $country?->id,
                    'delivery_country_name' => $country?->name ?: (string) data_get($delivery, 'country', ''),
                    'delivery_governorate_id' => $governorate?->id,
                    'delivery_governorate_name' => $governorate?->name ?: (string) data_get($delivery, 'governorate', ''),
                    'city' => (string) data_get($delivery, 'city', ''),
                    'street' => (string) data_get($delivery, 'street', ''),
                    'address_details' => (string) data_get($delivery, 'address_details', data_get($delivery, 'address', '')),
                    'created_at' => $order->created_at?->toIso8601String(),
                ];
            })
            ->sortByDesc('created_at')
            ->unique(fn (array $profile): string => implode('|', [
                Phone::forWhatsApp($profile['phone']) ?? $profile['phone'],
                mb_strtolower(trim($profile['parent_name'])),
                $profile['delivery_country_id'],
                $profile['delivery_governorate_id'],
                mb_strtolower(trim($profile['city'])),
                mb_strtolower(trim($profile['street'])),
                mb_strtolower(trim($profile['address_details'])),
            ]))
            ->take(max(1, min($limit, 20)))
            ->values();
    }
}
