<?php

namespace Tests\Feature;

use App\Http\Resources\Agent\AgentStudioOrderResource;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentApi\AgentCatalogScope;
use App\Services\AgentApi\AgentStudioOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentStudioApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_requires_agent_authentication_enabled_account_ability_and_permission(): void
    {
        $this->getJson('/api/agent/studio/connection')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'UNAUTHORIZED');

        $disabled = $this->agent(enabled: false);
        $this->withToken($this->token($disabled))->getJson('/api/agent/studio/connection')
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN');

        $missingAbility = $this->agent();
        $this->app['auth']->forgetGuards();
        $this->withToken($missingAbility->createToken('missing-read', ['agent'])->plainTextToken)
            ->getJson('/api/agent/studio/connection')
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN');

        $missingPermission = $this->agent();
        $missingPermission->permissions()->detach(Permission::query()->where('key', 'orders.view')->value('id'));
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($missingPermission))->getJson('/api/agent/studio/connection')
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN');
    }

    public function test_connection_returns_only_safe_agent_metadata(): void
    {
        $agent = $this->agent();

        $response = $this->withToken($this->token($agent))
            ->getJson('/api/agent/studio/connection')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('agent.id', $agent->id)
            ->assertJsonPath('studio_api', true)
            ->assertJsonPath('api_version', '1')
            ->assertJsonFragment(['agent:orders.read']);

        $response->assertJsonMissingPath('token')
            ->assertJsonMissingPath('agent.email')
            ->assertJsonMissingPath('agent.password');
    }

    public function test_product_scoped_token_cannot_use_the_story_studio_lookup(): void
    {
        $story = $this->story('قصة محمية', 'protected-story');
        $this->storyOrder('PROTECTED-STUDIO-GROUP', 'HK-PROTECTED-STUDIO', $story, 'علي', null);
        $agent = $this->agent();
        $token = $agent->createToken('product-only-studio', [
            'agent',
            'agent:orders.read',
            ...AgentCatalogScope::abilities(AgentCatalogScope::PRODUCTS),
        ])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/agent/studio/orders/HK-PROTECTED-STUDIO')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'FORBIDDEN');
    }

    public function test_studio_lookup_returns_all_story_units_without_acquisition_and_excludes_products_and_private_data(): void
    {
        $firstStory = $this->story('المخترع الصغير', 'little-inventor');
        $secondStory = $this->story('صائدة النجوم', 'star-hunter');
        $first = $this->storyOrder('STUDIO-GROUP', 'HK-2026-STUDIO-1', $firstStory, 'ياسين', 'إلى ياسين، بطلنا الصغير.');
        $second = $this->storyOrder('STUDIO-GROUP', 'HK-2026-STUDIO-2', $secondStory, 'مريم', 'إلى مريم، نجمتنا المضيئة.');
        $this->readyProductOrder('STUDIO-GROUP', 'HK-2026-STUDIO-PRODUCT');

        $sceneTwo = $firstStory->sceneTemplates()->create([
            'scene_number' => 2,
            'title' => 'المشهد الثاني',
            'text_template' => 'عاد {{child_name}} إلى المنزل.',
        ]);
        $sceneOne = $firstStory->sceneTemplates()->create([
            'scene_number' => 1,
            'title' => 'المشهد الأول',
            'text_template' => 'بدأ {{child_name}} الاختراع.',
        ]);
        $first->sceneTextSnapshots()->create([
            'source_story_scene_template_id' => $sceneTwo->id,
            'scene_number' => 2,
            'title_snapshot' => 'المشهد الثاني',
            'rendered_text' => 'عاد ياسين إلى المنزل.',
        ]);
        $firstScene = $first->sceneTextSnapshots()->create([
            'source_story_scene_template_id' => $sceneOne->id,
            'scene_number' => 1,
            'title_snapshot' => 'المشهد الأول',
            'rendered_text' => 'بدأ ياسين الاختراع.',
        ]);
        $secondTemplate = $secondStory->sceneTemplates()->create([
            'scene_number' => 1,
            'title' => null,
            'text_template' => 'رأت {{child_name}} نجمة تلمع في السماء.',
        ]);
        $secondScene = $second->sceneTextSnapshots()->create([
            'source_story_scene_template_id' => $secondTemplate->id,
            'scene_number' => 1,
            'rendered_text' => 'رأت مريم نجمة تلمع في السماء.',
        ]);

        $agent = $this->agent();
        $response = $this->withToken($this->token($agent))
            ->getJson('/api/agent/studio/orders/HK-2026-STUDIO-1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.id', 'STUDIO-GROUP')
            ->assertJsonPath('order.order_number', 'HK-2026-STUDIO-1')
            ->assertJsonCount(2, 'production_stories')
            ->assertJsonPath('production_stories.0.production_unit_id', 'story:'.$first->id)
            ->assertJsonPath('production_stories.0.story.id', $firstStory->id)
            ->assertJsonPath('production_stories.0.story.template_id', 'story:'.$firstStory->id)
            ->assertJsonPath('production_stories.0.child.name', 'ياسين')
            ->assertJsonPath('production_stories.0.dedication', 'إلى ياسين، بطلنا الصغير.')
            ->assertJsonPath('production_stories.0.scenes.0.id', 'order_scene_snapshot:'.$firstScene->id)
            ->assertJsonPath('production_stories.0.scenes.0.number', 1)
            ->assertJsonPath('production_stories.0.scenes.0.text', 'بدأ ياسين الاختراع.')
            ->assertJsonPath('production_stories.0.scenes.1.number', 2)
            ->assertJsonPath('production_stories.1.production_unit_id', 'story:'.$second->id)
            ->assertJsonPath('production_stories.1.child.name', 'مريم')
            ->assertJsonPath('production_stories.1.dedication', 'إلى مريم، نجمتنا المضيئة.')
            ->assertJsonPath('production_stories.1.scenes.0.id', 'order_scene_snapshot:'.$secondScene->id)
            ->assertJsonPath('production_stories.1.scenes.0.text', 'رأت مريم نجمة تلمع في السماء.');

        $this->assertDatabaseMissing('order_group_assignments', ['checkout_group_key' => 'STUDIO-GROUP']);
        $payload = $response->json();
        $this->assertArrayNotHasKey('delivery_details', $payload['order']);
        $this->assertArrayNotHasKey('payment_status', $payload['order']);
        $this->assertStringNotContainsString('201099999999', $response->getContent());
        $this->assertStringNotContainsString('عنوان خاص', $response->getContent());
        $this->assertStringNotContainsString('جاهز بدون تخصيص', $response->getContent());
    }

    public function test_lookup_accepts_short_checkout_reference_and_identifiers_are_stable(): void
    {
        $story = $this->story('قصة ثابتة', 'stable-story');
        $order = $this->storyOrder('STABLE-GROUP', 'HK-2026-STABLE', $story, 'نور', null);
        $template = $story->sceneTemplates()->create(['scene_number' => 1, 'text_template' => 'كانت نور سعيدة.']);
        $snapshot = $order->sceneTextSnapshots()->create([
            'source_story_scene_template_id' => $template->id,
            'scene_number' => 1,
            'rendered_text' => 'كانت نور سعيدة.',
        ]);
        $reference = $order->checkoutReference->short_reference;
        $agent = $this->agent();
        $token = $this->token($agent);

        $first = $this->withToken($token)->getJson('/api/agent/studio/orders/'.$reference)->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $second = $this->withToken($token)->getJson('/api/agent/studio/orders/'.$reference)->assertOk()->json();

        $this->assertSame('story:'.$order->id, $first['production_stories'][0]['production_unit_id']);
        $this->assertSame('story:'.$story->id, $first['production_stories'][0]['story']['template_id']);
        $this->assertSame('order_scene_snapshot:'.$snapshot->id, $first['production_stories'][0]['scenes'][0]['id']);
        $this->assertSame($first['order']['source_revision'], $second['order']['source_revision']);
        $this->assertSame($first['production_stories'][0]['scenes'][0]['id'], $second['production_stories'][0]['scenes'][0]['id']);
    }

    public function test_unknown_order_returns_agent_404_and_product_only_checkout_returns_empty_stories(): void
    {
        $agent = $this->agent();
        $token = $this->token($agent);

        $this->withToken($token)->getJson('/api/agent/studio/orders/UNKNOWN')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'ORDER_NOT_FOUND');

        $product = $this->readyProductOrder('PRODUCT-GROUP', 'HK-2026-PRODUCT-ONLY');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/agent/studio/orders/'.$product->order_number)
            ->assertOk()
            ->assertJsonPath('order.id', 'PRODUCT-GROUP')
            ->assertJsonCount(0, 'production_stories');
    }

    public function test_studio_lookup_query_count_does_not_grow_with_story_units(): void
    {
        $singleStory = $this->story('قصة واحدة', 'query-single');
        $single = $this->storyOrder('QUERY-SINGLE', 'HK-QUERY-SINGLE', $singleStory, 'طفل واحد', null);
        $singleStory->sceneTemplates()->create(['scene_number' => 1, 'text_template' => 'نص طفل واحد.']);

        foreach (range(1, 5) as $index) {
            $story = $this->story('قصة '.$index, 'query-many-'.$index);
            $this->storyOrder('QUERY-MANY', 'HK-QUERY-MANY-'.$index, $story, 'طفل '.$index, null);
            $story->sceneTemplates()->create(['scene_number' => 1, 'text_template' => 'نص الطفل.']);
        }

        $service = app(AgentStudioOrderService::class);
        $request = Request::create('/api/agent/studio/orders/test');
        DB::enableQueryLog();
        DB::flushQueryLog();
        (new AgentStudioOrderResource($service->find($single->order_number)))->resolve($request);
        $singleQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        (new AgentStudioOrderResource($service->find('HK-QUERY-MANY-1')))->resolve($request);
        $multipleQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($singleQueries + 1, $multipleQueries);
    }

    private function agent(bool $enabled = true): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'agent_api_enabled' => $enabled,
        ]);
    }

    private function token(User $agent): string
    {
        return $agent->createToken('studio-test', ['agent', 'agent:orders.read'])->plainTextToken;
    }

    private function story(string $title, string $slug): Story
    {
        return Story::query()->create([
            'title' => $title,
            'slug' => $slug,
            'language' => 'ar',
            'gender' => 'both',
            'price' => 350,
            'active' => true,
        ]);
    }

    private function storyOrder(string $group, string $number, Story $story, string $child, ?string $dedication): Order
    {
        return Order::query()->create([
            'order_number' => $number,
            'checkout_group_key' => $group,
            'story_id' => $story->id,
            'parent_name' => 'ولي أمر خاص',
            'child_name' => $child,
            'child_age' => 7,
            'child_gender' => 'girl',
            'language' => 'ar',
            'lesson' => 'الثقة بالنفس',
            'interests' => 'العلوم',
            'gift_note' => $dedication,
            'status' => 'new',
            'payment_status' => 'partially_paid',
            'paid_amount_cents' => 10_000,
            'delivery_details' => [
                'phone' => '201099999999',
                'address' => 'عنوان خاص',
                'payment_provider' => 'private-provider',
            ],
            'uploaded_photos' => [],
        ])->fresh(['checkoutReference']);
    }

    private function readyProductOrder(string $group, string $number): Order
    {
        $product = Product::query()->create([
            'name_ar' => 'جاهز بدون تخصيص',
            'slug' => strtolower($number),
            'sku' => $number,
            'price_cents' => 10_000,
            'is_active' => true,
            'personalization_mode' => 'none',
        ]);
        $order = Order::query()->create([
            'order_number' => $number,
            'checkout_group_key' => $group,
            'story_id' => null,
            'parent_name' => 'عميل المنتج',
            'status' => 'new',
            'delivery_details' => ['phone' => '201088888888', 'address' => 'عنوان منتج'],
            'uploaded_photos' => [],
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 10_000,
            'total_price_cents' => 10_000,
            'personalization_mode' => 'none',
        ]);

        return $order->fresh(['checkoutReference']);
    }
}
