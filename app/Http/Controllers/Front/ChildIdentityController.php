<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityPhoto;
use App\Models\ChildIdentityRequest;
use App\Models\Story;
use App\Models\StoryCategory;
use App\Services\Cart\CartTrackingService;
use App\Services\Cart\StoryCartItemBuilder;
use App\Services\ChildIdentity\AgeRangeResolver;
use App\Services\ChildIdentity\ChildIdentityAccessService;
use App\Services\ChildIdentity\ChildIdentityApprovalService;
use App\Services\ChildIdentity\ChildIdentityAttemptService;
use App\Services\ChildIdentity\ChildIdentityEventLogger;
use App\Services\ChildIdentity\ChildIdentityPhotoService;
use App\Services\ChildIdentity\ChildIdentitySettings;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChildIdentityController extends Controller
{
    public function index(ChildIdentitySettings $settings)
    {
        return response()
            ->view('front.child-identity.start', ['enabled' => $settings->enabled()])
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function store(
        Request $request,
        ChildIdentitySettings $settings,
        AgeRangeResolver $ageRanges,
        ChildIdentityAccessService $access,
        ChildIdentityEventLogger $events,
    ) {
        abort_unless($settings->enabled(), 404);
        $request->merge(['parent_phone' => Phone::normalize($request->input('parent_phone'))]);
        $validated = $request->validate([
            'parent_name' => ['required', 'string', 'max:255'],
            'parent_phone' => ['required', 'string', 'max:30'],
            'parent_email' => ['nullable', 'email', 'max:255'],
            'child_name' => ['required', 'string', 'max:255'],
            'child_age' => ['required', 'integer', 'min:1', 'max:18'],
            'gender' => ['nullable', Rule::in(['boy', 'girl'])],
            'processing_consent' => ['required', 'accepted'],
            'marketing_consent' => ['sometimes', 'accepted'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
        ]);
        $ageRange = $ageRanges->resolve((int) $validated['child_age']);
        $identity = ChildIdentityRequest::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => auth()->id(),
            'resume_token_hash' => hash('sha256', Str::random(80)),
            'parent_name' => $validated['parent_name'],
            'parent_phone' => $validated['parent_phone'],
            'parent_email' => $validated['parent_email'] ?? null,
            'child_name' => $validated['child_name'],
            'child_age' => $validated['child_age'],
            'age_range' => $ageRange,
            'gender' => $validated['gender'] ?? null,
            'status' => 'incomplete',
            'consent_accepted_at' => now(),
            'consent_version' => 'child-identity-v1-2026-07',
            'marketing_consent_at' => $request->boolean('marketing_consent') ? now() : null,
            'utm_source' => $validated['utm_source'] ?? null,
            'utm_medium' => $validated['utm_medium'] ?? null,
            'utm_campaign' => $validated['utm_campaign'] ?? null,
            'utm_content' => $validated['utm_content'] ?? null,
            'utm_term' => $validated['utm_term'] ?? null,
            'referrer' => Str::limit((string) $request->headers->get('referer'), 2000, ''),
            'last_activity_at' => now(),
        ]);
        $token = $access->issue($identity, $request);
        $events->record($identity, 'request.created', 'تم إنشاء طلب هوية الطفل وحفظه بشكل دائم.');
        $resumeUrl = route('child-identity.resume', ['identity' => $identity->uuid, 'token' => $token]);

        return redirect()
            ->route('child-identity.show', $identity->uuid)
            ->with('success', 'تم حفظ الطلب. ارفع من صورتين إلى ٥ صور واضحة.')
            ->with('resume_url', $resumeUrl);
    }

    public function resume(
        Request $request,
        ChildIdentityRequest $identity,
        string $token,
        ChildIdentityAccessService $access,
    ) {
        abort_unless($access->resume($identity, $token, $request), 403);

        return redirect()
            ->route('child-identity.show', $identity->uuid)
            ->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer']);
    }

    public function show(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityAccessService $access,
        AgeRangeResolver $ageRanges,
    ) {
        $this->authorizeIdentity($identity, $request, $access);
        $identity->load(['photos', 'attempts', 'approvedAttempt', 'selectedCategory', 'selectedStory']);
        $media = [
            'photos' => $identity->photos->mapWithKeys(fn ($photo) => [
                $photo->id => URL::temporarySignedRoute(
                    'child-identity.media.photo',
                    now()->addMinutes(10),
                    ['identity' => $identity->uuid, 'photo' => $photo->id],
                ),
            ]),
            'attempts' => $identity->attempts
                ->where('status', 'succeeded')
                ->mapWithKeys(fn ($attempt) => [
                    $attempt->id => URL::temporarySignedRoute(
                        'child-identity.media.attempt',
                        now()->addMinutes(10),
                        ['identity' => $identity->uuid, 'attempt' => $attempt->id],
                    ),
                ]),
        ];
        $stories = Story::query()
            ->where('active', true)
            ->with('categories')
            ->orderBy('title')
            ->get()
            ->filter(fn (Story $story) => $ageRanges->normalized((string) $story->age_range) === $ageRanges->normalized($identity->age_range))
            ->values();
        $categories = StoryCategory::query()
            ->whereHas('stories', fn ($query) => $query->where('active', true))
            ->orderBy('name')
            ->get()
            ->filter(fn (StoryCategory $category) => $stories->contains(fn (Story $story) => $story->categories->contains($category)))
            ->values();

        return response()
            ->view('front.child-identity.show', compact('identity', 'media', 'stories', 'categories'))
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function uploadPhoto(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityAccessService $access,
        ChildIdentityPhotoService $photos,
        ChildIdentityEventLogger $events,
    ) {
        $this->authorizeIdentity($identity, $request, $access);
        $this->ensureCustomerMutable($identity);
        $request->validate(['photo' => ['required', 'file', 'max:15360']]);
        $photo = $photos->store($identity, $request->file('photo'));
        $fromStatus = $identity->status;
        $targetStatus = $identity->approved_attempt_id
            ? $identity->status
            : ($identity->validPhotos()->count() >= 2 ? 'photos_uploaded' : 'incomplete');
        $identity->forceFill([
            'status' => $targetStatus,
        ])->save();
        $events->record(
            $identity,
            'photo.uploaded',
            'تم حفظ صورة أصلية للطفل.',
            ['photo_id' => $photo->id],
            fromStatus: $fromStatus,
            toStatus: $identity->status,
        );

        return back()->with('success', 'تم حفظ الصورة بنجاح.');
    }

    public function removePhoto(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityPhoto $photo,
        ChildIdentityAccessService $access,
        ChildIdentityPhotoService $photos,
        ChildIdentityEventLogger $events,
    ) {
        $this->authorizeIdentity($identity, $request, $access);
        $this->ensureCustomerMutable($identity);
        abort_unless($photo->child_identity_request_id === $identity->id, 404);
        abort_if($photo->attempts()->exists(), 422, 'لا يمكن إزالة صورة استُخدمت في محاولة محفوظة.');
        $photos->markRemoved($photo);
        $targetStatus = $identity->approved_attempt_id
            ? $identity->status
            : ($identity->validPhotos()->count() >= 2 ? 'photos_uploaded' : 'incomplete');
        $identity->forceFill([
            'status' => $targetStatus,
        ])->save();
        $events->record($identity, 'photo.removed', 'تم إخفاء صورة من الصور النشطة.', ['photo_id' => $photo->id]);

        return back()->with('success', 'تمت إزالة الصورة من الطلب مع الاحتفاظ بسجلها الآمن.');
    }

    public function generate(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityAccessService $access,
        ChildIdentityAttemptService $attempts,
    ) {
        $this->authorizeIdentity($identity, $request, $access);
        $this->ensureCustomerMutable($identity);
        $validated = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $attempts->create($identity, $validated['idempotency_key']);

        return back()->with('success', 'بدأ إنشاء هوية طفلك. سيتم تحديث النتيجة تلقائيًا.');
    }

    public function poll(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityAccessService $access,
    ) {
        $this->authorizeIdentity($identity, $request, $access);
        $latest = $identity->attempts()
            ->whereIn('status', ['pending', 'processing'])
            ->latest('attempt_number')
            ->first()
            ?? $identity->attempts()->latest('attempt_number')->first();

        return response()->json([
            'request_status' => $identity->fresh()->status,
            'attempt_status' => $latest?->status,
            'attempt_number' => $latest?->attempt_number,
            'message' => $latest?->safe_error_message,
            'refresh' => in_array($latest?->status, ['pending', 'processing'], true),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function approve(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityGenerationAttempt $attempt,
        ChildIdentityAccessService $access,
        ChildIdentityApprovalService $approvals,
    ) {
        $this->authorizeIdentity($identity, $request, $access);
        $this->ensureCustomerMutable($identity);
        $approvals->approve($identity, $attempt, $request->user());

        return back()->with('success', 'تم اعتماد الهوية. اختر قصة مناسبة لطفلك.');
    }

    public function selectCategory(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityAccessService $access,
        ChildIdentityEventLogger $events,
    ) {
        $this->authorizeIdentity($identity, $request, $access);
        $this->ensureCustomerMutable($identity);
        abort_unless($identity->approved_attempt_id, 422);
        $validated = $request->validate(['story_category_id' => ['required', 'exists:story_categories,id']]);
        $identity->forceFill([
            'selected_story_category_id' => $validated['story_category_id'],
            'selected_story_id' => null,
            'status' => 'approved',
        ])->save();
        $events->record($identity, 'category.selected', 'تم اختيار تصنيف للقصة.', $validated);

        return back()->with('success', 'اختر القصة التي تناسب طفلك.');
    }

    public function selectStory(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityAccessService $access,
        ChildIdentityEventLogger $events,
        AgeRangeResolver $ageRanges,
    ) {
        $this->authorizeIdentity($identity, $request, $access);
        $this->ensureCustomerMutable($identity);
        abort_unless($identity->approved_attempt_id && $identity->selected_story_category_id, 422);
        $validated = $request->validate(['story_id' => ['required', 'exists:stories,id']]);
        $story = Story::query()->where('active', true)->with('categories')->findOrFail($validated['story_id']);
        abort_unless(
            $story->categories->contains('id', $identity->selected_story_category_id)
            && $ageRanges->normalized((string) $story->age_range) === $ageRanges->normalized($identity->age_range),
            422,
        );
        $identity->forceFill(['selected_story_id' => $story->id, 'status' => 'story_selected'])->save();
        $events->record($identity, 'story.selected', 'تم اختيار القصة.', ['story_id' => $story->id]);

        return back()->with('success', 'تم اختيار القصة. أضفها إلى السلة لإكمال الطلب العادي.');
    }

    public function addToCart(
        Request $request,
        ChildIdentityRequest $identity,
        ChildIdentityAccessService $access,
        StoryCartItemBuilder $builder,
        ChildIdentityEventLogger $events,
    ) {
        $this->authorizeIdentity($identity, $request, $access);
        $this->ensureCustomerMutable($identity);
        abort_unless(in_array($identity->status, ['story_selected', 'in_cart'], true), 422);
        $identity->load(['selectedStory', 'approvedAttempt', 'validPhotos']);
        abort_unless(
            $identity->selectedStory?->active
            && $identity->approvedAttempt?->status === 'succeeded'
            && $identity->validPhotos->count() >= 2,
            422,
        );
        $cart = session('cart.items', []);
        $existingKey = collect($cart)->search(
            fn (array $item) => (int) ($item['child_identity_request_id'] ?? 0) === $identity->id
        );
        $itemKey = is_string($existingKey) ? $existingKey : (string) Str::uuid();
        $cart[$itemKey] = $builder->build(
            $identity->selectedStory,
            $itemKey,
            [
                'child_name' => $identity->child_name,
                'child_age' => $identity->child_age,
                'child_gender' => $identity->gender,
                'child_identity_request_id' => $identity->id,
                'child_identity_approved_attempt_id' => $identity->approved_attempt_id,
                'child_identity_cost_usd' => $identity->total_cost_usd,
            ],
            $identity->validPhotos->pluck('path')->all(),
        );
        session(['cart.items' => $cart]);
        session()->flash('upsell_story_key', $itemKey);
        app(CartTrackingService::class)->recordItemAdded($request, $itemKey);
        $identity->forceFill(['status' => 'in_cart'])->save();
        $events->record($identity, 'cart.added', 'تمت إضافة القصة المختارة إلى السلة.', ['cart_item_key' => $itemKey]);

        return redirect()->route('cart.index')->with('success', 'تمت إضافة القصة بالسعر العادي إلى السلة. خدمة الهوية مجانية.');
    }

    private function authorizeIdentity(
        ChildIdentityRequest $identity,
        Request $request,
        ChildIdentityAccessService $access,
    ): void {
        abort_unless($access->authorized($identity, $request), 403);
    }

    private function ensureCustomerMutable(ChildIdentityRequest $identity): void
    {
        abort_if(
            in_array($identity->status, ['converted', 'cancelled'], true),
            422,
            'لم يعد هذا الطلب قابلًا للتعديل من رابط العميل.',
        );
    }
}
