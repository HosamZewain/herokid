<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\AdminOrderQuickEditService;
use App\Support\Phone;
use App\Support\StoryAgeOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderQuickEditController extends Controller
{
    public function removalOptions(Order $representative, AdminOrderQuickEditService $edits)
    {
        return response()->json($edits->removalOptions($representative))->header('Cache-Control', 'private, no-store');
    }

    public function removeItem(Request $request, Order $representative, OrderItem $item, AdminOrderQuickEditService $edits)
    {
        $validated = $request->validate([
            'request_key' => ['required', 'uuid'],
            'confirmed' => ['required', 'accepted'],
            'removal_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'change_reason' => $this->reasonRules(),
        ], $this->messages());
        $active = $edits->removeItem($representative, $item, $validated, $request->user(), $request);

        return response()->json(['message' => 'تم نقل العنصر للمحذوفات وتحديث الإجمالي مع الحفاظ على المبلغ المدفوع.',
            'redirect_url' => route('admin.orders.groups.show', $active)]);
    }

    public function options(Order $representative, AdminOrderQuickEditService $edits, Request $request)
    {
        return response()->json($edits->options($representative, $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function contact(Request $request, Order $representative, AdminOrderQuickEditService $edits)
    {
        $validated = $request->validate([
            'parent_name' => ['sometimes', 'required', 'string', 'max:150'],
            'phone' => ['sometimes', 'required', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'change_reason' => $this->reasonRules(),
        ], $this->messages());
        foreach (['phone', 'alternate_phone'] as $key) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }
            // Preserve legacy phone formats on unrelated name edits; validate newly changed numbers.
            $old = data_get($representative->delivery_details, $key);
            if (filled($validated[$key]) && Phone::normalize($validated[$key]) !== Phone::normalize($old)) {
                if (! Phone::isValidMobile($validated[$key])) {
                    throw ValidationException::withMessages([$key => 'اكتب رقم موبايل صحيحًا؛ الرقم المصري لا يحتاج كود الدولة.']);
                }
                $validated[$key] = Phone::normalize($validated[$key]);
            }
        }
        $edits->contact($representative, $validated, $request->user(), $request);

        return $this->saved($request);
    }

    public function addProduct(Request $request, Order $representative, AdminOrderQuickEditService $edits)
    {
        $validated = $request->validate([
            'request_key' => ['required', 'uuid'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'linked_order_id' => ['nullable', 'integer'],
            'reuse_child_order_id' => ['nullable', 'integer'],
            ...$this->personalizationRules(),
        ], $this->messages());
        $edits->addProduct($representative, $validated, $request->user(), $request);

        return $this->saved($request);
    }

    public function updateItem(Request $request, Order $representative, OrderItem $item, AdminOrderQuickEditService $edits)
    {
        $validated = $request->validate($this->personalizationRules(), $this->messages());
        $edits->updateItem($representative, $item, $validated, $request->user(), $request);

        return $this->saved($request);
    }

    public function addStory(Request $request, Order $representative, AdminOrderQuickEditService $edits)
    {
        $validated = $request->validate([
            'request_key' => ['required', 'uuid'],
            'story_id' => ['required', 'integer', Rule::exists('stories', 'id')->where('active', true)],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'reuse_child_order_id' => ['nullable', 'integer'],
            'child_name' => ['sometimes', 'required', 'string', 'max:100'],
            'child_age' => ['sometimes', 'required', 'integer', Rule::in(StoryAgeOptions::forPersonalization())],
            'child_gender' => ['sometimes', 'required', Rule::in(['boy', 'girl'])],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'interests' => ['nullable', 'string', 'max:1000'],
            'gift_note' => ['nullable', 'string', 'max:1000'],
            'parent_notes' => ['nullable', 'string', 'max:2000'],
            ...Arr::except($this->personalizationRules(), ['personalization']),
        ], $this->messages());
        $edits->addStory($representative, $validated, $request->user(), $request);

        return $this->saved($request);
    }

    public function updateStory(Request $request, Order $order, AdminOrderQuickEditService $edits)
    {
        $validated = $request->validate([
            'child_name' => ['sometimes', 'required', 'string', 'max:100'],
            'child_age' => ['sometimes', 'required', 'integer', 'min:1', 'max:18'],
            'child_gender' => ['sometimes', 'required', 'in:boy,girl'],
            'language' => ['sometimes', 'required', 'in:ar,en'],
            'lesson' => ['nullable', 'string', 'max:500'],
            'interests' => ['nullable', 'string', 'max:1000'],
            'gift_note' => ['nullable', 'string', 'max:1000'],
            'parent_notes' => ['nullable', 'string', 'max:2000'],
            ...Arr::except($this->personalizationRules(), ['personalization']),
        ], $this->messages());
        $edits->updateStory($order, $validated, $request->user(), $request);

        return $this->saved($request);
    }

    private function personalizationRules(): array
    {
        return [
            'personalization' => ['nullable', 'array'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['required', 'file', 'max:'.((int) config('photo_uploads.max_size_mb', 15) * 1024)],
            'change_reason' => $this->reasonRules(),
        ];
    }

    private function reasonRules(): array
    {
        return ['required', 'string', 'min:5', 'max:500'];
    }

    private function messages(): array
    {
        return ['change_reason.required' => 'اكتب سبب التعديل لحفظه في سجل النشاط.',
            'change_reason.min' => 'سبب التعديل يجب ألا يقل عن ٥ أحرف.'];
    }

    private function saved(Request $request)
    {
        return $request->expectsJson()
            ? response()->json(['message' => 'تم حفظ التعديل وتسجيل تفاصيله في سجل النشاط.'])
            : back()->with('success', 'تم حفظ التعديل وتسجيل تفاصيله في سجل النشاط.');
    }
}
