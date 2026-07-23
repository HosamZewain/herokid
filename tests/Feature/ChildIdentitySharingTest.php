<?php

namespace Tests\Feature;

use App\Jobs\GenerateChildIdentityShareCardsJob;
use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\ChildIdentity\Sharing\ChildIdentityReferralService;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareCardGenerator;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareDraftService;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareEventService;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareManager;
use App\Services\ChildIdentity\Sharing\ChildIdentitySharePresenter;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareReportService;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChildIdentitySharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_share_buttons_require_a_successful_approved_attempt_without_an_extra_consent_dialog(): void
    {
        Storage::fake('local');
        [$identity, $attempt] = $this->approvedIdentity(900, 600);
        $attempt = app(ChildIdentityShareDraftService::class)->prepare($attempt);
        $unapproved = ChildIdentityRequest::create($this->identityAttributes(['uuid' => (string) Str::uuid()]));

        $this->withSession(['child_identity_grants' => [$unapproved->uuid]])
            ->get(route('child-identity.show', $unapproved->uuid))
            ->assertOk()
            ->assertDontSee('شارك هوية طفلك وخلي أصحابك يجربوا');

        $this->withSession(['child_identity_grants' => [$identity->uuid]])
            ->get(route('child-identity.show', $identity->uuid))
            ->assertOk()
            ->assertSee('واتساب')
            ->assertSee('فيسبوك')
            ->assertSee('تحميل')
            ->assertSee('شارك صورة هوية طفلك مع عائلتك واصدقائك')
            ->assertDontSee('خلّي أصحابك يجربوا HeroKid')
            ->assertDontSee('موافق، أظهر أزرار المشاركة')
            ->assertDontSee('data-share-payload=', false);

        $this->post(route('child-identity.shares.store', $identity->uuid), [])
            ->assertSessionHasErrors('share_consent');
        $this->assertDatabaseCount('child_identity_shares', 0);

        $this->withSession(['child_identity_grants' => [$identity->uuid]])
            ->post(route('child-identity.shares.store', $identity->uuid), [
                'share_consent' => '1',
            ])->assertRedirect();

        $share = ChildIdentityShare::firstOrFail();
        $this->assertSame($attempt->id, $share->generation_attempt_id);
        $this->assertTrue($share->share_enabled);
        $this->assertSame('ready', $share->status);
        $this->assertSame(64, strlen($share->public_token));
        $this->assertNotSame((string) $identity->id, $share->public_token);
        $this->assertNotNull($share->consent_accepted_at);
        $this->assertNotNull($share->ip_hash);
        $this->assertNotNull($share->guest_session_hash);
        foreach (ChildIdentityShare::VARIANTS as $variant) {
            Storage::disk('local')->assertExists($share->cardPath($variant));
        }
    }

    public function test_first_share_button_click_creates_the_public_share_and_redirects_immediately(): void
    {
        Storage::fake('local');
        [$identity, $attempt] = $this->approvedIdentity(900, 600);
        app(ChildIdentityShareDraftService::class)->prepare($attempt);

        $response = $this->withSession(['child_identity_grants' => [$identity->uuid]])
            ->post(route('child-identity.shares.store', $identity->uuid), [
                'share_action' => 'whatsapp',
            ])
            ->assertRedirect();

        $share = ChildIdentityShare::firstOrFail();
        $this->assertStringStartsWith(
            'https://wa.me/?text=',
            (string) $response->headers->get('Location'),
            json_encode([
                'share_status' => $share->status,
                'events' => $share->events()->pluck('event_type')->all(),
                'session' => $response->getSession()->all(),
            ]),
        );
        $this->assertSame('ready', $share->status);
        $this->assertDatabaseHas('child_identity_share_events', [
            'child_identity_share_id' => $share->id,
            'event_type' => 'share.whatsapp_clicked',
        ]);
        $this->assertNotContains(
            'throttle:6,1',
            app('router')->getRoutes()->getByName('child-identity.shares.store')->gatherMiddleware(),
        );
    }

    public function test_prebuilt_generation_card_is_reused_immediately_when_customer_enables_sharing(): void
    {
        Storage::fake('local');
        [$identity, $attempt] = $this->approvedIdentity(900, 600);
        $attempt = app(ChildIdentityShareDraftService::class)
            ->prepare($attempt);
        $draftPaths = collect(ChildIdentityShare::VARIANTS)
            ->mapWithKeys(fn (string $variant): array => [$variant => $attempt->getAttribute("share_{$variant}_card_path")]);

        $this->assertDatabaseCount('child_identity_shares', 0);
        $this->assertNotNull($attempt->share_cards_generated_at);

        $this->withSession(['child_identity_grants' => [$identity->uuid]])
            ->post(route('child-identity.shares.store', $identity->uuid), [
                'share_consent' => '1',
            ])->assertRedirect();

        $share = ChildIdentityShare::firstOrFail();
        $this->assertSame('ready', $share->status);
        $this->assertSame($attempt->share_draft_token, $share->public_token);
        foreach (ChildIdentityShare::VARIANTS as $variant) {
            $this->assertSame($draftPaths[$variant], $share->cardPath($variant));
        }
        $mediaUrl = URL::temporarySignedRoute(
            'child-identity.media.attempt',
            now()->addMinutes(5),
            ['identity' => $identity->uuid, 'attempt' => $attempt->id],
        );
        $media = $this->withSession(['child_identity_grants' => [$identity->uuid]])
            ->get($mediaUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $displayedContents = $media->streamedContent();
        $this->assertSame(
            Storage::disk('local')->get($attempt->share_feed_card_path),
            $displayedContents,
        );
        $this->assertNotSame(
            Storage::disk('local')->get($attempt->output_storage_path),
            $displayedContents,
        );
        $this->assertDatabaseHas('child_identity_share_events', [
            'child_identity_share_id' => $share->id,
            'event_type' => 'share.card_generation_succeeded',
        ]);
    }

    public function test_guest_cannot_manage_another_identity_share_and_revocation_blocks_public_media(): void
    {
        Storage::fake('local');
        [$identity] = $this->approvedIdentity();
        $share = $this->readyShare($identity);
        $other = ChildIdentityRequest::create($this->identityAttributes(['uuid' => (string) Str::uuid()]));

        $this->withSession(['child_identity_grants' => [$other->uuid]])
            ->post(route('child-identity.shares.revoke', [$identity->uuid, $share]))
            ->assertForbidden();

        $this->withSession(['child_identity_grants' => [$identity->uuid]])
            ->post(route('child-identity.shares.revoke', [$identity->uuid, $share]))
            ->assertRedirect();
        $this->get(route('child-identity-shares.show', $share->public_token))->assertStatus(410);
        $this->get(route('child-identity-shares.card', [$share->public_token, 'og']))->assertStatus(410);
        Storage::disk('local')->assertExists($share->og_card_path);
    }

    public function test_public_share_page_and_og_card_expose_only_branded_public_content(): void
    {
        Storage::fake('local');
        [$identity, $attempt] = $this->approvedIdentity();
        $share = $this->readyShare($identity);

        $page = $this->get(route('child-identity-shares.show', $share->public_token))
            ->assertOk()
            ->assertSee('اصنع هوية طفلك مجانًا')
            ->assertSee('noindex, follow', false)
            ->assertSee('/card/og', false)
            ->assertSee('width="1200" height="900"', false)
            ->assertSee('<meta property="og:image:height" content="900">', false)
            ->assertDontSee($identity->parent_name)
            ->assertDontSee($identity->parent_phone)
            ->assertDontSee($identity->parent_email)
            ->assertDontSee($identity->child_name)
            ->assertDontSee($attempt->output_storage_path);
        $this->assertStringContainsString('private', (string) $page->headers->get('Cache-Control'));
        $this->assertDatabaseHas('child_identity_share_events', [
            'child_identity_share_id' => $share->id,
            'event_type' => 'share.page_viewed',
        ]);

        $card = $this->get(route('child-identity-shares.card', [$share->public_token, 'og']))
            ->assertOk();
        $this->assertSame('image/jpeg', $card->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=300', (string) $card->headers->get('Cache-Control'));
    }

    public function test_share_card_generation_is_deterministic_non_ai_and_has_exact_dimensions(): void
    {
        Storage::fake('local');
        Http::fake();
        [$identity] = $this->approvedIdentity(900, 600);
        $share = $this->draftShare($identity);
        $paths = app(ChildIdentityShareCardGenerator::class)->generate($share, 1);

        foreach ([
            'feed' => [1200, 900],
            'story' => [1080, 1920],
            'og' => [1200, 900],
        ] as $variant => $expected) {
            Storage::disk('local')->assertExists($paths[$variant]);
            $image = new \Imagick;
            $image->readImageBlob(Storage::disk('local')->get($paths[$variant]));
            $this->assertSame($expected[0], $image->getImageWidth(), $variant);
            $this->assertSame($expected[1], $image->getImageHeight(), $variant);
            $this->assertSame('JPEG', strtoupper($image->getImageFormat()));
            $this->assertEmpty($image->getImageProperties('exif:*'));
            $image->clear();
        }
        $this->assertSame(
            hash('sha256', Storage::disk('local')->get($paths['feed'])),
            hash('sha256', Storage::disk('local')->get($paths['og'])),
        );

        $feed = new \Imagick;
        $feed->readImageBlob(Storage::disk('local')->get($paths['feed']));
        $identityCenter = $feed->getImagePixelColor(600, 495)->getColor();
        $feed->clear();

        $this->assertGreaterThan(45, $identityCenter['b'] - $identityCenter['r']);
        $this->assertGreaterThan(30, $identityCenter['b'] - $identityCenter['g']);
        Http::assertNothingSent();
    }

    public function test_opening_an_identity_refreshes_an_old_public_card_to_the_landscape_layout(): void
    {
        Storage::fake('local');
        [$identity] = $this->approvedIdentity(900, 600);
        $share = $this->readyShare($identity);
        $oldVersion = $share->generation_version;
        $oldFingerprint = $share->generated_fingerprint;

        $this->withSession(['child_identity_grants' => [$identity->uuid]])
            ->get(route('child-identity.show', $identity->uuid))
            ->assertOk();

        $share->refresh();
        $this->assertSame($oldVersion + 1, $share->generation_version);
        $this->assertSame('ready', $share->status);
        $this->assertNotSame($oldFingerprint, $share->generated_fingerprint);
        $this->assertSame($share->card_fingerprint, $share->generated_fingerprint);

        $feed = new \Imagick;
        $feed->readImageBlob(Storage::disk('local')->get($share->feed_card_path));
        $this->assertSame(1200, $feed->getImageWidth());
        $this->assertSame(900, $feed->getImageHeight());
        $feed->clear();
    }

    public function test_referral_is_first_touch_and_funnel_aggregates_are_idempotent(): void
    {
        Storage::fake('local');
        [$identity] = $this->approvedIdentity();
        $first = $this->readyShare($identity);
        [$secondIdentity] = $this->approvedIdentity();
        $second = $this->readyShare($secondIdentity);

        $request = Request::create(route('child-identity-shares.show', $first->public_token), 'GET');
        $request->setLaravelSession(app('session.store'));
        app(ChildIdentityReferralService::class)->remember($first, $request);
        app(ChildIdentityReferralService::class)->remember($second, $request);
        $this->assertSame($first->id, app(ChildIdentityReferralService::class)->resolve($request)?->id);

        $referred = ChildIdentityRequest::create($this->identityAttributes([
            'uuid' => (string) Str::uuid(),
            'referred_by_child_identity_share_id' => $first->id,
        ]));
        $events = app(ChildIdentityShareEventService::class);
        $events->recordFunnelOnce($first, 'share.identity_started', $referred);
        $events->recordFunnelOnce($first, 'share.identity_started', $referred);
        $events->recordFunnelOnce($first, 'share.identity_generated', $referred);

        $this->assertSame(1, $first->fresh()->total_identity_starts);
        $this->assertSame(1, $first->fresh()->total_identity_completions);
        $this->assertDatabaseCount('child_identity_share_events', 2);
    }

    public function test_caption_snapshots_do_not_change_when_settings_change(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$identity, $attempt] = $this->approvedIdentity();
        $request = Request::create('/', 'POST');
        $request->setLaravelSession(app('session.store'));
        Setting::updateOrCreate(['key' => 'child_identity_share_caption_ar'], [
            'value' => "النص الأول\n{share_url}",
        ]);
        $share = app(ChildIdentityShareManager::class)->createOrUpdate(
            $identity,
            $attempt,
            $request,
            false,
            true,
        );
        $snapshot = $share->caption_snapshot;
        Setting::where('key', 'child_identity_share_caption_ar')->update(['value' => "النص الثاني\n{share_url}"]);

        $this->assertSame($snapshot, $share->fresh()->caption_snapshot);
        $this->assertStringContainsString('النص الأول', $snapshot);
        $this->assertStringContainsString("\n", app(ChildIdentityShareText::class)->completeCaption($share, route('child-identity-shares.show', $share->public_token)));
    }

    public function test_channel_urls_have_correct_attribution_and_facebook_does_not_prefill_commentary(): void
    {
        Storage::fake('local');
        [$identity] = $this->approvedIdentity();
        $share = $this->readyShare($identity);
        $payload = app(ChildIdentitySharePresenter::class)->customerPayload($share);

        $this->assertStringContainsString('utm_source%3Dwhatsapp', $payload['whatsapp']);
        $this->assertStringContainsString(rawurlencode('#HeroKid'), $payload['whatsapp']);
        $this->assertStringContainsString('sharer.php?u=', $payload['facebook']);
        $this->assertStringContainsString('utm_source%3Dfacebook', $payload['facebook']);
        $this->assertStringNotContainsString('quote=', $payload['facebook']);
        $this->assertStringNotContainsString(rawurlencode($payload['caption']), $payload['facebook']);
        $this->assertStringContainsString('utm_source=copy_link', urldecode($payload['copyUrl']));
    }

    public function test_card_generation_job_is_idempotent_for_the_same_fingerprint_and_version(): void
    {
        Storage::fake('local');
        Http::fake();
        [$identity] = $this->approvedIdentity(900, 600);
        $share = $this->draftShare($identity);
        $job = new GenerateChildIdentityShareCardsJob($share->id, $share->generation_version);

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $this->assertSame('ready', $share->fresh()->status);
        $this->assertDatabaseCount('child_identity_share_events', 1);
        $this->assertDatabaseHas('child_identity_share_events', [
            'child_identity_share_id' => $share->id,
            'event_type' => 'share.card_generation_succeeded',
        ]);
        Http::assertNothingSent();
    }

    public function test_admin_share_report_permission_is_enforced_and_report_has_aggregate_metrics(): void
    {
        Storage::fake('local');
        [$identity] = $this->approvedIdentity();
        $share = $this->readyShare($identity);
        $share->forceFill([
            'total_views' => 10,
            'total_cta_clicks' => 4,
            'total_identity_starts' => 2,
        ])->save();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.child-identities.share-report'))
            ->assertOk()
            ->assertSee('تقرير مشاركة هويات الأطفال')
            ->assertSee('إجمالي المشاركات')
            ->assertDontSee($identity->parent_phone);

        $permission = Permission::where('key', 'child_identities.view_share_report')->firstOrFail();
        $admin->permissions()->detach($permission);
        $admin->unsetRelation('permissions');

        $this->actingAs($admin)
            ->get(route('admin.child-identities.share-report'))
            ->assertForbidden();
    }

    public function test_share_report_counts_a_multi_story_checkout_and_its_revenue_once(): void
    {
        Storage::fake('local');
        [$identity] = $this->approvedIdentity();
        $share = $this->readyShare($identity);
        $orders = collect([1, 2])->map(fn (int $number): Order => Order::create([
            'order_number' => 'HK-SHARE-'.$number,
            'checkout_group_key' => 'SHARED-CHECKOUT-1',
            'referred_by_child_identity_share_id' => $share->id,
            'delivery_details' => ['total' => 500],
            'uploaded_photos' => [],
            'status' => 'new',
        ]));
        foreach ($orders as $order) {
            $share->events()->create([
                'event_type' => 'share.order_created',
                'referred_order_id' => $order->id,
                'occurred_at' => now(),
            ]);
        }

        $summary = app(ChildIdentityShareReportService::class)->build()['summary'];

        $this->assertSame(1, $summary['orders']);
        $this->assertSame(500.0, $summary['revenue']);
    }

    public function test_share_caption_templates_reject_unsupported_placeholders(): void
    {
        $this->expectException(ValidationException::class);

        app(ChildIdentityShareText::class)->validateTemplate('جرب HeroKid {share_url} {parent_phone}');
    }

    private function approvedIdentity(int $width = 60, int $height = 40): array
    {
        $identity = ChildIdentityRequest::create($this->identityAttributes());
        $contents = $this->jpeg($width, $height);
        $path = 'child-identities/'.$identity->uuid.'/attempts/1/output.jpg';
        Storage::disk('local')->put($path, $contents);
        $attempt = $identity->attempts()->create([
            'attempt_number' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'initiated_by' => 'customer',
            'status' => 'succeeded',
            'provider' => 'openai',
            'model' => 'gpt-image-2',
            'prompt_version' => 'test-v1',
            'prompt_snapshot' => 'A fixed private generation prompt snapshot for automated testing.',
            'prompt_hash' => hash('sha256', 'test'),
            'input_photos_count' => 2,
            'image_size' => '1536x1024',
            'image_quality' => 'medium',
            'output_disk' => 'local',
            'output_storage_path' => $path,
            'output_checksum' => hash('sha256', $contents),
            'cost_calculation_method' => 'calculated',
            'billing_status' => 'estimated',
            'completed_at' => now(),
        ]);
        $identity->forceFill(['approved_attempt_id' => $attempt->id, 'status' => 'approved'])->save();

        return [$identity->fresh(), $attempt];
    }

    private function readyShare(ChildIdentityRequest $identity): ChildIdentityShare
    {
        $share = $this->draftShare($identity);
        foreach (ChildIdentityShare::VARIANTS as $variant) {
            $path = "child-identity-shares/{$share->id}/v1/{$variant}.jpg";
            Storage::disk('local')->put($path, $this->jpeg(20, 20));
            $share->setAttribute("{$variant}_card_path", $path);
        }
        $share->forceFill([
            'status' => 'ready',
            'share_enabled' => true,
            'cards_generated_at' => now(),
            'generated_fingerprint' => $share->card_fingerprint,
        ])->save();

        return $share->fresh();
    }

    private function draftShare(ChildIdentityRequest $identity): ChildIdentityShare
    {
        return ChildIdentityShare::create([
            'child_identity_request_id' => $identity->id,
            'generation_attempt_id' => $identity->approved_attempt_id,
            'public_token' => Str::random(64),
            'status' => 'generating',
            'share_enabled' => true,
            'display_child_first_name' => false,
            'consent_accepted_at' => now(),
            'consent_version' => 'test-v1',
            'created_by_type' => 'customer',
            'card_disk' => 'local',
            'template_version' => 'test-v1',
            'card_fingerprint' => hash('sha256', Str::random()),
            'generation_version' => 1,
            'caption_snapshot' => "شوفوا هوية طفلي\n".route('child-identity.index'),
            'hashtags_snapshot' => "#HeroKid\n#هوية_طفلك",
        ]);
    }

    private function identityAttributes(array $overrides = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(),
            'resume_token_hash' => hash('sha256', Str::random(80)),
            'parent_name' => 'مريم أحمد',
            'parent_phone' => '201001112233',
            'parent_email' => 'parent@example.com',
            'child_name' => 'ليلى محمد كامل',
            'child_age' => 5,
            'age_range' => '٣ - ٦ سنوات',
            'gender' => 'girl',
            'status' => 'incomplete',
            'consent_accepted_at' => now(),
            'consent_version' => 'test-v1',
            'last_activity_at' => now(),
        ], $overrides);
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 238, 242, 255);
        $accent = imagecolorallocate($image, 79, 70, 229);
        imagefill($image, 0, 0, $background);
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 2), (int) ($height / 2), $accent);
        ob_start();
        imagejpeg($image, null, 92);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }
}
