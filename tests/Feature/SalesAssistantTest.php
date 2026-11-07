<?php

namespace Tests\Feature;

use App\Jobs\AnswerSalesAssistant;
use App\Models\SalesConversation;
use App\Models\SalesTurn;
use App\Services\SalesAssistant\SalesBudget;
use App\Services\SalesAssistant\SalesCapabilities;
use App\Services\SalesAssistant\SalesCatalog;
use App\Services\SalesAssistant\SalesProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SalesAssistantFixtures;
use Tests\TestCase;

class SalesAssistantTest extends TestCase
{
    use RefreshDatabase, SalesAssistantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_disabled_default_has_no_widget_no_mcp_and_no_provider_request(): void
    {
        $this->get('/shopping-assistant')->assertNotFound();
        $this->postJson('/shopping-assistant/state')->assertNotFound();
        $this->postJson('/mcp/storefront-sales', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('data-sales-assistant', false);
        Http::assertNothingSent();
    }

    public function test_guest_state_is_owned_encrypted_and_messages_enqueue_once_with_202(): void
    {
        $this->salesEnabled();
        $this->openSales();
        $nonce = (string) Str::uuid();
        $message = ['message' => 'عاوز قصة عن الصلاة، تليفوني 01012345678، اسمه سري للغاية', 'client_nonce' => $nonce];
        $this->postJson(route('sales.message'), $message)->assertStatus(202);
        $this->postJson(route('sales.message'), $message)->assertStatus(202);
        Queue::assertPushed(AnswerSalesAssistant::class, 1);
        Queue::assertPushed(AnswerSalesAssistant::class, fn ($job) => $job->queue === 'storefront-assistant' && $job->connection === 'storefront-assistant');
        $raw = DB::table('sales_turns')->first();
        $this->assertStringNotContainsString('الصلاة', $raw->request_text);
        $turn = SalesTurn::sole();
        $this->assertStringNotContainsString('01012345678', $turn->request_text);
        $this->assertStringNotContainsString('سري للغاية', $turn->request_text);
        $this->assertDatabaseCount('sales_assistant_events', 3);
        Http::assertNothingSent();
    }

    public function test_client_cannot_supply_another_conversation_or_any_tool_session_argument(): void
    {
        $this->salesEnabled();
        $foreign = $this->salesConversation();
        $this->openSales();
        $this->postJson(route('sales.message'), ['message' => 'كرة القدم', 'client_nonce' => (string) Str::uuid(), 'conversation_id' => $foreign->id])->assertStatus(422);
        $this->assertDatabaseCount('sales_turns', 0);
        $this->postJson(route('sales.event'), ['type' => 'order_created', 'key' => 'story:1'])->assertUnprocessable();
    }

    public function test_per_session_single_pending_and_daily_40_turn_limit(): void
    {
        $this->salesEnabled();
        $budget = app(SalesBudget::class);
        $c = $this->salesConversation();
        $turn = $budget->reserve($c, 'الصلاة', (string) Str::uuid());
        try {
            $budget->reserve($c, 'كرة القدم', (string) Str::uuid());
            $this->fail();
        } catch (ValidationException) {
        }
        $budget->settle($turn, ['input_tokens' => 0, 'output_tokens' => 0], 'unavailable', null);
        DB::table('sales_session_days')->update(['turns' => 40]);
        $this->expectException(ValidationException::class);
        $budget->reserve($c, 'جديد', (string) Str::uuid());
    }

    public function test_cairo_budget_day_and_uncertain_cost_are_not_released_or_retried(): void
    {
        $this->salesEnabled();
        $this->travelTo(Carbon::parse('2026-11-06 22:30:00', 'UTC'));
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'الصلاة', (string) Str::uuid());
        $this->assertSame('2026-11-07', $turn->budget_day);
        app(SalesBudget::class)->settle($turn, [], 'uncertain', null);
        $this->assertSame(100000, (int) DB::table('sales_daily_budgets')->value('reserved_micros'));
        app(SalesBudget::class)->settle($turn, ['input_tokens' => 0, 'output_tokens' => 0], 'completed', []);
        $this->assertSame('uncertain', $turn->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_budget_ceiling_rejects_before_provider_and_settles_receipt_once(): void
    {
        $this->salesEnabled();
        $budget = app(SalesBudget::class);
        $turn = $budget->reserve($this->salesConversation(), 'الصلاة', (string) Str::uuid());
        $budget->settle($turn, ['input_tokens' => 1000, 'output_tokens' => 100], 'completed', ['message' => 'أهلا', 'recommendations' => [], 'upsell' => false]);
        $budget->settle($turn, ['input_tokens' => 1000, 'output_tokens' => 100], 'completed', []);
        $this->assertSame(175, (int) DB::table('sales_daily_budgets')->value('spent_micros'));
        DB::table('sales_daily_budgets')->update(['spent_micros' => 5000000]);
        $this->expectException(ValidationException::class);
        $budget->reserve($this->salesConversation(), 'الصلاة', (string) Str::uuid());
    }

    public function test_mcp_lists_exactly_five_read_tools_and_rejects_internal_and_foreign_arguments(): void
    {
        $this->salesEnabled();
        $story = $this->salesStory();
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'الصلاة', (string) Str::uuid());
        $turn->update(['status' => 'processing']);
        $token = app(SalesCapabilities::class)->issue($turn, ['line_count' => 0, 'items' => []]);
        $this->withToken($token)->postJson('/mcp/storefront-sales', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk()->assertJsonCount(5, 'result.tools');
        $request = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_catalog_item', 'arguments' => ['key' => 'story:'.$story->id]]];
        $this->withToken($token)->postJson('/mcp/storefront-sales', $request)->assertOk()->assertJsonPath('result.structuredContent.item.title', $story->title);
        $request['params']['arguments']['session_id'] = 'another';
        $this->withToken($token)->postJson('/mcp/storefront-sales', $request)->assertOk()->assertJsonPath('result.isError', true);
        $request['params'] = ['name' => 'get_order_context', 'arguments' => ['order_id' => 1]];
        $this->withToken($token)->postJson('/mcp/storefront-sales', $request)->assertStatus(400)->assertJsonStructure(['error']);
        $this->withToken($token)->postJson('/mcp/herokid', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_mcp_expiration_completion_and_four_tool_cap_are_enforced(): void
    {
        $this->salesEnabled();
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'الصلاة', (string) Str::uuid());
        $turn->update(['status' => 'processing']);
        $cap = app(SalesCapabilities::class);
        $token = $cap->issue($turn, ['line_count' => 1]);
        $resolved = $cap->resolve($token);
        foreach (range(1, 4) as $i) {
            $this->assertSame(['cart' => ['line_count' => 1]], $cap->tool($resolved, 'get_my_cart_summary', []));
        }
        try {
            $cap->tool($resolved, 'get_my_cart_summary', []);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
        $this->travel(4)->minutes();
        $this->withToken($token)->postJson('/mcp/storefront-sales', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden();
    }

    public function test_fake_provider_uses_exact_model_bounded_read_only_mcp_and_no_private_fields(): void
    {
        $this->salesEnabled();
        $story = $this->salesStory();
        $this->openSales();
        $this->postJson(route('sales.message'), ['message' => 'قصة عن الصلاة', 'client_nonce' => (string) Str::uuid()])->assertStatus(202);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->salesReply([['key' => 'story:'.$story->id, 'reason' => 'عن حب الصلاة والمسجد']]))]);
        $turn = SalesTurn::sole();
        (new AnswerSalesAssistant($turn->id, ['line_count' => 0, 'items' => []]))->handle(app(SalesProvider::class), app(SalesBudget::class));
        Http::assertSent(function ($r) {
            $data = $r->data();
            $json = json_encode($data);

            return $data['model'] === 'gpt-6-luna' && $data['store'] === false && $data['reasoning']['effort'] === 'low'
                && $data['tools'][0]['allowed_tools'] === SalesCapabilities::TOOLS && ! str_contains($json, 'child_name') && ! str_contains($json, 'upload_session_token') && ! isset($data['previous_response_id']);
        });
        $this->assertSame('completed', $turn->fresh()->status);
        $this->assertDatabaseHas('sales_assistant_events', ['type' => 'reply_completed']);
        $this->postJson(route('sales.state'))->assertOk()->assertJsonPath('turns.0.result.cards.0.title', $story->title)->assertJsonPath('turns.0.result.cards.0.price_cents', 19900);
        // A duplicate queue delivery cannot invoke OpenAI again.
        (new AnswerSalesAssistant($turn->id, []))->handle(app(SalesProvider::class), app(SalesBudget::class));
        Http::assertSentCount(1);
    }

    public function test_fabricated_keys_prices_and_scripts_cannot_become_product_cards(): void
    {
        $this->salesEnabled();
        $this->salesStory();
        Http::fake(['*' => Http::response($this->salesReply([['key' => 'story:999999', 'reason' => 'خيالي']], 'أهلاً <script>alert(1)</script> https://example.test'))]);
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'الصلاة', (string) Str::uuid());
        $turn->update(['status' => 'processing']);
        $reply = app(SalesProvider::class)->answer($turn, []);
        $this->assertSame([], $reply['result']['recommendations']);
        $this->assertStringNotContainsString('<script>', $reply['result']['message']);
        $this->assertStringNotContainsString('https://', $reply['result']['message']);
    }

    public function test_provider_connection_failure_keeps_reservation_and_uses_no_automatic_retry(): void
    {
        $this->salesEnabled();
        Http::fake(fn () => throw new ConnectionException('synthetic'));
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'الصلاة', (string) Str::uuid());
        (new AnswerSalesAssistant($turn->id, []))->handle(app(SalesProvider::class), app(SalesBudget::class));
        $this->assertSame('uncertain', $turn->fresh()->status);
        $this->assertSame(100000, (int) $turn->fresh()->reserved_micros);
        Queue::assertNothingPushed();
    }

