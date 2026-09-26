<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MobilePromoCode;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DiscountCodeController extends Controller
{
    public function index(): View
    {
        $codes = MobilePromoCode::query()
            ->withCount('websiteRedemptions')
            ->latest()
            ->paginate(25);

        return view('admin.discount-codes.index', compact('codes'));
    }

    public function edit(MobilePromoCode $discountCode): View
    {
        return view('admin.discount-codes.edit', compact('discountCode'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $code = MobilePromoCode::query()->create($this->normalize($data));

        AdminActivityLogger::log(
            'discount_code.created',
            'إنشاء كود خصم جديد.',
            $code,
            ['configuration' => $code->only($this->auditedFields())],
            request: $request,
        );

        return redirect()->route('admin.discount-codes.index')->with('success', 'تم إنشاء كود الخصم بنجاح.');
    }

    public function update(Request $request, MobilePromoCode $discountCode): RedirectResponse
    {
        $data = $this->validated($request, $discountCode);
        $before = $discountCode->only($this->auditedFields());
        $discountCode->update($this->normalize($data));

        AdminActivityLogger::log(
            'discount_code.updated',
            'تعديل إعدادات كود الخصم.',
            $discountCode,
            ['changes' => AdminActivityLogger::changedValues($before, $discountCode->only($this->auditedFields()))],
            request: $request,
        );

        return redirect()->route('admin.discount-codes.index')->with('success', 'تم حفظ تعديلات كود الخصم.');
    }

    public function toggle(Request $request, MobilePromoCode $discountCode): RedirectResponse
    {
        $before = $discountCode->is_active;
        $discountCode->update(['is_active' => ! $before]);

        AdminActivityLogger::log(
            'discount_code.status_updated',
            $discountCode->is_active ? 'تفعيل كود الخصم.' : 'إيقاف كود الخصم.',
            $discountCode,
            ['old' => $before, 'new' => $discountCode->is_active],
            request: $request,
        );

        return back()->with('success', $discountCode->is_active ? 'تم تفعيل الكود.' : 'تم إيقاف الكود.');
    }

    private function validated(Request $request, ?MobilePromoCode $code = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash:ascii', 'max:40', Rule::unique('mobile_promo_codes', 'code')->ignore($code)],
            'name' => ['nullable', 'string', 'max:120'],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'minimum_subtotal' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'maximum_discount' => ['nullable', 'numeric', 'min:0.01', 'max:10000000'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'website_enabled' => ['nullable', 'boolean'],
            'mobile_enabled' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'code.required' => 'اكتب كود الخصم.',
            'code.alpha_dash' => 'الكود يقبل حروفًا إنجليزية وأرقامًا وشرطة فقط.',
            'code.unique' => 'هذا الكود مستخدم بالفعل.',
            'discount_value.required' => 'اكتب قيمة الخصم.',
            'ends_at.after' => 'تاريخ الانتهاء يجب أن يكون بعد تاريخ البداية.',
        ]);

        if ($data['discount_type'] === 'percent' && (float) $data['discount_value'] > 100) {
            throw ValidationException::withMessages(['discount_value' => 'نسبة الخصم لا يمكن أن تتجاوز 100%.']);
        }

        if (! $request->boolean('website_enabled') && ! $request->boolean('mobile_enabled')) {
            throw ValidationException::withMessages(['website_enabled' => 'اختر الموقع أو تطبيق الهاتف على الأقل.']);
        }

        return $data;
    }

    private function normalize(array $data): array
    {
        return [
            'code' => mb_strtoupper(trim($data['code'])),
            'name' => filled($data['name'] ?? null) ? trim($data['name']) : null,
            'discount_type' => $data['discount_type'],
            'discount_value' => (int) round(((float) $data['discount_value']) * 100),
            'minimum_subtotal_cents' => (int) round(((float) ($data['minimum_subtotal'] ?? 0)) * 100),
            'maximum_discount_cents' => filled($data['maximum_discount'] ?? null)
                ? (int) round(((float) $data['maximum_discount']) * 100)
                : null,
            'usage_limit' => filled($data['usage_limit'] ?? null) ? (int) $data['usage_limit'] : null,
            'per_user_limit' => filled($data['per_user_limit'] ?? null) ? (int) $data['per_user_limit'] : null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'website_enabled' => (bool) ($data['website_enabled'] ?? false),
            'mobile_enabled' => (bool) ($data['mobile_enabled'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    /** @return array<int, string> */
    private function auditedFields(): array
    {
        return [
            'code', 'name', 'discount_type', 'discount_value', 'minimum_subtotal_cents',
            'maximum_discount_cents', 'usage_limit', 'per_user_limit', 'is_active',
            'website_enabled', 'mobile_enabled', 'starts_at', 'ends_at',
        ];
    }
}
