<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Story;
use App\Models\StoryCategory;
use App\Models\VisitorCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FootballStoriesLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_landing_loads_only_active_football_stories_from_the_category(): void
    {
        $active = $this->footballStory('football-active', 'بطل ملعب كرة القدم', 349);
        $inactive = $this->footballStory('football-inactive', 'قصة كرة غير متاحة', 399, false);
        $unrelated = $this->story('space-active', 'بطل الفضاء', 299);

        $this->get(route('football-stories.index'))
            ->assertOk()
            ->assertSee($active->title)
            ->assertDontSee($inactive->title)
            ->assertDontSee($unrelated->title)
            ->assertSee('خلي طفلك بطل قصة كرة القدم باسمه وصورته')
            ->assertSee('data-football-sticky-summary', false)
            ->assertSee('data-football-form', false)
            ->assertSee('data-story-checkbox', false)
            ->assertSee('data-story-toggle', false)
            ->assertSee('data-football-photo-input', false)
            ->assertSee('٠ / ٣')
            ->assertSee('canonical', false)
            ->assertSee(route('football-stories.index'), false)
            ->assertSee('"@type":"CollectionPage"', false)
            ->assertSee('"priceCurrency":"EGP"', false);

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertSee('/football-stories', false);
    }

    public function test_customer_cannot_continue_without_a_story_or_required_child_information(): void
    {
        $this->footballStory('football-validation', 'قصة التحقق', 349);

        $this->from(route('football-stories.index'))
            ->post(route('football-stories.store'), [])
            ->assertRedirect(route('football-stories.index'))
            ->assertSessionHasErrors([
                'story_ids',
                'child_name',
                'child_age',
                'child_gender',
                'photo_upload_ids',
            ]);

        $this->get(route('football-stories.index'))
            ->assertSee('اختر قصة كرة قدم واحدة على الأقل للمتابعة.')
            ->assertSee('اكتب اسم الطفل الأول.')
            ->assertSee('ارفع صورتين واضحتين للطفل على الأقل.');
    }

    public function test_one_child_and_one_photo_set_create_multiple_story_cart_items_with_the_correct_total(): void
    {
        $first = $this->footballStory('football-first', 'بطل الملعب', 349);
        $second = $this->footballStory('football-second', 'موعد هالاند', 399);
        [$sessionToken, $photoIds] = $this->uploadedPhotos(2);

        $this->post(route('football-stories.store'), $this->landingPayload(
            [$first->id, $second->id],
            $sessionToken,
            $photoIds,
        ))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('cart.index'));

        $items = collect(session('cart.items'));
        $this->assertCount(2, $items);
        $this->assertSame(748.0, $items->sum('story_price'));
        $this->assertCount(1, $items->pluck('shared_personalization_key')->unique());
        $this->assertCount(1, $items->pluck('child_name')->unique());
        $this->assertCount(1, $items->map(fn (array $item): string => serialize($item['uploaded_photos']))->unique());
        $this->assertTrue($items->every(fn (array $item): bool => $item['source_context'] === 'football_landing'));

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('data-cart-added-toast', false)
            ->assertSee('٢ قصص كرة قدم')
            ->assertSee('الخطوة ٣ من ٣')
            ->assertSee("track('AddToCart'", false);
    }

    public function test_removing_one_selected_story_keeps_shared_photos_for_the_other_story(): void
    {
        $first = $this->footballStory('football-remove-first', 'القصة الأولى', 349);
        $second = $this->footballStory('football-remove-second', 'القصة الثانية', 349);
        [$sessionToken, $photoIds] = $this->uploadedPhotos(2);

        $this->post(route('football-stories.store'), $this->landingPayload(
            [$first->id, $second->id],
            $sessionToken,
            $photoIds,
        ))->assertRedirect(route('cart.index'));

        $cart = session('cart.items');
        $firstKey = array_key_first($cart);
        $paths = $cart[$firstKey]['uploaded_photos'];

        $this->deleteJson(route('cart.destroy', $firstKey))
            ->assertOk()
            ->assertJsonPath('cart_count', 1);

        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }

        $this->assertCount(1, session('cart.items'));
    }

    public function test_three_photos_are_allowed_but_a_fourth_photo_is_rejected(): void
    {
        $story = $this->footballStory('football-three-photos', 'قصة الصور', 349);
        [$sessionToken, $threePhotoIds] = $this->uploadedPhotos(3);

        $this->post(route('football-stories.store'), $this->landingPayload(
            [$story->id],
            $sessionToken,
            $threePhotoIds,
        ))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('cart.index'));

        $this->flushSession();
        [$nextToken, $photoIds] = $this->uploadedPhotos(3);
        $photoIds[] = 'a2195925-5fb1-40e7-bbec-9202709a3eca';

        $this->from(route('football-stories.index'))
            ->post(route('football-stories.store'), $this->landingPayload(
                [$story->id],
                $nextToken,
                $photoIds,
            ))
            ->assertRedirect(route('football-stories.index'))
            ->assertSessionHasErrors('photo_upload_ids');
    }

    public function test_guest_checkout_creates_one_order_per_story_in_one_checkout_and_preserves_first_touch_attribution(): void
    {
        $first = $this->footballStory('football-checkout-first', 'قصة صلاح', 349);
        $second = $this->footballStory('football-checkout-second', 'قصة هالاند', 399);

        $this->withHeader('Referer', 'https://www.facebook.com/')
            ->get(route('football-stories.index', [
                'utm_source' => 'facebook',
                'utm_medium' => 'paid_social',
                'utm_campaign' => 'football_august',
                'utm_content' => 'video_a',
                'campaign_id' => 'cmp-10',
                'adset_id' => 'set-20',
                'ad_id' => 'ad-30',
                'fbclid' => 'fb-click-value',
            ]))
            ->assertOk();

        [$sessionToken, $photoIds] = $this->uploadedPhotos(2);
        $this->post(route('football-stories.store'), $this->landingPayload(
            [$first->id, $second->id],
            $sessionToken,
            $photoIds,
        ))->assertRedirect(route('cart.index'));

        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail();
        $governorate->update(['delivery_fee' => 75]);

        $this->post(route('checkout.store'), [
            'parent_name' => 'ولي أمر الطفل',
            'phone' => '01000000000',
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'city' => 'مدينة نصر',
            'street' => 'شارع الاختبار',
            'address_details' => 'عمارة ١ شقة ٢',
        ])->assertRedirect(route('checkout.success'));

        $orders = Order::query()->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertCount(1, $orders->pluck('checkout_group_key')->unique());
        $this->assertSame([349.0, 399.0], $orders->pluck('delivery_details')->map(fn (array $details): float => (float) $details['item_price'])->all());
        $this->assertTrue($orders->every(fn (Order $order): bool => $order->order_source === 'website'));
        $this->assertTrue($orders->every(fn (Order $order): bool => (float) $order->delivery_details['total'] === 823.0));
        $this->assertSame('facebook', $orders->first()->delivery_details['marketing_attribution']['utm_source']);
        $this->assertSame('video_a', $orders->first()->delivery_details['marketing_attribution']['utm_content']);
        $this->assertSame('ad-30', $orders->first()->delivery_details['marketing_attribution']['ad_id']);

        $visitorCart = VisitorCart::query()->sole();
        $this->assertSame('facebook', $visitorCart->utm_source);
        $this->assertSame('video_a', $visitorCart->utm_content);
        $this->assertSame('converted', $visitorCart->status);
    }

    public function test_age_mismatch_is_explained_without_removing_the_selection(): void
    {
        $story = $this->footballStory('football-older-child', 'بطولة الكبار', 349, true, '9-12');

        $this->get(route('football-stories.index'))
            ->assertOk()
            ->assertSee('data-ages="9,10,11,12"', false)
            ->assertSee('data-age-warning', false)
            ->assertSee('aria-live="polite"', false);
    }

    private function footballStory(
        string $slug,
        string $title,
        int $price,
        bool $active = true,
        string $ageRange = '3-9',
    ): Story {
        $story = $this->story($slug, $title, $price, $active, $ageRange);
        $category = StoryCategory::firstOrCreate(
            ['slug' => 'football-stories'],
            ['name' => 'قصص كرة القدم'],
        );
        $story->categories()->syncWithoutDetaching([$category->id]);

        return $story;
    }

    private function story(
        string $slug,
        string $title,
        int $price,
        bool $active = true,
        string $ageRange = '3-9',
    ): Story {
        return Story::create([
            'title' => $title,
            'slug' => $slug,
            'short_desc' => 'قصة كرة قدم قصيرة وممتعة.',
            'age_range' => $ageRange,
            'language' => 'ar',
            'lesson_value' => 'الثقة والعمل الجماعي',
            'price' => $price,
            'active' => $active,
        ]);
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function uploadedPhotos(int $count): array
    {
        $session = $this->getJson(route('photo-uploads.session'))->assertOk()->json();
        $photoIds = [];

        for ($index = 1; $index <= $count; $index++) {
            $photoIds[] = $this->postJson(route('photo-uploads.store'), [
                'upload_session_token' => $session['upload_session_token'],
                'upload_batch_token' => $session['upload_batch_token'],
                'photo' => $this->tinyPngUpload("child-{$index}.png"),
            ])->assertCreated()->json('id');
        }

        return [$session['upload_session_token'], $photoIds];
    }

    /**
     * @param  array<int, int>  $storyIds
     * @param  array<int, string>  $photoIds
     */
    private function landingPayload(array $storyIds, string $sessionToken, array $photoIds): array
    {
        return [
            'story_ids' => $storyIds,
            'child_name' => 'نور',
            'child_age' => 7,
            'child_gender' => 'boy',
            'gift_note' => 'إهداء اختياري',
            'interests' => 'كرة القدم',
            'parent_notes' => 'ملاحظة',
            'upload_session_token' => $sessionToken,
            'photo_upload_ids' => $photoIds,
        ];
    }

    private function tinyPngUpload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'herokid-football-photo-');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
        ));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
