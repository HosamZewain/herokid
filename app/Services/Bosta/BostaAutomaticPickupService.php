<?php

namespace App\Services\Bosta;

use App\Models\BostaShipment;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderStatusRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class BostaAutomaticPickupService
{
    public const SETTING_KEY = 'bosta_automatic_pickup';

    public function settings(): array
    {
        $saved = json_decode((string) Setting::where('key', self::SETTING_KEY)->value('value'), true);

        return array_replace(['enabled' => false, 'contact_name' => '', 'contact_phone' => '', 'authorized_by' => null], is_array($saved) ? $saved : []);
    }

    public function run(): string
    {
        $settings = $this->settings();
        $now = CarbonImmutable::now('Africa/Cairo');
        if (! $settings['enabled'] || ! config('bosta.enabled') || $now->isFriday() || $now->format('H:i') !== '14:30') {
            return 'disabled_or_outside_schedule';
        }
        $admin = User::find($settings['authorized_by']);
        if (! $admin?->is_active || ! Gate::forUser($admin)->allows('bosta.create_pickup')) {
            return $this->result('authorization_missing');
        }
        $created = array_unique(['shipment_created', ...OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'shipment_created', false)]);
        $cancelled = array_unique(['cancelled', ...OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_ORDER, 'cancelled', false)]);
        $ids = BostaShipment::query()->awaitingPickup()->whereNotNull('tracking_number')
            ->where('business_location_id', config('bosta.business_location_id'))
            ->whereHas('order', fn ($q) => $q->whereIn('shipping_status', $created))
            ->whereNotExists(function ($q) use ($created, $cancelled): void {
                $q->selectRaw('1')->from('orders')->whereColumn('orders.checkout_group_key', 'bosta_shipments.checkout_group_key')
                    ->whereNull('orders.deleted_at')->where(fn ($q) => $q->whereNotIn('shipping_status', $created)->orWhereNull('shipping_status')->orWhereIn('status', $cancelled));
            })->pluck('id')->all();
        if ($ids === []) {
            return $this->result('no_eligible_shipments');
        }
        try {
            app(BostaPickupService::class)->create($ids, [
                'scheduled_date' => $now->toDateString(), 'contact_name' => $settings['contact_name'],
                'contact_phone' => $settings['contact_phone'], 'notes' => 'HeroKid scheduled pickup',
            ], $admin, null, 'daily:'.$now->toDateString().':'.config('bosta.business_location_id'));

            return $this->result('created');
        } catch (ValidationException $exception) {
            return $this->result('review_required', mb_substr(implode(' ', Arr::flatten($exception->errors())), 0, 500));
        } catch (Throwable) {
            return $this->result('review_required', 'تعذر التحقق من Bosta. راجع سجل الاستلام قبل أي إعادة محاولة.');
        }
    }

    private function result(string $result, ?string $message = null): string
    {
        Setting::updateOrCreate(['key' => self::SETTING_KEY.'_last_result'], ['value' => json_encode([
            'status' => $result, 'at' => now()->toIso8601String(), 'message' => $message,
        ])]);

        return $result;
    }
}
