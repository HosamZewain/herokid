<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Story;
use App\Services\Cart\CartTrackingService;
use App\Services\Cart\StoryCartItemBuilder;
use App\Services\Catalog\UnifiedStorefrontService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Services\Uploads\UploadValidationException;
use App\Support\StoryAgeOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;

class FootballStoriesController extends Controller
{
    public const CATEGORY_SLUG = 'football-stories';

    public function index(
        Request $request,
        TemporaryPhotoUploadService $uploads,
        UnifiedStorefrontService $storefront,
    ) {
        $uploadSession = $uploads->ensureSession($request);
        $stories = $this->footballStories()
            ->get()
            ->map(function (Story $story) use ($storefront): array {
                $item = $storefront->storyItem($story);

                return [
                    'model' => $story,
                    'catalog' => $item,
                    'recommended_ages' => StoryAgeOptions::fromRange($story->age_range),
                ];
            });
        $relatedProducts = Product::query()
            ->with('category')
            ->publiclyVisible()
            ->where('personalization_mode', '!=', 'collect_child_details')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->take(4)
            ->get();

        return view('front.football-stories.index', [
            'stories' => $stories,
            'relatedProducts' => $relatedProducts,
            'ageOptions' => StoryAgeOptions::forPersonalization(),
            'paymentMethods' => setting_array('payment_methods'),
            'photoUploadConfig' => [
                'sessionToken' => $uploadSession['token'],
                'batchToken' => Str::random(48),
                'uploadUrl' => route('photo-uploads.store'),
                'previewUrlTemplate' => route('photo-uploads.show', ['publicId' => '__ID__']),
                'deleteUrlTemplate' => route('photo-uploads.destroy', ['publicId' => '__ID__']),
                'minimumFiles' => (int) config('photo_uploads.min_files', 2),
                'maxFiles' => (int) config('photo_uploads.max_files', 3),
                'maxSizeMb' => (int) config('photo_uploads.max_size_mb', 15),
                'concurrency' => (int) config('photo_uploads.concurrency', 2),
                'maxLongEdge' => (int) config('photo_uploads.max_long_edge', 2560),
                'jpegQuality' => (int) config('photo_uploads.jpeg_quality', 90),
                'serverRejectedUploads' => $this->uploadErrors($request->session()->get('errors')),
            ],
        ]);
    }

