<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AgenticFixtures;
use Tests\TestCase;

class AgenticMcpTest extends TestCase
{
    use AgenticFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['agentic.mcp_enabled' => true, 'agentic.observe' => false, 'agentic.evaluate' => false]);
    }

    public function test_mcp_requires_dedicated_token_permissions_active_account_and_flag(): void
    {
        $this->rpc('tools/list')->assertUnauthorized();
        $this->withToken($this->token(['*']))->rpc('tools/list')->assertForbidden();
        $this->withToken($this->token(['agentic:read'], []))->rpc('tools/list')->assertForbidden();
        $this->withToken($this->token(['agentic:read'], ['orders.view', 'order_automation.view'], false))->rpc('tools/list')->assertForbidden();
        $this->withToken($this->token())->rpc('tools/list')->assertOk()->assertJsonCount(6, 'result.tools');
        config(['agentic.mcp_enabled' => false]);
        $this->rpc('tools/list')->assertNotFound();
    }

    public function test_seven_tools_are_read_only_and_finance_requires_both_scope_and_permission(): void
    {
        $state = $this->observedCheckout('mcp');
        Order::where('checkout_group_key', 'mcp')->update(['paid_amount_cents' => 123456, 'payment_status' => 'partially_paid']);
        $this->withToken($this->token(['agentic:read', 'agentic:finance'], ['orders.view', 'order_automation.view', 'orders.statistics.view']));
        $tools = $this->rpc('tools/list')->assertOk()->assertJsonCount(7, 'result.tools')->json('result.tools');
        $before = DB::table('orders')->get()->toJson();
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
            $this->assertFalse($tool['annotations']['destructiveHint']);
            $this->assertArrayHasKey('outputSchema', $tool);
            $args = ['order_id' => $state->representative_id];
            if ($tool['name'] === 'get_product_spec') {
                $args['unit_key'] = $state->snapshot['units'][0]['unit_key'];
            }
            $response = $this->rpc('tools/call', ['name' => $tool['name'], 'arguments' => $args])->assertOk()->assertJsonPath('result.isError', false)
                ->assertJsonPath('result.structuredContent.read_only', true);
            if ($tool['name'] !== 'get_payment_status') {
                $response->assertDontSee('123456')->assertDontSee('Synthetic child')->assertDontSee('synthetic/photo');
            } else {
                $response->assertJsonPath('result.structuredContent.data.paid_amount_cents', 123456);
            }
        }
        $this->assertSame($before, DB::table('orders')->get()->toJson());
        $this->assertDatabaseCount('ai_agent_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->withToken($this->token(['agentic:read'], ['orders.view', 'order_automation.view', 'orders.statistics.view']))
            ->rpc('tools/call', ['name' => 'get_payment_status', 'arguments' => ['order_id' => $state->representative_id]])->assertJsonStructure(['error']);
    }

    public function test_write_tools_extra_arguments_cross_unit_and_foreign_origin_are_rejected(): void
    {
        $state = $this->observedCheckout('safe-mcp');
        $this->withToken($this->token());
        foreach (['exec_command', 'send_order_confirmation', 'generate_identity', 'generate_story_scenes', 'create_human_task'] as $name) {
            $this->rpc('tools/call', ['name' => $name, 'arguments' => ['order_id' => $state->representative_id]])->assertJsonStructure(['error']);
        }
        $this->rpc('tools/call', ['name' => 'get_order_context', 'arguments' => ['order_id' => $state->representative_id, 'sql' => 'select secret']])->assertJsonPath('result.isError', true);
        $this->rpc('tools/call', ['name' => 'get_product_spec', 'arguments' => ['order_id' => $state->representative_id, 'unit_key' => 'product:999999']])->assertJsonPath('result.isError', true);
        $this->withHeader('Origin', 'https://untrusted.invalid')->rpc('tools/list')->assertForbidden();
    }

    private function rpc(string $method, array $params = [])
    {
        // A new HTTP request resolves a new bearer token, just as the real transport does.
        app('auth')->forgetGuards();

        return $this->postJson('/mcp/herokid', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], ['MCP-Protocol-Version' => '2025-11-25']);
    }

    private function token(array $abilities = ['agentic:read'], array $permissions = ['orders.view', 'order_automation.view'], bool $active = true): string
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => $active, 'agent_api_enabled' => true]);
        $user->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));

        return $user->createToken('synthetic-agentic-test', $abilities, now()->addHour())->plainTextToken;
    }
}
