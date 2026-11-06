<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\SalesAssistant\SalesBudget;
use App\Services\SalesAssistant\SalesCatalog;
use App\Services\SalesAssistant\SalesSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\SalesAssistantFixtures;
use Tests\TestCase;

class SalesAssistantAdminTest extends TestCase
{
    use RefreshDatabase, SalesAssistantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function admin(array $permissions = ['analytics.view', 'settings.site.update', 'sales_reports.view', 'orders.view_all']): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => false]);
        $user->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));
        $this->actingAs($user->fresh());

        return $user;
    }

    public function test_anonymous_and_restricted_staff_cannot_read_conversations_or_aggregate_finance(): void
    {
        $this->getJson(route('admin.sales-assistant.index'))->assertUnauthorized();
        $this->admin([]);
        $this->get(route('admin.sales-assistant.index'))->assertForbidden();
        Setting::updateOrCreate(['key' => 'orders_visibility'], ['value' => 'assigned']);
        $staff = $this->admin(['analytics.view', 'settings.site.update', 'sales_reports.view']);
        $this->assertFalse($staff->hasPermission('orders.view_all'));
        $this->assertSame('assigned', Setting::where('key', 'orders_visibility')->value('value'));
        $this->get(route('admin.sales-assistant.index'))->assertForbidden();
    }

    public function test_usage_report_retains_message_reply_visitor_tokens_and_cost_without_private_values(): void
    {
        $this->admin();
        $conversation = $this->salesConversation();
        $turn = app(SalesBudget::class)->reserve($conversation, 'رسالة مشفرة', (string) Str::uuid());
        app(SalesBudget::class)->settle($turn, ['input_tokens' => 1000, 'output_tokens' => 100], 'completed', ['message' => 'رد مسجل', 'recommendations' => []]);
        $this->get(route('admin.sales-assistant.index'))->assertOk()->assertSee('رسائل العملاء')->assertSee('ردود ناجحة')->assertSee('التوكنز')->assertSee('تقدير التكلفة')->assertDontSee('رسالة مشفرة');
        $this->getJson(route('admin.sales-assistant.conversation', $conversation))->assertOk()->assertJsonPath('turns.0.question', 'رسالة مشفرة');
        $this->admin(['analytics.view', 'orders.view_all']);
        $this->get(route('admin.sales-assistant.index'))->assertOk()->assertDontSee('تقدير التكلفة')->assertDontSee('توكنز الإدخال');
        $this->getJson(route('admin.sales-assistant.conversation', $conversation))->assertForbidden();
    }

    public function test_activation_requires_experience_approval_and_current_key_model_receipt_without_fallback(): void
    {
        $this->admin();
        $this->post(route('admin.sales-assistant.update'), ['enabled' => 1, 'daily_budget_usd' => 5, 'experience_approved' => 1])->assertSessionHasErrors('enabled');
        $provider = AiProvider::where('driver', 'openai')->firstOrFail();
        $provider->update(['is_active' => true]);
        app(AiProviderCredentialService::class)->save($provider, 'synthetic-key-one');
        Http::fake(['https://api.openai.com/v1/models/gpt-6-luna' => Http::response(['id' => 'gpt-6-luna'])]);
        $this->post(route('admin.sales-assistant.verify'))->assertRedirect()->assertSessionHas('success');
        $this->assertTrue(app(SalesSettings::class)->verified());
        $this->assertFalse(app(SalesSettings::class)->workerReady());
        $this->post(route('admin.sales-assistant.update'), ['enabled' => 1, 'daily_budget_usd' => 5, 'experience_approved' => 1])->assertSessionHasErrors('enabled');
        Cache::put('sales-assistant:worker-heartbeat', now()->timestamp, 120);
        $this->post(route('admin.sales-assistant.update'), ['enabled' => 1, 'daily_budget_usd' => 5])->assertSessionHasErrors('enabled');
        $this->post(route('admin.sales-assistant.update'), ['enabled' => 1, 'daily_budget_usd' => 5, 'experience_approved' => 1])->assertRedirect();
        $this->assertTrue(app(SalesSettings::class)->enabled());
        $this->assertSame(5000000, app(SalesSettings::class)->budget());
        app(AiProviderCredentialService::class)->save($provider, 'synthetic-key-two');
        $this->assertFalse(app(SalesSettings::class)->verified());
        Http::assertSentCount(1);
    }

    public function test_catalog_keywords_edit_changes_search_without_exposing_inactive_items(): void
    {
        $this->admin();
        $story = $this->salesStory();
        $this->post(route('admin.sales-assistant.profile'), ['key' => 'story:'.$story->id, 'keywords' => 'احترام الوقت، النظام'])->assertRedirect();
        $this->assertSame($story->id, app(SalesCatalog::class)->search('النظام')[0]['id']);
        $this->post(route('admin.sales-assistant.profile'), ['key' => 'story:999999', 'keywords' => 'اختبار'])->assertNotFound();
    }
}