    public function store(
        Request $request,
        TemporaryPhotoUploadService $uploads,
        StoryCartItemBuilder $itemBuilder,
    ) {
        $maximumPhotos = (int) config('photo_uploads.max_files', 3);
        $validated = $request->validate([
            'story_ids' => ['required', 'array', 'min:1'],
            'story_ids.*' => ['required', 'integer', 'distinct'],
            'child_name' => ['required', 'string', 'max:255'],
            'child_age' => ['required', 'integer', Rule::in(StoryAgeOptions::forPersonalization())],
            'child_gender' => ['required', Rule::in(['boy', 'girl'])],
            'gift_note' => ['nullable', 'string', 'max:500'],
            'interests' => ['nullable', 'string', 'max:500'],
            'parent_notes' => ['nullable', 'string', 'max:1000'],
            'upload_session_token' => ['required', 'string'],
            'photo_upload_ids' => ['required', 'array', 'min:2', 'max:'.$maximumPhotos],
            'photo_upload_ids.*' => ['required', 'uuid', 'distinct'],
        ], [
            'story_ids.required' => 'اختر قصة كرة قدم واحدة على الأقل للمتابعة.',
            'story_ids.array' => 'اختيار القصص غير صالح. أعد اختيار القصص المطلوبة.',
            'story_ids.min' => 'اختر قصة كرة قدم واحدة على الأقل للمتابعة.',
            'story_ids.*.integer' => 'إحدى القصص المختارة غير صالحة.',
            'story_ids.*.distinct' => 'تم اختيار القصة نفسها أكثر من مرة.',
            'child_name.required' => 'اكتب اسم الطفل الأول.',
            'child_name.max' => 'اسم الطفل يجب ألا يزيد عن ٢٥٥ حرفًا.',
            'child_age.required' => 'اختر عمر الطفل.',
            'child_age.integer' => 'اختر عمر الطفل من القائمة.',
            'child_age.in' => 'اختر عمرًا من ٣ إلى ١٢ سنة.',
            'child_gender.required' => 'اختر جنس الطفل لتخصيص النص والصور بصورة صحيحة.',
            'child_gender.in' => 'اختيار جنس الطفل غير صالح.',
            'gift_note.max' => 'الإهداء يجب ألا يزيد عن ٥٠٠ حرف.',
            'interests.max' => 'الاهتمامات يجب ألا تزيد عن ٥٠٠ حرف.',
            'parent_notes.max' => 'الملاحظات يجب ألا تزيد عن ١٠٠٠ حرف.',
            'upload_session_token.required' => 'انتهت جلسة رفع الصور. حدّث الصفحة وحاول مرة أخرى.',
            'photo_upload_ids.required' => 'ارفع صورتين واضحتين للطفل على الأقل.',
            'photo_upload_ids.array' => 'صور الطفل المرفوعة غير صالحة.',
            'photo_upload_ids.min' => 'ارفع صورتين واضحتين للطفل على الأقل.',
            'photo_upload_ids.max' => 'يمكنك رفع '.$maximumPhotos.' صور كحد أقصى.',
            'photo_upload_ids.*.uuid' => 'إحدى الصور المرفوعة غير صالحة. احذفها وارفعها مرة أخرى.',
            'photo_upload_ids.*.distinct' => 'تم إرسال الصورة نفسها أكثر من مرة.',
        ]);

        $selectedStories = $this->footballStories()
            ->whereIn('stories.id', $validated['story_ids'])
            ->get()
            ->keyBy('id');

        if ($selectedStories->count() !== count($validated['story_ids'])) {
            return back()
                ->withInput()
                ->withErrors(['story_ids' => 'إحدى القصص المختارة لم تعد متاحة. راجع اختيارك وحاول مرة أخرى.']);
        }

        $sharedCartKey = 'football-'.Str::uuid();

        try {
            $photoPaths = $uploads->attachIdsToCart($request, $validated['photo_upload_ids'], $sharedCartKey)
                ->pluck('path')
                ->values()
                ->all();
        } catch (UploadValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors([$exception->field ?: 'photo_upload_ids' => $exception->getMessage()]);
        }

        $cart = session('cart.items', []);
        $addedKeys = [];
        $addedValue = 0.0;

        foreach ($validated['story_ids'] as $storyId) {
            $story = $selectedStories->get((int) $storyId);
            $itemKey = (string) Str::uuid();
            $cartItem = $itemBuilder->build($story, $itemKey, $validated, $photoPaths);
            $cartItem['source_context'] = 'football_landing';
            $cartItem['shared_personalization_key'] = $sharedCartKey;
            $cart[$itemKey] = $cartItem;
            $addedKeys[] = $itemKey;
            $addedValue += (float) $cartItem['story_price'];
        }

        session(['cart.items' => $cart]);
        session()->flash('upsell_story_key', $addedKeys[0]);
        session()->flash('football_add_to_cart_event', [
            'content_type' => 'product',
            'content_ids' => $selectedStories->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            'content_name' => $selectedStories->pluck('title')->implode('، '),
            'num_items' => $selectedStories->count(),
            'value' => $addedValue,
            'currency' => 'EGP',
        ]);

        foreach (array_keys($cart) as $cartKey) {
            if (($cart[$cartKey]['source_context'] ?? null) === 'football_landing'
                && ($cart[$cartKey]['shared_personalization_key'] ?? null) === $sharedCartKey) {
                app(CartTrackingService::class)->recordItemAdded($request, $cartKey);
            }
        }

        return redirect()->route('cart.index')->with('cart_added_notice', [
            'story_title' => $selectedStories->count() === 1
                ? $selectedStories->first()->title
                : arabic_number($selectedStories->count()).' قصص كرة قدم',
            'story_titles' => $selectedStories->pluck('title')->values()->all(),
            'story_count' => $selectedStories->count(),
        ]);
    }

    private function footballStories(): Builder
    {
        return Story::query()
            ->with(['categories:id,name,slug', 'publicBookletPreview.currentVersion'])
            ->where('active', true)
            ->whereHas('categories', fn ($query) => $query->where('slug', self::CATEGORY_SLUG))
            ->orderBy('title');
    }

    private function uploadErrors(mixed $errors): bool
    {
        return $errors instanceof ViewErrorBag
            && ($errors->has('photo_upload_ids') || $errors->has('photo_upload_ids.*'));
    }
}
