<?php

namespace Tests\Feature;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityPhoto;
use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\Permission;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\RoboDesk\RoboDeskIntegrationRegistry;
use App\Services\RoboDesk\RoboDeskSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Identity Confirmation integration: same three fields, same webhook shape,
 * triggered when a generated identity is still waiting on the parent.
 */
class RoboDeskIdentityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://robodesk.test/conversation/start/sendMsg';

    // ── Happy paths ──────────────────────────────────────────────────────

    public function test_an_undecided_identity_posts_the_configured_payload(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);
        $this->enable();
        $this->configure('{"to":"{{ customer_phone }}","child":"{{ child_name }}","image":"{{ identity_url }}"}');

        [$identity, $attempt] = $this->generatedIdentity();

        $event = $this->eventFor($identity);
        $this->assertSame('succeeded', $event->status);

        Http::assertSent(function ($request): bool {
            return $request->url() === self::URL
                && $request['to'] === '201501188884'
                && $request['child'] === 'ليلى'
                // A signed, expiring link rather than a raw storage path.
                && str_contains((string) $request['image'], 'signature=')
                && str_contains((string) $request['image'], 'expires=');
        });

        $this->assertSame($attempt->id, (int) data_get($event->payload, 'attempt_id', $attempt->id));
    }

    public function test_it_parks_a_linked_order_at_identity_pending_confirmation(): void
    {
        Http::fake([self::URL => Http::response([], 200)]);
        $this->enable();
        $this->configure('');

        $order = $this->order('CHK-ID-1');
        [$identity] = $this->generatedIdentity($order);

        $this->assertSame('identity_pending_confirmation', $order->refresh()->status);
        $this->assertSame('CHK-ID-1', $this->eventFor($identity)->checkout_group_key);
    }

    public function test_approval_from_robodesk_approves_the_attempt_and_releases_the_order(): void
    {
        Http::fake([self::URL => Http::response([], 200)]);
        $this->enable();
        $this->configure('');

        $order = $this->order('CHK-ID-2');
        [$identity, $attempt] = $this->generatedIdentity($order);

        $this->sendCallback('identity.approved', [
            'identity_uuid' => $identity->uuid,
            'order_id' => $order->id,
        ])->assertAccepted();

        $this->assertSame($attempt->id, $identity->refresh()->approved_attempt_id);
        $this->assertSame('new', $order->refresh()->status);
    }

    public function test_a_change_request_feeds_the_comment_into_the_prompt_and_regenerates(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->enable();
        $this->configure('');

        [$identity] = $this->generatedIdentity();

        $this->sendCallback('identity.changes_requested', [
            'identity_uuid' => $identity->uuid,
            'comment' => 'الشعر أفتح من الحقيقة',
        ])->assertAccepted();

        $identity->refresh();
        $this->assertStringContainsString('الشعر أفتح من الحقيقة', (string) $identity->prompt_override);
        $this->assertSame(1, $identity->events()->where('event_type', 'identity.revision_requested')->count());

        // Queued as `robodesk`, which both bypasses the customer attempt cap and
        // keeps the retry out of the auto-approval path.
        $this->assertDatabaseHas('child_identity_generation_attempts', [
            'child_identity_request_id' => $identity->id,
            'initiated_by' => 'robodesk',
        ]);
    }

    public function test_the_screen_renders_with_its_own_variables_and_webhook(): void
    {
        $this->enable();
        $this->configure('');

        $this->actingAs($this->admin())
            ->get(route('admin.robodesk.settings.edit', RoboDeskIntegrationRegistry::IDENTITY_CONFIRMATION))
            ->assertOk()
            ->assertSee('{{ child_name }}', false)
            ->assertSee('{{ identity_url }}', false)
            ->assertSee('identity.approved')
            ->assertSee('identity.changes_requested')
            ->assertSee('/api/integrations/robodesk/v1/events', false);
    }

    public function test_both_integrations_are_listed_and_configured_independently(): void
    {
        $this->enable();
        $this->configure('');

        $this->actingAs($this->admin())
            ->get(route('admin.robodesk.settings.index'))
            ->assertOk()
            ->assertSee('تأكيد الطلب')
            ->assertSee('اعتماد هوية الطفل');

        $registry = app(RoboDeskIntegrationRegistry::class);
        $this->assertTrue($registry->identityConfirmation()->enabled());
        $this->assertFalse($registry->orderConfirmation()->enabled());
    }

    public function test_the_screen_shows_a_starter_payload_and_the_attachment_endpoint(): void
    {
        $this->enable();
        $this->configure('');

        $this->actingAs($this->admin())
            ->get(route('admin.robodesk.settings.edit', RoboDeskIntegrationRegistry::IDENTITY_CONFIRMATION))
            ->assertOk()
            // RoboDesk's own keys are visible before anything is saved.
            ->assertSee('procedureId', false)
            ->assertSee('attachments', false)
            // The parent's payment proof comes back as a file upload.
            ->assertSee('/api/integrations/robodesk/v1/payment-proofs', false)
            ->assertSee('multipart/form-data', false);
    }

    // ── Common exceptions ────────────────────────────────────────────────

    public function test_an_auto_approved_identity_is_never_sent_for_confirmation(): void
    {
        Http::fake();
        $this->enable();
        $this->configure('');

        [$identity, $attempt] = $this->generatedIdentity(autoApproved: true);

        $this->assertDatabaseMissing('robodesk_integration_events', [
            'event_type' => RoboDeskIntegrationRegistry::IDENTITY_CONFIRMATION,
        ]);
        Http::assertNothingSent();
    }

    public function test_a_disabled_identity_integration_sends_nothing(): void
    {
        Http::fake();
        app(RoboDeskSettings::class)->save(['robodesk_enabled' => '1']);

        [$identity] = $this->generatedIdentity();

        $this->assertDatabaseMissing('robodesk_integration_events', [
            'event_type' => RoboDeskIntegrationRegistry::IDENTITY_CONFIRMATION,
        ]);
        Http::assertNothingSent();
    }

    public function test_a_change_request_without_a_comment_is_rejected(): void
    {
        Http::fake();
        $this->enable();
        $this->configure('');
        [$identity] = $this->generatedIdentity();

        $this->sendCallback('identity.changes_requested', ['identity_uuid' => $identity->uuid])
            ->assertStatus(422);
    }

    public function test_a_callback_for_an_unknown_identity_is_rejected(): void
    {
        Http::fake();
        $this->enable();
        $this->configure('');

        $this->sendCallback('identity.approved', ['identity_uuid' => (string) Str::uuid()])
            ->assertStatus(422);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function sendCallback(string $type, array $data)
    {
        return $this->withHeader('X-RoboDesk-Token', 'static-token')
            ->postJson('/api/integrations/robodesk/v1/events', [
                'id' => (string) Str::uuid(),
                'type' => $type,
                'data' => $data,
            ]);
    }

    private function enable(): void
    {
        app(RoboDeskSettings::class)->save([
            'robodesk_enabled' => '1',
            'robodesk_gate_identity_confirmation' => '1',
        ]);
    }

    private function configure(string $payload): void
    {
        app(RoboDeskIntegrationRegistry::class)->save(
            RoboDeskIntegrationRegistry::IDENTITY_CONFIRMATION,
            true,
            self::URL,
            'static-token',
            $payload,
        );
    }

    /** @return array{0: ChildIdentityRequest, 1: ChildIdentityGenerationAttempt} */
    private function generatedIdentity(?Order $order = null, bool $autoApproved = false): array
    {
        $identity = ChildIdentityRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'parent_name' => 'ولي الأمر',
            'parent_phone' => '01501188884',
            'child_name' => 'ليلى',
            'gender' => 'female',
            'child_age' => 5,
            'age_range' => '4-6',
            'resume_token_hash' => hash('sha256', Str::random(32)),
            'consent_accepted_at' => now(),
            'consent_version' => 'v1',
            'status' => 'queued',
            'converted_order_id' => $order?->id,
        ]);

        // A revision re-generates, and generation requires valid source photos.
        foreach (range(1, 2) as $index) {
            ChildIdentityPhoto::query()->create([
                'child_identity_request_id' => $identity->id,
                'disk' => 'local',
                'path' => 'identities/'.$identity->uuid.'/photo-'.$index.'.jpg',
                'original_filename' => 'photo-'.$index.'.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 1024,
                'checksum' => hash('sha256', 'photo-'.$index),
                'sort_order' => $index,
                'upload_status' => 'uploaded',
                'validation_status' => 'valid',
            ]);
        }

        $attempt = ChildIdentityGenerationAttempt::query()->create([
            'child_identity_request_id' => $identity->id,
            'idempotency_key' => (string) Str::uuid(),
            'attempt_number' => 1,
            'status' => 'pending',
            'initiated_by' => 'customer',
            'provider' => 'openai',
            'model' => 'gpt-image-2',
            'prompt_version' => 'v1',
            'prompt_snapshot' => 'prompt',
            'prompt_hash' => str_repeat('a', 64),
            'input_photos_count' => 2,
            'image_size' => '1536x1024',
            'image_quality' => 'medium',
        ]);

        if ($autoApproved) {
            $identity->forceFill(['approved_attempt_id' => $attempt->id, 'status' => 'approved'])->save();
        }

        // Succeeding the attempt is what the trigger listens for.
        $attempt->forceFill([
            'status' => 'succeeded',
            'output_storage_path' => 'identities/'.$identity->uuid.'.png',
            'output_disk' => 'local',
            'completed_at' => now(),
        ])->save();

        return [$identity->refresh(), $attempt->refresh()];
    }

    private function eventFor(ChildIdentityRequest $identity): RoboDeskIntegrationEvent
    {
        return RoboDeskIntegrationEvent::query()
            ->where('event_type', RoboDeskIntegrationRegistry::IDENTITY_CONFIRMATION)
            ->where('deduplication_key', 'like', '%'.$identity->uuid.'%')
            ->firstOrFail();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->permissions()->sync(
            Permission::query()->whereIn('key', ['robodesk.configure', 'robodesk.manage_credentials', 'robodesk.view'])->pluck('id')
        );
        $admin->unsetRelation('permissions');

        return $admin;
    }

    private function order(string $checkout): Order
    {
        return Order::query()->create([
            'order_number' => 'HK-'.Str::upper(Str::random(10)),
            'checkout_group_key' => $checkout,
            'parent_name' => 'ولي الأمر',
            'status' => 'new',
            'uploaded_photos' => [],
            'delivery_details' => [
                'phone' => '01501188884',
                'country' => 'مصر',
                'governorate' => 'القاهرة',
                'city' => 'مدينة نصر',
                'street' => 'شارع 1',
                'address_details' => 'الدور الثالث',
                'delivery_fee' => 50,
            ],
        ]);
    }
}
