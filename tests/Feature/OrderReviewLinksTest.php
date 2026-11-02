<?php

namespace Tests\Feature;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\OrderProductPreviewGallery;
use App\Models\Setting;
use App\Models\Story;
use App\Services\Orders\OrderReviewLinks;
use App\Services\Orders\OrderWhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderReviewLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('s3_private');
    }

    public function test_mixed_checkout_contains_both_identities_and_one_gallery_without_other_orders_or_pdfs(): void
    {
        $first = $this->order('ONE', 'shared');
        $second = $this->order('TWO', 'shared');
        $other = $this->order('OTHER', 'other');
        $this->identity($first);
        $this->identity($second);
        $this->identity($other);
        $gallery = $this->gallery($first);
        $otherGallery = $this->gallery($other);
        $text = app(OrderReviewLinks::class)->forOrders(collect([$first->fresh(), $second->fresh()]));
        $this->assertSame(2, substr_count($text, 'هوية الطفل'));
        $this->assertSame(1, substr_count($text, $gallery->publicUrl()));
        $this->assertStringNotContainsString($otherGallery->publicUrl(), $text);
        $this->assertStringNotContainsString('synthetic/', $text);
        $this->assertStringNotContainsString('booklet', $text);
        $this->assertStringNotContainsString('scenes', $text);
        preg_match_all('~https?://[^\s]+~u', $text, $urls);
        $this->assertCount(3, $urls[0]);
        // Private identity URLs are actually signed and return the intended image.
        $this->get($urls[0][0])->assertOk()->assertStreamedContent('image-ONE');
        $this->get($urls[0][1])->assertOk()->assertStreamedContent('image-TWO');
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=bad', $urls[0][0]))->assertForbidden();
    }

    public function test_empty_disabled_invalid_and_deleted_previews_are_not_included(): void
    {
        $order = $this->order('EMPTY', 'empty');
        $this->assertSame('', app(OrderReviewLinks::class)->forOrders(collect([$order])));
        $gallery = $this->gallery($order);
        $gallery->update(['status' => 'revoked']);
        $this->assertSame('', app(OrderReviewLinks::class)->forOrders(collect([$order])));
        $gallery->update(['status' => 'active', 'public_token_encrypted' => 'invalid']);
        $this->assertSame('', app(OrderReviewLinks::class)->forOrders(collect([$order])));
        $this->identity($order);
        $order->delete();
        $this->assertSame('', app(OrderReviewLinks::class)->forOrders(collect([$order])));
    }

    public function test_new_variable_does_not_append_legacy_preview_or_pdf_and_does_not_edit_templates(): void
    {
        $order = $this->order('PRODUCT', 'product');
        $gallery = $this->gallery($order);
        $stored = json_encode([['id' => 'preview', 'title' => 'معاينة', 'is_active' => true, 'message' => '{{preview_links}}']]);
        Setting::updateOrCreate(['key' => OrderWhatsAppMessageService::SETTING_KEY], ['value' => $stored]);
        $group = ['key' => 'product', 'short_reference' => 'PRODUCT', 'phone' => '01000000001', 'active_orders' => collect([$order->fresh()])];
        $service = app(OrderWhatsAppMessageService::class);
        $this->assertSame([], $service->unknownVariables('{{preview_links}}'));
        $body = $service->messagesForGroup($group)[0]['body'];
        $this->assertSame("معاينة المنتجات\n".$gallery->publicUrl(), $body);
        $this->assertSame($stored, Setting::where('key', OrderWhatsAppMessageService::SETTING_KEY)->value('value'));
    }

    public function test_staff_without_photo_permission_does_not_receive_signed_identity_message(): void
    {
        $order = $this->order('LIMITED', 'limited');
        $this->identity($order);
        Setting::updateOrCreate(['key' => OrderWhatsAppMessageService::SETTING_KEY], ['value' => json_encode([
            ['id' => 'review', 'title' => 'Private review', 'message' => '{{preview_links}}', 'is_active' => true],
            ['id' => 'general', 'title' => 'General', 'message' => '{{order_reference}}', 'is_active' => true],
        ])]);
        $staff = \App\Models\User::factory()->create(['role' => 'employee']);
        $this->actingAs($staff);
        $messages = app(OrderWhatsAppMessageService::class)->messagesForGroup(['key' => 'limited', 'short_reference' => 'LIMITED', 'phone' => '01000000001', 'active_orders' => collect([$order->fresh()])]);
        $this->assertSame(['general'], array_column($messages, 'id'));
    }

    private function order(string $number, string $key): Order
    {
        return Order::create(['order_number' => $number, 'checkout_group_key' => $key, 'child_name' => 'طفل '.$number, 'status' => 'new_confirmed']);
    }

    private function identity(Order $order): void
    {
        $story = Story::create(['title' => 'قصة '.$order->order_number, 'slug' => 'test-'.Str::uuid(), 'language' => 'ar', 'gender' => 'both', 'price' => 100, 'active' => true]);
        $identity = ChildIdentityRequest::create(['uuid' => (string) Str::uuid(), 'resume_token_hash' => hash('sha256', Str::random()), 'parent_name' => 'Synthetic', 'parent_phone' => '01000000001', 'child_name' => 'Synthetic', 'child_age' => 7, 'age_range' => '6-9', 'gender' => 'girl', 'status' => 'completed']);
        $path = 'synthetic/'.$order->id.'.png';
        Storage::disk('s3_private')->put($path, 'image-'.$order->order_number);
        $attempt = ChildIdentityGenerationAttempt::create(['child_identity_request_id' => $identity->id, 'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(), 'status' => 'succeeded', 'model' => 'synthetic', 'prompt_version' => 'test', 'prompt_snapshot' => 'synthetic', 'prompt_hash' => hash('sha256', 'synthetic'), 'input_photos_count' => 1, 'image_size' => 'synthetic', 'image_quality' => 'synthetic', 'output_disk' => 's3_private', 'output_storage_path' => $path]);
        $order->update(['story_id' => $story->id, 'child_identity_request_id' => $identity->id, 'child_identity_approved_attempt_id' => $attempt->id]);
    }

    private function gallery(Order $order): OrderProductPreviewGallery
    {
        $token = Str::random(64);
        $gallery = OrderProductPreviewGallery::create(['checkout_group_key' => $order->checkoutGroupKey(), 'status' => 'active', 'public_token_hash' => hash('sha256', $token), 'public_token_encrypted' => Crypt::encryptString($token)]);
        $gallery->previews()->create(['order_id' => $order->id, 'file_path' => 'synthetic/product.png', 'disk' => 's3_private', 'original_name' => 'product.png', 'mime_type' => 'image/png']);

        return $gallery;
    }
}
