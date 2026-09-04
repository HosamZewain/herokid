<?php

namespace App\Services\Bosta;

use App\Models\BostaShipment;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BostaShipmentService
{
    public function __construct(
        private BostaClient $client,
        private BostaAddressResolver $addresses,
        private AdminOrderGroupService $groups,
        private BostaShipmentEligibilityService $eligibility,
        private BostaShippingStatusService $shippingStatuses,
        private BostaShipmentDescriptionBuilder $descriptions,
    ) {}

    /** @param array<string, mixed> $overrides */
    public function create(Order $representative, User $admin, Request $request, array $overrides = []): BostaShipment
    {
        [$shipment, $payload] = DB::transaction(function () use ($representative, $admin, $overrides): array {
            $orders = Order::query()->where('checkout_group_key', $representative->checkoutGroupKey())
                ->lockForUpdate()->with(['items', 'story', 'checkoutReference'])->get();
            if ($orders->isEmpty()) {
                throw ValidationException::withMessages(['order' => 'عملية الشراء غير متاحة للشحن.']);
            }

            $group = $this->groups->present($orders);

            $this->eligibility->ensureEligible($orders);

            $existing = BostaShipment::query()->where('checkout_group_key', $group['key'])->lockForUpdate()->first();
            if ($existing?->bosta_delivery_id) {
                throw ValidationException::withMessages(['order' => 'تم إنشاء شحنة Bosta لهذه العملية بالفعل.']);
            }
            if ($existing?->creation_status === 'pending' && $existing->updated_at?->isAfter(now()->subMinutes(10))) {
                throw ValidationException::withMessages(['order' => 'إنشاء شحنة Bosta لهذه العملية جارٍ بالفعل. انتظر قليلًا قبل إعادة المحاولة.']);
            }

            $delivery = array_replace($group['delivery'], array_filter([
                'bosta_city_id' => $overrides['bosta_city_id'] ?? null,
                'bosta_district_id' => $overrides['bosta_district_id'] ?? null,
                'governorate' => $overrides['governorate'] ?? null,
                'city' => $overrides['district_name'] ?? null,
                'street' => $overrides['first_line'] ?? null,
                'address_details' => $overrides['second_line'] ?? null,
            ], fn (mixed $value): bool => filled($value)));
            $phone = $this->egyptianPhone($overrides['receiver_phone'] ?? $group['phone']);
            $receiverName = trim((string) ($overrides['receiver_name'] ?? $group['customer_name']));
            if (! $phone) {
                throw ValidationException::withMessages(['order' => 'بيانات الهاتف أو عنوان التوصيل غير مكتملة.']);
            }
            if ($receiverName === '') {
                throw ValidationException::withMessages(['order' => 'اسم مستلم الشحنة مطلوب.']);
            }
            $dropOffAddress = $this->addresses->resolve($delivery);
            $codAmountCents = array_key_exists('cod_amount', $overrides) && $overrides['cod_amount'] !== null
                ? (int) round(((float) $overrides['cod_amount']) * 100)
                : $group['remaining_amount_cents'];

            if (blank(config('bosta.business_location_id'))) {
                throw ValidationException::withMessages(['order' => 'معرّف مكان الاستلام في Bosta غير مضبوط.']);
            }

            $shipment = $existing ?: new BostaShipment;
            $shipment->forceFill([
                'checkout_group_key' => $group['key'],
                'order_id' => $group['representative_id'],
                'business_reference' => $group['short_reference'] ?: $orders->first()->order_number,
                'created_by_user_id' => $admin->id,
                'cod_amount_cents' => $codAmountCents,
                'package_type' => config('bosta.default_package_type'),
                'allow_open_package' => (bool) config('bosta.allow_open_package'),
                'business_location_id' => (string) config('bosta.business_location_id'),
                'creation_status' => 'pending',
                'last_error' => null,
            ])->save();

            $payload = [
                'type' => 10,
                'businessReference' => $shipment->business_reference,
                'businessLocationId' => $shipment->business_location_id,
                'cod' => $shipment->cod_amount_cents / 100,
                'allowToOpenPackage' => $shipment->allow_open_package,
                'receiver' => ['fullName' => $receiverName, 'phone' => $phone],
                'dropOffAddress' => $dropOffAddress,
                'specs' => [
                    'packageType' => $shipment->package_type,
                    'packageDetails' => [
                        'itemsCount' => max(1, $group['story_count'] + $group['product_quantity'] + $group['add_on_quantity']),
                        'description' => $this->descriptions->build($group, $receiverName, $phone),
                    ],
                ],
            ];

            return [$shipment, $payload];
        });

        try {
            $response = $this->client->createDelivery($payload);
            $data = data_get($response, 'data', $response);
            $shipment->forceFill([
                'bosta_delivery_id' => data_get($data, '_id') ?: data_get($data, 'id'),
                'tracking_number' => data_get($data, 'trackingNumber'),
                'provider_response' => $this->safeProviderData($data),
                'creation_status' => 'created',
                'last_error' => null,
            ])->save();

            if (blank($shipment->bosta_delivery_id)) {
                throw new \RuntimeException('Bosta delivery response did not include an identifier.');
            }
        } catch (Throwable $exception) {
            $shipment->forceFill([
                'creation_status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            AdminActivityLogger::log('bosta.shipment.failed', 'تعذر إنشاء شحنة Bosta للعملية '.$shipment->business_reference, $representative, [
                'shipment_id' => $shipment->id,
                'cod_amount_cents' => $shipment->cod_amount_cents,
                'cod_is_financial_collection' => false,
            ], $admin, $request);

            throw $exception;
        }

        AdminActivityLogger::log('bosta.shipment.created', 'تم إنشاء شحنة Bosta للعملية '.$shipment->business_reference, $representative, [
            'shipment_id' => $shipment->id,
            'tracking_number' => $shipment->tracking_number,
            'cod_amount_cents' => $shipment->cod_amount_cents,
            'cod_is_financial_collection' => false,
        ], $admin, $request);

        $this->shippingStatuses->updateCheckout(
            $shipment->checkout_group_key,
            'shipment_created',
            'تم إنشاء شحنة Bosta بنجاح. رقم التتبع: '.($shipment->tracking_number ?: 'قيد التجهيز'),
        );

        return $shipment->refresh();
    }

    private function safeProviderData(array $data): array
    {
        return collect($data)->only(['_id', 'id', 'trackingNumber', 'state', 'type', 'businessReference'])->all();
    }

    private function egyptianPhone(mixed $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?: '';

        if (str_starts_with($digits, '0020')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '20')) {
            $digits = substr($digits, 2);
        }

        $digits = ltrim($digits, '0');
        $local = '0'.$digits;

        return preg_match('/^01[0125]\d{8}$/', $local) === 1 ? $local : null;
    }
}
