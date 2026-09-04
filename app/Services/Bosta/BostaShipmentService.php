<?php

namespace App\Services\Bosta;

use App\Models\BostaShipment;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Support\AdminActivityLogger;
use App\Support\OrderStatusRegistry;
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
    ) {}

    public function create(Order $representative, User $admin, Request $request): BostaShipment
    {
        [$shipment, $payload] = DB::transaction(function () use ($representative, $admin): array {
            $orders = Order::query()->where('checkout_group_key', $representative->checkoutGroupKey())
                ->lockForUpdate()->with(['items', 'story', 'checkoutReference'])->get();
            if ($orders->isEmpty()) {
                throw ValidationException::withMessages(['order' => 'عملية الشراء غير متاحة للشحن.']);
            }

            $group = $this->groups->present($orders);

            $cancelledStatuses = collect(OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_ORDER, 'cancelled'))
                ->push('cancelled')
                ->unique()
                ->values()
                ->all();
            $blockedShippingStatuses = collect(['delivered', 'cancelled', 'returned', 'not_required'])
                ->flatMap(fn (string $behavior): array => OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, $behavior))
                ->merge(['delivered', 'cancelled', 'returned', 'not_required'])
                ->unique();
            if ($orders->contains(fn (Order $order): bool => in_array($order->status, $cancelledStatuses, true))
                || $orders->contains(fn (Order $order): bool => $blockedShippingStatuses->contains($order->shipping_status))) {
                throw ValidationException::withMessages(['order' => 'عملية الشراء ملغاة أو لا تحتاج إلى شحن عبر Bosta.']);
            }

            $existing = BostaShipment::query()->where('checkout_group_key', $group['key'])->lockForUpdate()->first();
            if ($existing?->bosta_delivery_id) {
                throw ValidationException::withMessages(['order' => 'تم إنشاء شحنة Bosta لهذه العملية بالفعل.']);
            }
            if ($existing?->creation_status === 'pending' && $existing->updated_at?->isAfter(now()->subMinutes(10))) {
                throw ValidationException::withMessages(['order' => 'إنشاء شحنة Bosta لهذه العملية جارٍ بالفعل. انتظر قليلًا قبل إعادة المحاولة.']);
            }

            $delivery = $group['delivery'];
            $phone = $this->egyptianPhone($group['phone']);
            if (! $phone) {
                throw ValidationException::withMessages(['order' => 'بيانات الهاتف أو عنوان التوصيل غير مكتملة.']);
            }
            $dropOffAddress = $this->addresses->resolve($delivery);

            if (blank(config('bosta.business_location_id'))) {
                throw ValidationException::withMessages(['order' => 'معرّف مكان الاستلام في Bosta غير مضبوط.']);
            }

            $shipment = $existing ?: new BostaShipment;
            $shipment->forceFill([
                'checkout_group_key' => $group['key'],
                'order_id' => $group['representative_id'],
                'business_reference' => $group['short_reference'] ?: $orders->first()->order_number,
                'created_by_user_id' => $admin->id,
                'cod_amount_cents' => $group['remaining_amount_cents'],
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
                'receiver' => ['fullName' => $group['customer_name'], 'phone' => $phone],
                'dropOffAddress' => $dropOffAddress,
                'specs' => [
                    'packageType' => $shipment->package_type,
                    'packageDetails' => [
                        'itemsCount' => max(1, $group['story_count'] + $group['product_quantity'] + $group['add_on_quantity']),
                        'description' => implode('، ', array_filter([...$group['story_titles'], ...$group['product_titles'], ...$group['add_on_titles']])),
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
