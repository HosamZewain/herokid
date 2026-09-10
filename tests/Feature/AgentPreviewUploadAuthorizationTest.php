<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\BookletPreview;
use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use App\Services\BookletPreviews\BookletPreviewManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Mpdf\Mpdf;
use Tests\TestCase;

class AgentPreviewUploadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_preview_upload_returns_401(): void
    {
        $order = $this->storyOrder('PREVIEW-AUTH-1', 'HK-PREVIEW-AUTH-1');

        $this->post('/api/agent/orders/'.$order->id.'/previews', $this->bookletPayload(), $this->headers('unauthenticated'))
            ->assertUnauthorized()
            ->assertJsonPath('error', 'UNAUTHORIZED');
    }

    public function test_disabled_agent_api_account_cannot_upload_preview(): void
    {
        $order = $this->storyOrder('PREVIEW-AUTH-2', 'HK-PREVIEW-AUTH-2');
        $agent = $this->agent(false);

        $this->upload($order, $this->previewToken($agent), 'disabled-agent')
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN');
    }

    public function test_token_without_preview_write_ability_cannot_upload_preview(): void
    {
        $order = $this->storyOrder('PREVIEW-AUTH-3', 'HK-PREVIEW-AUTH-3');
        $agent = $this->agent();
        $readOnlyToken = $agent->createToken('read-only-studio', [
            'agent',
            'agent:orders.read',
            'agent:catalog.stories',
        ])->plainTextToken;

        $this->upload($order, $readOnlyToken, 'missing-preview-ability')
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN');
    }

    public function test_agent_without_preview_write_application_permission_cannot_upload_preview(): void
    {
        $order = $this->storyOrder('PREVIEW-AUTH-4', 'HK-PREVIEW-AUTH-4');
        $agent = $this->agent();
        $agent->permissions()->detach(Permission::where('key', 'orders.preview.upload')->firstOrFail());

        $this->upload($order, $this->previewToken($agent), 'missing-preview-permission')
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN');
    }

    public function test_authorized_agent_uploads_story_preview_without_acquisition(): void
    {
        Storage::fake('local');
        $order = $this->storyOrder('PREVIEW-NO-ACQUIRE', 'HK-PREVIEW-NO-ACQUIRE');
        $agent = $this->agent();

        $response = $this->upload($order, $this->previewToken($agent), 'preview-without-acquisition')
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('preview.type', 'booklet');

        $preview = BookletPreview::with('currentVersion')->findOrFail($response->json('preview.preview_id'));
        $this->assertSame($order->id, $preview->order_id);
        $this->assertSame(1, $preview->currentVersion->version_number);
        $this->assertDatabaseCount('order_group_assignments', 0);
        $this->assertSame('new', $order->refresh()->status);
        $activity = AdminActivityLog::query()->where('action', 'agent.order_preview_uploaded')->firstOrFail();
        $this->assertSame($order->id, $activity->subject_id);
        $this->assertSame($agent->id, $activity->user_id);
        $this->assertSame(
            hash('sha256', 'preview-without-acquisition'),
            $activity->properties['request_identifier'],
        );
    }

    public function test_authorized_agent_uploads_when_checkout_is_acquired_by_another_agent(): void
    {
        Storage::fake('local');
        $order = $this->storyOrder('PREVIEW-OTHER-OWNER', 'HK-PREVIEW-OTHER-OWNER');
        $owner = $this->agent();
        $uploader = $this->agent();
        $assignment = $this->assignment($order, $owner);

        $this->upload($order, $this->previewToken($uploader), 'preview-other-owner')
            ->assertCreated();

        $this->assertSame($owner->id, $assignment->fresh()->assigned_to_user_id);
        $this->assertDatabaseHas('booklet_previews', ['order_id' => $order->id]);
    }

    public function test_booklet_upload_is_scoped_to_the_selected_story_in_a_multi_story_checkout(): void
    {
        Storage::fake('local');
        $first = $this->storyOrder('MULTI-STORY-PREVIEW', 'HK-MULTI-STORY-A');
        $second = $this->storyOrder('MULTI-STORY-PREVIEW', 'HK-MULTI-STORY-B');
        $agent = $this->agent();
        $manager = app(BookletPreviewManager::class);
        $siblingPreview = $manager->createOrReplaceForOrder($second, $this->validPdfUpload('sibling.pdf'), null, $agent);
        $siblingVersionId = $siblingPreview->current_version_id;

        $response = $this->upload($first, $this->previewToken($agent), 'multi-story-first')
            ->assertCreated();

        $firstPreviewId = $response->json('preview.preview_id');
        $this->assertNotSame($siblingPreview->id, $firstPreviewId);
        $this->assertDatabaseCount('booklet_previews', 2);
        $this->assertDatabaseHas('booklet_previews', ['id' => $firstPreviewId, 'order_id' => $first->id]);
        $this->assertDatabaseHas('booklet_previews', [
            'id' => $siblingPreview->id,
            'order_id' => $second->id,
            'current_version_id' => $siblingVersionId,
        ]);
        $this->assertSame(1, $siblingPreview->versions()->count());
    }

    public function test_repeated_idempotency_key_does_not_create_another_preview_or_file(): void
    {
        Storage::fake('local');
        $order = $this->storyOrder('PREVIEW-IDEMPOTENT', 'HK-PREVIEW-IDEMPOTENT');
        $token = $this->previewToken($this->agent());
        $file = $this->validPdfUpload('idempotent.pdf');
        $payload = $this->bookletPayload($file);
        $headers = $this->headers('same-preview-key', $token);

        $first = $this->post('/api/agent/orders/'.$order->id.'/previews', $payload, $headers)
            ->assertCreated();
        $second = $this->post('/api/agent/orders/'.$order->id.'/previews', $payload, $headers)
            ->assertCreated();

        $this->assertSame($first->json('preview.preview_id'), $second->json('preview.preview_id'));
        $this->assertDatabaseCount('booklet_previews', 1);
        $this->assertDatabaseCount('booklet_preview_versions', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('booklet-previews'));
    }

    public function test_new_idempotency_key_replaces_the_story_preview_with_a_new_version(): void
    {
        Storage::fake('local');
        $order = $this->storyOrder('PREVIEW-REPLACE', 'HK-PREVIEW-REPLACE');
        $token = $this->previewToken($this->agent());

        $first = $this->upload($order, $token, 'preview-version-1', 'first.pdf')->assertCreated();
        $second = $this->upload($order, $token, 'preview-version-2', 'second.pdf')->assertCreated();

        $this->assertSame($first->json('preview.preview_id'), $second->json('preview.preview_id'));
        $this->assertSame(2, $second->json('preview.version'));
        $this->assertDatabaseCount('booklet_previews', 1);
        $this->assertDatabaseCount('booklet_preview_versions', 2);
    }

    public function test_invalid_pdf_is_rejected_without_creating_a_preview(): void
    {
        Storage::fake('local');
        $order = $this->storyOrder('PREVIEW-INVALID', 'HK-PREVIEW-INVALID');

        $this->post('/api/agent/orders/'.$order->id.'/previews', [
            'type' => 'booklet',
            'preview_files' => [$this->invalidPdfUpload()],
        ], $this->headers('invalid-preview', $this->previewToken($this->agent())))
            ->assertUnprocessable()
            ->assertJsonPath('error', 'INVALID_ATTACHMENT');

        $this->assertDatabaseCount('booklet_previews', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('booklet-previews'));
    }

    public function test_unknown_story_order_returns_404(): void
    {
        $this->post('/api/agent/orders/999999/previews', $this->bookletPayload(), $this->headers(
            'unknown-preview-order',
            $this->previewToken($this->agent()),
        ))->assertNotFound()->assertJsonPath('error', 'ORDER_NOT_FOUND');
    }

    public function test_existing_acquired_agent_can_still_upload_preview(): void
    {
        Storage::fake('local');
        $order = $this->storyOrder('PREVIEW-SAME-OWNER', 'HK-PREVIEW-SAME-OWNER');
        $agent = $this->agent();
        $this->assignment($order, $agent);

        $this->upload($order, $this->previewToken($agent), 'preview-same-owner')
            ->assertCreated();

        $this->assertDatabaseHas('booklet_previews', ['order_id' => $order->id]);
    }

    public function test_attachment_upload_still_requires_checkout_acquisition(): void
    {
        $order = $this->storyOrder('ATTACHMENT-STILL-LOCKED', 'HK-ATTACHMENT-STILL-LOCKED');
        $agent = $this->agent();
        $token = $agent->createToken('attachment-writer', [
            'agent',
            'agent:orders.upload-attachment',
            'agent:catalog.stories',
        ])->plainTextToken;

        $this->post('/api/agent/orders/'.$order->id.'/attachments', [
            'attachments' => [UploadedFile::fake()->create('production.pdf', 10, 'application/pdf')],
        ], $this->headers('attachment-without-acquisition', $token))
            ->assertForbidden()
            ->assertJsonPath('error', 'ORDER_NOT_ACQUIRED_BY_AGENT');
    }

    public function test_current_studio_multipart_contract_is_accepted(): void
    {
        Storage::fake('local');
        $order = $this->storyOrder('STUDIO-MULTIPART', 'HK-STUDIO-MULTIPART');

        $this->post('/api/agent/orders/'.$order->id.'/previews', [
            'type' => 'booklet',
            'preview_files' => [$this->validPdfUpload('studio-preview.pdf')],
        ], [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->previewToken($this->agent()),
            'Idempotency-Key' => 'studio-current-contract',
        ])->assertCreated()
            ->assertJsonPath('preview.type', 'booklet')
            ->assertJsonPath('preview.version', 1);
    }

    public function test_story_preview_upload_still_respects_token_catalog_scope(): void
    {
        $order = $this->storyOrder('PREVIEW-SCOPE', 'HK-PREVIEW-SCOPE');
        $agent = $this->agent();
        $productsOnlyToken = $agent->createToken('products-preview-writer', [
            'agent',
            'agent:orders.upload-preview',
            'agent:catalog.products',
        ])->plainTextToken;

        $this->upload($order, $productsOnlyToken, 'story-preview-with-product-scope')
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN');
    }

    public function test_issued_production_token_contains_existing_preview_write_capabilities(): void
    {
        $manager = $this->agent();
        $agent = $this->agent(false);

        $this->actingAs($manager)->post(route('admin.agent-api-tokens.store'), [
            'agent_user_id' => $agent->id,
            'name' => 'studio-preview-writer',
            'expires_in_days' => 30,
            'catalog_scope' => 'stories',
        ])->assertRedirect(route('admin.agent-api-tokens.index'));

        $token = PersonalAccessToken::query()->where('name', 'studio-preview-writer')->firstOrFail();
        $this->assertContains('agent:orders.upload-preview', $token->abilities);
        $this->assertTrue($agent->refresh()->hasPermission('orders.preview.upload'));
    }

    private function upload(Order $order, string $token, string $idempotencyKey, string $filename = 'preview.pdf')
    {
        return $this->post(
            '/api/agent/orders/'.$order->id.'/previews',
            $this->bookletPayload($this->validPdfUpload($filename)),
            $this->headers($idempotencyKey, $token),
        );
    }

    /** @return array<string, mixed> */
    private function bookletPayload(?UploadedFile $file = null): array
    {
        return [
            'type' => 'booklet',
            'preview_files' => [$file ?? $this->validPdfUpload()],
        ];
    }

    /** @return array<string, string> */
    private function headers(string $idempotencyKey, ?string $token = null): array
    {
        return array_filter([
            'Accept' => 'application/json',
            'Authorization' => $token ? 'Bearer '.$token : null,
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    private function agent(bool $enabled = true): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'agent_api_enabled' => $enabled,
        ]);
    }

    private function previewToken(User $agent): string
    {
        return $agent->createToken('studio-preview', [
            'agent',
            'agent:orders.upload-preview',
            'agent:catalog.stories',
        ])->plainTextToken;
    }

    private function storyOrder(string $group, string $number): Order
    {
        $story = Story::create([
            'title' => 'قصة '.$number,
            'slug' => strtolower($number),
            'language' => 'ar',
            'gender' => 'both',
            'price' => 349,
            'active' => true,
        ]);

        return Order::create([
            'order_number' => $number,
            'checkout_group_key' => $group,
            'story_id' => $story->id,
            'parent_name' => 'ولي أمر',
            'child_name' => 'مريم',
            'child_age' => 7,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => ['checkout_group' => $group],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
    }

    private function assignment(Order $order, User $owner): OrderGroupAssignment
    {
        return OrderGroupAssignment::create([
            'checkout_group_key' => $order->checkoutGroupKey(),
            'assigned_to_user_id' => $owner->id,
            'assigned_by_user_id' => $owner->id,
            'assigned_at' => now(),
        ]);
    }

    private function validPdfUpload(string $name = 'preview.pdf'): UploadedFile
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        $pdf->WriteHTML('<h1>HeroKid Studio Preview</h1><p>Authorized story preview.</p>');
        $path = tempnam(sys_get_temp_dir(), 'herokid-agent-preview-');
        file_put_contents($path, $pdf->Output('', 'S'));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function invalidPdfUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'herokid-invalid-preview-');
        file_put_contents($path, '%PDF-this-is-not-a-valid-document');

        return new UploadedFile($path, 'invalid.pdf', 'application/pdf', null, true);
    }
}
