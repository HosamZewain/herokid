<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCountry;
use App\Models\Setting;
use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CartController extends Controller
{
    public function index()
    {
        $cart = $this->cart();

        return view('front.cart.index', [
            'cartItems' => $cart,
            'subtotal' => $this->subtotal($cart),
            'deliveryFee' => $this->defaultDeliveryFee(),
            'deliveryCountries' => $this->deliveryCountries(),
        ]);
    }

    public function store(Request $request, Story $story)
    {
        abort_unless($story->active, 404);

        $validated = $request->validate([
            'child_name' => 'required|string|max:255',
            'child_age' => 'required|integer|min:1|max:18',
            'child_gender' => 'required|in:boy,girl',
            'gift_note' => 'nullable|string|max:500',
            'interests' => 'nullable|string|max:500',
            'parent_notes' => 'nullable|string|max:1000',
            'privacy_consent' => 'required|accepted',
            'photos' => 'required|array|min:1|max:5',
            'photos.*' => 'image|mimes:jpeg,png,jpg|max:5120',
        ], [
            'child_name.required' => 'يرجى إدخال اسم الطفل.',
            'child_name.max' => 'اسم الطفل يجب ألا يزيد عن 255 حرفاً.',
            'child_age.required' => 'يرجى إدخال عمر الطفل.',
            'child_age.integer' => 'يرجى إدخال عمر الطفل كرقم صحيح.',
            'child_age.min' => 'عمر الطفل يجب ألا يقل عن سنة واحدة.',
            'child_age.max' => 'عمر الطفل يجب ألا يزيد عن 18 سنة.',
            'child_gender.required' => 'يرجى اختيار جنس الطفل.',
            'child_gender.in' => 'يرجى اختيار جنس صحيح للطفل.',
            'gift_note.max' => 'الإهداء يجب ألا يزيد عن 500 حرف.',
            'interests.max' => 'اهتمامات الطفل يجب ألا تزيد عن 500 حرف.',
            'parent_notes.max' => 'ملاحظات الفريق يجب ألا تزيد عن 1000 حرف.',
            'privacy_consent.required' => 'يجب الموافقة على استخدام الصور لإكمال الطلب.',
            'privacy_consent.accepted' => 'يجب الموافقة على استخدام الصور لإكمال الطلب.',
            'photos.required' => 'يرجى رفع صورة واحدة واضحة للطفل على الأقل.',
            'photos.array' => 'يرجى رفع صور الطفل بطريقة صحيحة.',
            'photos.min' => 'يرجى رفع صورة واحدة واضحة للطفل على الأقل.',
            'photos.max' => 'يمكنك رفع 5 صور كحد أقصى.',
            'photos.*.image' => 'يجب أن تكون صور الطفل ملفات صور صحيحة.',
            'photos.*.mimes' => 'صور الطفل يجب أن تكون بصيغة JPG أو PNG.',
            'photos.*.max' => 'حجم كل صورة يجب ألا يزيد عن 5 ميجا.',
        ]);

        $itemKey = (string) Str::uuid();
        $photoPaths = [];

        foreach ($request->file('photos', []) as $photo) {
            $photoPaths[] = $photo->store('orders/cart/' . now()->format('Y-m') . '/' . $itemKey, 'local');
        }

        $cart = $this->cart();
        $cart[$itemKey] = [
            'key' => $itemKey,
            'story_id' => $story->id,
            'story_title' => $story->title,
            'story_slug' => $story->slug,
            'story_price' => (float) $story->price,
            'story_language' => $story->language,
            'story_lesson' => $story->lesson_value,
            'child_name' => $validated['child_name'],
            'child_age' => $validated['child_age'],
            'child_gender' => $validated['child_gender'],
            'interests' => $validated['interests'] ?? null,
            'gift_note' => $validated['gift_note'] ?? null,
            'parent_notes' => $validated['parent_notes'] ?? null,
            'uploaded_photos' => $photoPaths,
        ];

        session(['cart.items' => $cart]);

        if ($request->input('next') === 'cart') {
            return redirect()->route('cart.index')->with('success', 'تمت إضافة القصة إلى السلة بنجاح.');
        }

        return redirect()
            ->route('stories.index')
            ->with('success', 'تمت إضافة القصة إلى السلة. يمكنك اختيار قصة أخرى أو إتمام الطلب من السلة.');
    }

    public function destroy(string $key)
    {
        $cart = $this->cart();

        if (isset($cart[$key])) {
            foreach ($cart[$key]['uploaded_photos'] ?? [] as $photoPath) {
                if (is_string($photoPath) && ! str_contains($photoPath, '..')) {
                    Storage::disk('local')->delete($photoPath);
                }
            }

            unset($cart[$key]);
            session(['cart.items' => $cart]);
        }

        return redirect()->route('cart.index')->with('success', 'تم حذف القصة من السلة.');
    }

    private function cart(): array
    {
        return session('cart.items', []);
    }

    private function subtotal(array $cart): float
    {
        return collect($cart)->sum(fn (array $item): float => (float) ($item['story_price'] ?? 0));
    }

    private function defaultDeliveryFee(): float
    {
        $country = $this->deliveryCountries()->first();

        if ($country) {
            return max(0, (float) $country->delivery_fee);
        }

        $settings = Cache::remember('site_settings', 3600, fn () => Setting::all()->pluck('value', 'key')->toArray());

        return max(0, (float) ($settings['delivery_fee'] ?? 0));
    }

    private function deliveryCountries()
    {
        return DeliveryCountry::where('active', true)
            ->with(['activeGovernorates'])
            ->orderByRaw("code = 'EG' desc")
            ->orderBy('name')
            ->get();
    }
}
