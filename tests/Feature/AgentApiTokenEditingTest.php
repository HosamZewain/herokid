<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentApi\AgentCatalogScope;
use App\Services\AgentApi\AgentProductScope;
use App\Services\AgentApi\AgentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Mpdf\Mpdf;
use Tests\TestCase;

class AgentApiTokenEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_existing_token_name_without_changing_its_hash(): void
    {
        [$plainTextToken, $token] = $this->token($this->agent(), ['agent:orders.read']);
        $originalHash = $token->token;

        $this->updateToken($this->manager(), $token, ['name' => 'renamed-studio-token'])
            ->assertRedirect(route('admin.agent-api-tokens.edit', $token->id));

        $token->refresh();
        $this->assertSame('renamed-studio-token', $token->name);
        $this->assertSame($originalHash, $token->token);
        $this->assertNotSame($plainTextToken, $token->token);
    }

    public function test_admin_can_add_an_allowlisted_ability(): void
    {
        [, $token] = $this->token($this->agent(), ['agent:orders.read']);

        $this->updateToken($this->manager(), $token, [
            'abilities' => ['agent:orders.read', 'agent:orders.upload-preview'],
        ])->assertRedirect();

        $this->assertContains('agent:orders.upload-preview', $token->refresh()->abilities);
    }

    public function test_admin_can_remove_an_ability(): void
    {
        [, $token] = $this->token($this->agent(), [
            'agent:orders.read',
            'agent:orders.upload-preview',
        ]);

        $this->updateToken($this->manager(), $token, ['abilities' => ['agent:orders.read']])
            ->assertRedirect();

        $this->assertNotContains('agent:orders.upload-preview', $token->refresh()->abilities);
    }

    public function test_unknown_ability_is_rejected(): void
    {
        [, $token] = $this->token($this->agent(), ['agent:orders.read']);

        $this->updateToken($this->manager(), $token, [
            'abilities' => ['agent:orders.read', 'agent:orders.super-admin'],
        ])->assertSessionHasErrors('abilities.1');

        $this->assertNotContains('agent:orders.super-admin', $token->refresh()->abilities);
    }

    public function test_base_agent_ability_is_always_retained(): void
    {
        [, $token] = $this->token($this->agent(), ['agent:orders.read']);

        $this->updateToken($this->manager(), $token, ['abilities' => []])
            ->assertRedirect();

        $this->assertContains('agent', $token->refresh()->abilities);
        $this->assertNotContains('agent:orders.read', $token->abilities);
    }

    public function test_same_token_value_remains_valid_immediately_after_edit(): void
    {
        [$plainTextToken, $token] = $this->token($this->agent(), ['agent:orders.read']);

        $this->usingToken($plainTextToken)->getJson('/api/agent/studio/connection')->assertOk();

        $this->updateToken($this->manager(), $token, ['name' => 'same-secret-new-name'])
            ->assertRedirect();

        $this->usingToken($plainTextToken)->getJson('/api/agent/studio/connection')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_same_token_gains_preview_upload_immediately_after_ability_is_added(): void
    {
        Storage::fake('local');
        $agent = $this->agent();
        [$plainTextToken, $token] = $this->token($agent, ['agent:orders.read']);
        $order = $this->storyOrder('TOKEN-GAINS-PREVIEW', 'HK-TOKEN-GAINS-PREVIEW');

        $this->uploadPreview($order, $plainTextToken, 'before-preview-grant')
            ->assertForbidden();

        $this->updateToken($this->manager(), $token, [
            'abilities' => ['agent:orders.read', 'agent:orders.upload-preview'],
        ])->assertRedirect();

        $this->uploadPreview($order, $plainTextToken, 'after-preview-grant')
            ->assertCreated()
            ->assertJsonPath('preview.type', 'booklet');
        $this->assertDatabaseCount('order_group_assignments', 0);
        $this->assertDatabaseHas('booklet_previews', ['order_id' => $order->id]);
    }

    public function test_same_token_loses_preview_upload_immediately_after_ability_is_removed(): void
    {
        Storage::fake('local');
        [$plainTextToken, $token] = $this->token($this->agent(), [
            'agent:orders.read',
            'agent:orders.upload-preview',
        ]);
        $first = $this->storyOrder('TOKEN-LOSES-PREVIEW-A', 'HK-TOKEN-LOSES-PREVIEW-A');
        $second = $this->storyOrder('TOKEN-LOSES-PREVIEW-B', 'HK-TOKEN-LOSES-PREVIEW-B');

        $this->uploadPreview($first, $plainTextToken, 'before-preview-removal')->assertCreated();

        $this->updateToken($this->manager(), $token, ['abilities' => ['agent:orders.read']])
            ->assertRedirect();

        $this->uploadPreview($second, $plainTextToken, 'after-preview-removal')
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN');
        $this->assertDatabaseMissing('booklet_previews', ['order_id' => $second->id]);
    }

    public function test_admin_can_reduce_token_to_read_only_and_mutations_return_403(): void
    {
        Storage::fake('local');
        [$plainTextToken, $token] = $this->token($this->agent(), AgentTokenService::defaultOperationAbilities());
        $order = $this->storyOrder('READ-ONLY-TOKEN', 'HK-READ-ONLY-TOKEN');

        $this->updateToken($this->manager(), $token, ['abilities' => ['agent:orders.read']])
            ->assertRedirect();

        $this->uploadPreview($order, $plainTextToken, 'read-only-preview')->assertForbidden();
        $this->usingToken($plainTextToken)->post('/api/agent/orders/'.$order->id.'/attachments', [
            'attachments' => [UploadedFile::fake()->create('production.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json', 'Idempotency-Key' => 'read-only-attachment'])
            ->assertForbidden();
        $this->usingToken($plainTextToken)->getJson('/api/agent/studio/connection')->assertOk();
    }

    public function test_edit_page_does_not_reveal_raw_token_or_stored_hash(): void
    {
        [$plainTextToken, $token] = $this->token($this->agent(), ['agent:orders.read']);

        $this->actingAs($this->manager())
            ->get(route('admin.agent-api-tokens.edit', $token->id))
            ->assertOk()
            ->assertDontSee($plainTextToken)
            ->assertDontSee($token->token)
            ->assertSee('قيمة التوكن السرية غير قابلة للعرض أو الاسترجاع');
    }

    public function test_token_owner_cannot_be_changed(): void
    {
        $owner = $this->agent();
        $other = $this->agent();
        [, $token] = $this->token($owner, ['agent:orders.read']);

        $this->updateToken($this->manager(), $token, ['agent_user_id' => $other->id])
            ->assertSessionHasErrors('agent_user_id');

        $this->assertSame($owner->id, $token->refresh()->tokenable_id);
    }

    public function test_catalog_scope_can_be_changed(): void
    {
        [, $token] = $this->token($this->agent(), ['agent:orders.read'], AgentCatalogScope::ALL);

        $this->updateToken($this->manager(), $token, ['catalog_scope' => AgentCatalogScope::STORIES])
            ->assertRedirect();

        $this->assertSame(AgentCatalogScope::STORIES, AgentCatalogScope::fromAbilities($token->refresh()->abilities));
        $this->assertContains('agent:catalog.stories', $token->abilities);
        $this->assertNotContains('agent:catalog.products', $token->abilities);
    }

    public function test_selected_product_restrictions_are_replaced_using_existing_scope_abilities(): void
    {
        $first = $this->product('token-product-first');
        $second = $this->product('token-product-second');
        [, $token] = $this->token(
            $this->agent(),
            ['agent:orders.read'],
            AgentCatalogScope::PRODUCTS,
            [$first->id],
        );

        $this->updateToken($this->manager(), $token, [
            'catalog_scope' => AgentCatalogScope::PRODUCTS,
            'restrict_products' => true,
            'product_ids' => [$second->id],
        ])->assertRedirect();

        $this->assertSame([$second->id], AgentProductScope::productIdsFromAbilities($token->refresh()->abilities));
    }

    public function test_existing_selected_product_remains_visible_when_it_is_no_longer_active(): void
    {
        $product = $this->product('token-product-inactive');
        [, $token] = $this->token(
            $this->agent(),
            ['agent:orders.read'],
            AgentCatalogScope::PRODUCTS,
            [$product->id],
        );
        $product->update(['is_active' => false]);

        $this->actingAs($this->manager())
            ->get(route('admin.agent-api-tokens.edit', $token->id))
            ->assertOk()
            ->assertSee('منتج token-product-inactive')
            ->assertSee('value="'.$product->id.'" checked', false);
    }

    public function test_rework_permission_pair_can_be_enabled_and_disabled(): void
    {
        [, $token] = $this->token($this->agent(), ['agent:orders.read']);

        $this->updateToken($this->manager(), $token, ['allow_rework' => true])->assertRedirect();
        foreach (AgentTokenService::reworkAbilities() as $ability) {
            $this->assertContains($ability, $token->refresh()->abilities);
        }

        $this->updateToken($this->manager(), $token, ['allow_rework' => false])->assertRedirect();
        foreach (AgentTokenService::reworkAbilities() as $ability) {
            $this->assertNotContains($ability, $token->refresh()->abilities);
        }
    }

    public function test_expiry_can_be_extended(): void
    {
        [, $token] = $this->token($this->agent(), ['agent:orders.read'], expiresAt: now()->addDays(10));
        $extended = now()->addDays(100)->startOfMinute();

        $this->updateToken($this->manager(), $token, ['expires_at' => $extended->format('Y-m-d H:i:s')])
            ->assertRedirect();

        $this->assertSame($extended->timestamp, $token->refresh()->expires_at->timestamp);
    }

    public function test_expiry_can_be_shortened(): void
    {
        [, $token] = $this->token($this->agent(), ['agent:orders.read'], expiresAt: now()->addDays(100));
        $shortened = now()->addDays(5)->startOfMinute();

        $this->updateToken($this->manager(), $token, ['expires_at' => $shortened->format('Y-m-d H:i:s')])
            ->assertRedirect();

        $this->assertSame($shortened->timestamp, $token->refresh()->expires_at->timestamp);
    }

    public function test_revoked_token_cannot_be_edited(): void
    {
        [, $token] = $this->token($this->agent(), ['agent:orders.read']);
        $tokenId = $token->id;
        $manager = $this->manager();

        $this->actingAs($manager)->delete(route('admin.agent-api-tokens.destroy', $tokenId))->assertRedirect();
        $this->actingAs($manager)->get('/admin/agent-api-tokens/'.$tokenId.'/edit')->assertNotFound();
        $this->actingAs($manager)->patch('/admin/agent-api-tokens/'.$tokenId, [])->assertNotFound();
    }

    public function test_unauthorized_admin_and_customer_cannot_edit_tokens(): void
    {
        [, $token] = $this->token($this->agent(), ['agent:orders.read']);
        $limitedAdmin = $this->manager();
        $limitedAdmin->permissions()->sync([]);

        $this->actingAs($limitedAdmin)->get(route('admin.agent-api-tokens.edit', $token->id))->assertForbidden();
        $this->actingAs($limitedAdmin)->patch(route('admin.agent-api-tokens.update', $token->id), $this->payload($token))->assertForbidden();

        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->get(route('admin.agent-api-tokens.edit', $token->id))->assertForbidden();
    }

    public function test_audit_log_records_before_and_after_ability_scope_and_expiry_without_secret(): void
    {
        [$plainTextToken, $token] = $this->token($this->agent(), ['agent:orders.read']);
        $oldExpiry = $token->expires_at->toIso8601String();
        $newExpiry = now()->addDays(60)->startOfMinute();
        $manager = $this->manager();

        $this->updateToken($manager, $token, [
            'abilities' => ['agent:orders.read', 'agent:orders.upload-preview'],
            'catalog_scope' => AgentCatalogScope::STORIES,
            'expires_at' => $newExpiry->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $log = AdminActivityLog::query()->where('action', 'agent_api.token_updated')->firstOrFail();
        $this->assertSame($manager->id, $log->user_id);
        $this->assertSame($token->tokenable_id, $log->properties['agent_user_id']);
        $this->assertSame($token->id, $log->properties['credential_record_id']);
        $this->assertSame($oldExpiry, $log->properties['previous']['expires_at']);
        $this->assertSame(AgentCatalogScope::STORIES, $log->properties['new']['catalog_scope']);
        $this->assertContains('agent:orders.upload-preview', $log->properties['new']['abilities']);
        $serialized = json_encode($log->properties);
        $this->assertStringNotContainsString($plainTextToken, $serialized);
        $this->assertStringNotContainsString($token->token, $serialized);
    }

    public function test_edit_page_shows_account_permission_status_and_update_does_not_grant_it(): void
    {
        $agent = $this->agent();
        $agent->permissions()->detach(Permission::where('key', 'orders.preview.upload')->firstOrFail());
        [, $token] = $this->token($agent, ['agent:orders.read', 'agent:orders.upload-preview']);

        $this->actingAs($this->manager())
            ->get(route('admin.agent-api-tokens.edit', $token->id))
            ->assertOk()
            ->assertSee('orders.preview.upload')
            ->assertSee('الحساب يفتقد');

        $this->updateToken($this->manager(), $token, [
            'abilities' => ['agent:orders.read', 'agent:orders.upload-preview'],
        ])->assertRedirect();

        $this->assertFalse($agent->refresh()->hasPermission('orders.preview.upload'));
    }

    public function test_existing_token_creation_flow_still_issues_standard_abilities(): void
    {
        $agent = $this->agent(false);

        $this->actingAs($this->manager())->post(route('admin.agent-api-tokens.store'), [
            'agent_user_id' => $agent->id,
            'name' => 'new-production-token',
            'expires_in_days' => 30,
            'catalog_scope' => AgentCatalogScope::ALL,
        ])->assertRedirect(route('admin.agent-api-tokens.index'));

        $token = PersonalAccessToken::query()->where('name', 'new-production-token')->firstOrFail();
        foreach (AgentTokenService::defaultOperationAbilities() as $ability) {
            $this->assertContains($ability, $token->abilities);
        }
        $this->assertContains('agent', $token->abilities);
    }

    public function test_existing_revoke_flow_still_deletes_the_token(): void
    {
        [, $token] = $this->token($this->agent(), ['agent:orders.read']);

        $this->actingAs($this->manager())
            ->delete(route('admin.agent-api-tokens.destroy', $token->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    /** @param array<string, mixed> $overrides */
    private function updateToken(User $manager, PersonalAccessToken $token, array $overrides = [])
    {
        return $this->actingAs($manager)
            ->from(route('admin.agent-api-tokens.edit', $token->id))
            ->patch(route('admin.agent-api-tokens.update', $token->id), $this->payload($token, $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function payload(PersonalAccessToken $token, array $overrides = []): array
    {
        $configuration = app(AgentTokenService::class)->configuration($token->fresh());

        return array_replace([
            'name' => $configuration['name'],
            'abilities' => array_values(array_intersect(
                AgentTokenService::editableOperationAbilities(),
                $configuration['abilities'],
            )),
            'catalog_scope' => $configuration['catalog_scope'],
            'restrict_products' => $configuration['restrict_products'],
            'product_ids' => $configuration['product_ids'],
            'allow_rework' => $configuration['allow_rework'],
            'identity_only' => $configuration['identity_only'],
            'expires_at' => $token->expires_at->format('Y-m-d H:i:s'),
        ], $overrides);
    }

    /**
     * @param  array<int, string>  $operationAbilities
     * @param  array<int, int>  $productIds
     * @return array{0: string, 1: PersonalAccessToken}
     */
    private function token(
        User $agent,
        array $operationAbilities,
        string $scope = AgentCatalogScope::STORIES,
        array $productIds = [],
        $expiresAt = null,
    ): array {
        $issued = $agent->createToken('existing-studio-token', [
            'agent',
            ...$operationAbilities,
            ...AgentCatalogScope::abilities($scope),
            ...AgentProductScope::abilities($productIds),
        ], $expiresAt ?? now()->addDays(30));

        return [$issued->plainTextToken, $issued->accessToken];
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function agent(bool $enabled = true): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'agent_api_enabled' => $enabled,
        ]);
    }

    private function usingToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function uploadPreview(Order $order, string $token, string $idempotencyKey)
    {
        return $this->usingToken($token)->post('/api/agent/orders/'.$order->id.'/previews', [
            'type' => 'booklet',
            'preview_files' => [$this->validPdfUpload()],
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => $idempotencyKey,
        ]);
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

    private function product(string $slug): Product
    {
        return Product::create([
            'name_ar' => 'منتج '.$slug,
            'slug' => $slug,
            'price_cents' => 10000,
            'is_active' => true,
            'production_prompt_template' => 'Create {{product_name}}.',
        ]);
    }

    private function validPdfUpload(): UploadedFile
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        $pdf->WriteHTML('<h1>HeroKid Studio Preview</h1>');
        $path = tempnam(sys_get_temp_dir(), 'herokid-token-preview-');
        file_put_contents($path, $pdf->Output('', 'S'));

        return new UploadedFile($path, 'preview.pdf', 'application/pdf', null, true);
    }
}
