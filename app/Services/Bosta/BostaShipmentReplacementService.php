<?php

namespace App\Services\Bosta;

use App\Models\BostaShipment;
use App\Models\Order;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BostaShipmentReplacementService
{
    public function __construct(private BostaClient $client, private BostaShippingStatusService $statuses) {}

    public function release(BostaShipment $shipment, string $action, string $reason, User $admin, Request $request): void
    {
        $lock = Cache::lock('bosta:pickup-create:'.$shipment->business_location_id, 300);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['order' => 'توجد عملية جارية لهذه الشحنة.']);
        }
        try {
            $shipment->refresh();
            if ($shipment->superseded_at || ! $shipment->tracking_number) {
                throw ValidationException::withMessages(['order' => 'هذه الشحنة ليست البوليصة الحالية.']);
            }
            $response = $this->client->delivery($shipment->tracking_number);
            $data = data_get($response, 'data', []);
            if (($response['success'] ?? true) !== true || (string) ($data['_id'] ?? '') !== $shipment->bosta_delivery_id) {
                throw ValidationException::withMessages(['order' => 'تعذر التحقق من هوية الشحنة لدى Bosta.']);
            }
            $state = strtolower(trim((string) data_get($data, 'state.value')));
            $code = data_get($data, 'state.code');
            $cancelled = in_array($state, ['terminated', 'canceled', 'cancelled'], true);
            $terminal = $cancelled || in_array($state, ['returned to business', 'lost', 'damaged'], true);
            if ($action === 'cancel') {
                if (! $cancelled) {
                    if ($shipment->cancellation_status || ! in_array((int) $code, [10, 11], true)) {
                        throw ValidationException::withMessages(['order' => 'لا يمكن تأكيد الإلغاء الآن. راجع Bosta؛ لن تُنشأ بوليصة بديلة لشحنة قد تكون قيد النقل.']);
                    }
                    // Persist before the external mutation. A timeout must block replacement.
                    $shipment->update(['cancellation_status' => 'pending']);
                    try {
                        $result = $this->client->terminateDelivery($shipment->tracking_number);
                        if (($result['success'] ?? false) !== true) {
                            throw new \RuntimeException('Cancellation not confirmed.');
                        }
                        $cancelled = true;
                    } catch (Throwable $exception) {
                        $shipment->update(['cancellation_status' => 'uncertain']);
                        throw ValidationException::withMessages(['order' => 'نتيجة الإلغاء غير مؤكدة. تحقّق من Bosta ثم اضغط التحقق مجددًا؛ لا تنشئ بوليصة أخرى.']);
                    }
                }
            } elseif (! $terminal) {
                throw ValidationException::withMessages(['order' => 'إعادة الشحن متاحة بعد تأكيد Bosta: مرتجع للمخزن، مفقود، تالف أو ملغى.']);
            }

            DB::transaction(function () use ($shipment, $reason, $cancelled, $state, $code, $admin, $request): void {
                Order::query()->where('checkout_group_key', $shipment->checkout_group_key)->lockForUpdate()->get();
                $shipment = BostaShipment::query()->lockForUpdate()->findOrFail($shipment->id);
                if ($shipment->superseded_at) {
                    return;
                }
                $shipment->update([
                    'superseded_at' => now(), 'replacement_reason' => $reason,
                    'cancellation_status' => $cancelled ? 'confirmed' : null,
                    'shipping_status' => $cancelled ? 'cancelled' : ($state === 'returned to business' ? 'returned' : $state),
                    'state_code' => $code ?? $shipment->state_code,
                ]);
                $this->statuses->updateCheckout($shipment->checkout_group_key, 'ready', 'تجهيز إعادة شحن بعد التحقق من البوليصة السابقة: '.$reason);
                AdminActivityLogger::log('bosta.shipment.released', 'تم حفظ البوليصة السابقة وإتاحة شحنة بديلة.', $shipment, [
                    'reason' => $reason, 'previous_tracking_number' => $shipment->tracking_number,
                ], $admin, $request);
            });
        } finally {
            $lock->release();
        }
    }
}