    public function test_upsell_decline_survives_new_turns_and_refuses_model_repetition(): void
    {
        $this->salesEnabled();
        $story = $this->salesStory();
        $this->openSales();
        $this->postJson(route('sales.event'), ['type' => 'upsell_declined'])->assertNoContent();
        $this->postJson(route('sales.upsells'))->assertOk()->assertJsonCount(0, 'cards');
        $turn = app(SalesBudget::class)->reserve(SalesConversation::sole(), 'الصلاة', (string) Str::uuid());
        $turn->update(['status' => 'processing']);
        Http::fake(['*' => Http::response($this->salesReply([['key' => 'story:'.$story->id, 'reason' => 'حب الصلاة']], upsell: true))]);
        $reply = app(SalesProvider::class)->answer($turn, []);
        $this->assertSame([], $reply['result']['recommendations']);
    }

    public function test_turn_retention_and_stale_reconciliation_preserve_orders_and_aggregate_usage(): void
    {
        $this->salesEnabled();
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'الصلاة', (string) Str::uuid());
        $turn->update(['created_at' => now()->subMinutes(6), 'updated_at' => now()->subMinutes(6)]);
        $this->artisan('sales-assistant:maintain')->assertSuccessful();
        $this->assertSame('expired', $turn->fresh()->status);
        $this->assertSame(0, (int) $turn->fresh()->reserved_micros);
        $turn->update(['created_at' => now()->subDays(31)]);
        $this->artisan('sales-assistant:maintain')->assertSuccessful();
        $this->assertDatabaseCount('sales_turns', 0);
        $this->assertDatabaseCount('sales_assistant_events', 2);
        Http::assertNothingSent();
    }

    public function test_message_rate_limit_and_csrf_reject_before_any_additional_provider_turn(): void
    {
        $this->salesEnabled();
        $this->openSales();
        for ($i = 0; $i < 5; $i++) {
            $receipt = $this->postJson(route('sales.message'), ['message' => 'قصة الصلاة', 'client_nonce' => (string) Str::uuid()])->assertStatus(202);
            app(SalesBudget::class)->settle(SalesTurn::findOrFail($receipt->json('turn_id')), ['input_tokens' => 0, 'output_tokens' => 0], 'unavailable', null);
        }
        $this->postJson(route('sales.message'), ['message' => 'جديد', 'client_nonce' => (string) Str::uuid()])->assertStatus(429);
        $this->app->instance('env', 'local');
        $this->postJson(route('sales.event'), ['type' => 'upsell_declined'])->assertStatus(419);
        Http::assertNothingSent();
        $this->assertDatabaseCount('sales_turns', 5);
    }

    public function test_stale_queued_observation_cannot_release_a_reservation_after_worker_started(): void
    {
        $this->salesEnabled();
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'قصة', (string) Str::uuid());
        $queued = clone $turn;
        $turn->update(['status' => 'processing']);
        app(SalesBudget::class)->settle($queued, ['input_tokens' => 0, 'output_tokens' => 0], 'expired', null);
        $this->assertSame('processing', $turn->fresh()->status);
        $this->assertSame(100000, (int) $turn->fresh()->reserved_micros);
    }

    public function test_catalog_golden_egyptian_needs_have_correct_top_three_retrieval_without_substring_collision(): void
    {
        $prayer = $this->salesStory();
        $football = $this->salesStory('football', 'محمد صلاح وكرة القدم', 'العمل الجماعي وكرة القدم');
        $parents = $this->salesStory('parents', 'حب ماما وبابا', 'بر الوالدين وطاعة الوالدين');
        $color = $this->salesStory('color', 'مغامرة التلوين', 'الرسم والابداع');
        $puzzle = $this->salesStory('focus', 'مغامرة البازل', 'التركيز والمهارات');
        $cases = [['عاوز قصة عن حب الصلاة', $prayer], ['ابني مش بيحب يصلي', $prayer], ['قصة عن المسجد', $prayer], ['صلاة الفجر', $prayer], ['حب العبادة', $prayer],
            ['بيحب كرة القدم', $football], ['ابني بيحب الكورة', $football], ['قصة عن محمد صلاح', $football], ['football', $football], ['حكاية عن الكره', $football],
            ['قصة عن بر الوالدين', $parents], ['طاعة الوالدين', $parents], ['يحب ماما', $parents], ['يسمع كلام بابا', $parents], ['احترام والدته', $parents],
            ['عايز كتاب تلوين', $color], ['بيحب الرسم', $color], ['نشاط عن الابداع', $color], ['نشاط بعيد عن الشاشات', $puzzle], ['بيتفرج كتير علي التليفزيون', $puzzle], ['شقي بزيادة', $puzzle], ['عايز ينمي التركيز', $puzzle]];
        $hits = 0;
        foreach ($cases as [$query,$story]) {
            if (in_array($story->id, array_column(array_slice(app(SalesCatalog::class)->search($query), 0, 3), 'id'), true)) {
                $hits++;
            }
        }
        $this->assertGreaterThanOrEqual(.9, $hits / count($cases));
        $this->assertSame($prayer->id, app(SalesCatalog::class)->search('الصلاة')[0]['id']);
        $this->assertSame([], app(SalesCatalog::class)->search('الصلاة', budgetCents: 100));
    }
}
