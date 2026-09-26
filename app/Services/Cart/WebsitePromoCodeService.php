<?php

namespace App\Services\Cart;

use App\Models\MobilePromoCode;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebsitePromoCodeService
{
    private const SESSION_KEY = 'cart.promo_code_id';

    /** @return array{promo: MobilePromoCode|null, discount_cents: int} */
    public function quote(Request $request, int $subtotalCents): array
    {
        $promoId = $request->session()->get(self::SESSION_KEY);
        if (! $promoId) {
            return ['promo' => null, 'discount_cents' => 0];
        }

        $promo = MobilePromoCode::query()->find($promoId);

        try {
            $this->assertAvailable($promo, $subtotalCents);
        } catch (ValidationException) {
            $request->session()->forget(self::SESSION_KEY);

            return ['promo' => null, 'discount_cents' => 0];
        }

        return ['promo' => $promo, 'discount_cents' => $promo->discountFor($subtotalCents)];
    }

    /** @return array{promo: MobilePromoCode, discount_cents: int} */
    public function apply(Request $request, string $code, int $subtotalCents): array
    {
        $normalizedCode = mb_strtoupper(trim($code));
        $promo = MobilePromoCode::query()
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->first();

        $this->assertAvailable($promo, $subtotalCents);
        $request->session()->put(self::SESSION_KEY, $promo->id);

        return ['promo' => $promo, 'discount_cents' => $promo->discountFor($subtotalCents)];
    }

    public function remove(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /** @return array{promo: MobilePromoCode|null, discount_cents: int} */
    public function redeemForCheckout(
        Request $request,
        int $subtotalCents,
        string $customerPhone,
        string $checkoutGroupKey,
    ): array {
        $promoId = $request->session()->get(self::SESSION_KEY);
        if (! $promoId) {
            return ['promo' => null, 'discount_cents' => 0];
        }

        $existing = DB::table('website_promo_code_redemptions')
            ->where('checkout_group_key', $checkoutGroupKey)
            ->first();
        if ($existing) {
            return [
                'promo' => MobilePromoCode::query()->find($existing->mobile_promo_code_id),
                'discount_cents' => (int) $existing->discount_cents,
            ];
        }

        $promo = MobilePromoCode::query()->lockForUpdate()->find($promoId);
        $phoneHash = $this->phoneHash($customerPhone);
        $this->assertAvailable($promo, $subtotalCents, $phoneHash);
        $discountCents = $promo->discountFor($subtotalCents);

        DB::table('website_promo_code_redemptions')->insert([
            'mobile_promo_code_id' => $promo->id,
            'checkout_group_key' => $checkoutGroupKey,
            'customer_phone_hash' => $phoneHash,
            'discount_cents' => $discountCents,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $promo->increment('used_count');

        return ['promo' => $promo, 'discount_cents' => $discountCents];
    }

    private function assertAvailable(?MobilePromoCode $promo, int $subtotalCents, ?string $phoneHash = null): void
    {
        if (! $promo || ! $promo->isAvailableFor('website')) {
            throw ValidationException::withMessages([
                'promo_code' => 'كود الخصم غير صحيح أو غير متاح حاليًا.',
            ]);
        }

        if ($subtotalCents < $promo->minimum_subtotal_cents) {
            throw ValidationException::withMessages([
                'promo_code' => 'الحد الأدنى لاستخدام هذا الكود هو '.format_money($promo->minimum_subtotal_cents / 100).'.',
            ]);
        }

        if ($phoneHash && $promo->per_user_limit !== null) {
            $uses = DB::table('website_promo_code_redemptions')
                ->where('mobile_promo_code_id', $promo->id)
                ->where('customer_phone_hash', $phoneHash)
                ->count();

            if ($uses >= $promo->per_user_limit) {
                throw ValidationException::withMessages([
                    'promo_code' => 'تم الوصول إلى الحد المسموح لاستخدام هذا الكود لهذا العميل.',
                ]);
            }
        }
    }

    private function phoneHash(string $phone): string
    {
        $normalized = Phone::forWhatsApp($phone) ?: Phone::normalize($phone);

        return hash('sha256', (string) $normalized);
    }
}
