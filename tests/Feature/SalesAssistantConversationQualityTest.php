<?php

namespace Tests\Feature;

use App\Models\SalesConversation;
use App\Models\SalesTurn;
use App\Services\SalesAssistant\SalesBudget;
use App\Services\SalesAssistant\SalesCapabilities;
use App\Services\SalesAssistant\SalesCatalog;
use App\Services\SalesAssistant\SalesChildContext;
use App\Services\SalesAssistant\SalesPrivacy;
use App\Services\SalesAssistant\SalesProvider;
use App\Services\SalesAssistant\SalesSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SalesAssistantFixtures;
use Tests\TestCase;

class SalesAssistantConversationQualityTest extends TestCase
{
    use RefreshDatabase, SalesAssistantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function remember(SalesConversation $conversation, string $text, int $minutes = 10): SalesTurn
    {
        return SalesTurn::create(['id' => (string) Str::uuid7(), 'conversation_id' => $conversation->id,
            'client_nonce' => (string) Str::uuid(), 'status' => 'completed', 'request_text' => $text,
            'budget_day' => now('Africa/Cairo')->toDateString(), 'capability_nonce' => Str::random(64),
            'created_at' => now()->subMinutes($minutes), 'result' => ['message' => 'تمام، خلينا نختار قصة.', 'recommendations' => []]]);
    }

    public function test_public_identity_and_catalog_titles_remain_readable_while_private_details_are_redacted(): void
    {
        $privacy = app(SalesPrivacy::class);
        $text = 'أنا مساعد HeroKid، قصة اسمها موعد النور عن حب الصلاة.';
        $this->assertSame($text, $privacy->safeReply($text, ['موعد النور']));
        $this->assertSame('قصة اسمها موعد النور', $privacy->redact('قصة اسمها موعد النور', publicLabels: ['موعد النور']));
        $private = 'أنا أحمد محمود، بنتي اسمها سري للغاية، موبايلي 01012345678، بريدي parent@example.test، عنواني شارع النيل';
        $redacted = $privacy->redact($private);
        foreach (['أحمد محمود', 'سري للغاية', '01012345678', 'parent@example.test', 'شارع النيل'] as $secret) {
            $this->assertStringNotContainsString($secret, $redacted);
            $this->assertStringNotContainsString($secret, $privacy->safeReply($private));
        }
        $this->assertStringNotContainsString('بيانات خاصة', $privacy->safeReply($private));
        $this->assertStringNotContainsString('سري للغاية', $privacy->redact('قصة اسمها سري للغاية', ['سري للغاية'], ['سري للغاية']));
        $this->assertSame('قصة منورة', $privacy->redact('قصة منورة', ['نور']));
        foreach (['اسمي موعد النور', 'عنواني موعد النور شارع سري'] as $privateTitle) {
            $this->assertStringNotContainsString('موعد النور', $privacy->redact($privateTitle, publicLabels: ['موعد النور']));
        }

    }

    public function test_child_facts_survive_topic_changes_and_more_than_four_recent_messages_without_crossing_conversations(): void
    {
        $c = $this->salesConversation();
        $this->remember($c, 'رشحلي قصة لبنتي', 30);
        $this->remember($c, 'بنتي ٦ سنين اختار انت حاجة مناسبة', 29);
        foreach (range(1, 6) as $n) {
            $this->remember($c, 'عاوز قصة عن التنمر', 20 - $n);
        }
        $foreign = $this->salesConversation();
        $this->remember($foreign, 'ابني ٩ سنوات', 5);
        $turn = $this->remember($c, 'قصة تحببه في الصلاة', 1);
        $this->assertSame(['age' => 6, 'gender' => 'girl', 'needs_child_choice' => false], app(SalesChildContext::class)->forTurn($turn));
        $this->assertSame(['age' => 9, 'gender' => 'boy', 'needs_child_choice' => false], app(SalesChildContext::class)->forTurn(SalesTurn::where('conversation_id', $foreign->id)->first()));
    }

    public function test_switching_child_resets_stale_age_and_ambiguous_siblings_require_a_choice(): void
    {
        $context = app(SalesChildContext::class);
        $this->assertSame(['age' => null, 'gender' => 'boy', 'needs_child_choice' => false], $context->fromMessages(['بنتي ٦ سنين', 'دلوقتي عاوز قصة لابني']));
        $this->assertSame(['age' => null, 'gender' => 'girl', 'needs_child_choice' => false], $context->fromMessages(['بنتي ٦ سنين', 'بنتي التانية']));
        $this->assertTrue($context->fromMessages(['عندي ولد ٤ سنين وبنت ٦ سنين'])['needs_child_choice']);
        $this->assertSame(['age' => 6, 'gender' => 'girl', 'needs_child_choice' => false], $context->fromMessages(['عندي ولد ٤ سنين وبنت ٦ سنين', 'نبدأ ببنتي عمرها 6']));
        $this->assertSame('boy', $context->fromMessages(['بنتي ٦ سنين', 'ابني ولد مش بنت'])['gender']);
    }

    public function test_all_stories_customize_for_either_gender_while_actual_child_age_is_preserved(): void
    {
        $this->salesEnabled();
        $girl = $this->salesStory('girl-prayer', 'نور الصلاة', 'حب الصلاة', '٦ - ٩ سنوات');
        $girl->update(['gender' => 'girl']);
        $boy = $this->salesStory('boy-prayer', 'صلاة الفجر مع بابا', 'حب الصلاة', '٦ - ٩ سنوات');
        $boy->update(['gender' => 'boy']);
        $older = $this->salesStory('older-prayer', 'نور الصلاة للكبار', 'حب الصلاة', '٩ - ١٢ سنوات');
        $older->update(['gender' => 'girl']);
        $c = $this->salesConversation();
        $this->remember($c, 'بنتي 6 سنين');
        $turn = app(SalesBudget::class)->reserve($c, 'قصة تحببه في الصلاة', (string) Str::uuid());
        $turn->update(['status' => 'processing']);
        Http::fake(['*' => Http::response($this->salesReply([
            ['key' => 'story:'.$boy->id, 'reason' => 'عن الصلاة'], ['key' => 'story:'.$girl->id, 'reason' => 'عن الصلاة'],
            ['key' => 'story:'.$older->id, 'reason' => 'عن الصلاة'],
        ], 'أرشح صلاة الفجر مع بابا. كام سنة عمرها؟'))]);
        $reply = app(SalesProvider::class)->answer($turn, []);
        Http::assertSent(function ($request) use ($girl, $boy) {
            $data = json_decode($request->data()['input'][0]['content'], true);
            $this->assertSame(['age' => 6, 'gender' => 'girl', 'needs_child_choice' => false], $data['child_context']);
            $this->assertSame(['story:'.$girl->id, 'story:'.$boy->id], array_column($data['catalog_candidates'], 'key'));

            return true;
        });
        $this->assertSame(['story:'.$boy->id, 'story:'.$girl->id], array_column($reply['result']['recommendations'], 'key'));
        $this->assertStringNotContainsString('صلاة الفجر مع بابا', $reply['result']['message']);
        $this->assertStringNotContainsString('كام', $reply['result']['message']);
        $this->assertStringContainsString('لبنتك', $reply['result']['message']);
    }

    public function test_known_age_question_is_removed_even_when_every_recommendation_is_valid(): void
    {
        $this->salesEnabled();
        $girl = $this->salesStory('girl-prayer', 'نور الصلاة', 'حب الصلاة');
        $girl->update(['gender' => 'girl']);
        $c = $this->salesConversation();
        $this->remember($c, 'بنتي 6 سنين');
        $turn = app(SalesBudget::class)->reserve($c, 'الصلاة', (string) Str::uuid());
        Http::fake(['*' => Http::response($this->salesReply([['key' => 'story:'.$girl->id, 'reason' => 'عن الصلاة']], 'عمر طفلك كام؟'))]);
        $reply = app(SalesProvider::class)->answer($turn, []);
        $this->assertCount(1, $reply['result']['recommendations']);
        $this->assertStringNotContainsString('كام', $reply['result']['message']);
        $this->assertStringContainsString('6', $reply['result']['message']);
    }

    public function test_known_age_questions_cover_ordinary_egyptian_phrases(): void
    {
        $context = app(SalesChildContext::class);
        foreach (['عمرها كام؟', 'عمر طفلك كام؟', 'عمر بنتك كام سنة؟', 'كام سنة عمرها؟', 'عندها كام سنة؟'] as $question) {
            $this->assertTrue($context->repeatsKnownQuestion($question, ['age' => 6, 'gender' => 'girl']), $question);
            $this->assertFalse($context->repeatsKnownQuestion($question, ['age' => null, 'gender' => 'girl']), $question);
        }
    }

    public function test_enabled_page_omits_removed_privacy_section_and_drawer_has_its_own_controls(): void
    {
        $this->salesEnabled();
        $this->get('/shopping-assistant')->assertOk()->assertDontSee('خصوصية المحادثة')->assertDontSee('hk-sales-privacy', false)
            ->assertSee('data-full="1"', false)->assertDontSee('data-floating-whatsapp', false)->assertDontSee('hk-sales-launch', false);
        $this->get('/')->assertOk()->assertSee('data-open', false)->assertSee('data-close', false)->assertDontSee('خصوصية المحادثة');
    }

    public function test_no_age_appropriate_story_does_not_fall_back_to_an_older_story(): void
    {
        $this->salesEnabled();
        $boy = $this->salesStory('boy-prayer', 'موعد النور', 'حب الصلاة');
        $boy->update(['gender' => 'boy', 'age_range' => '12-15']);
        $girl = $this->salesStory('girl-other', 'طبيبة الحيوانات', 'الرحمة');
        $girl->update(['gender' => 'girl', 'short_desc' => 'قصة عن الحيوانات']);
        $c = $this->salesConversation();
        $this->remember($c, 'بنتي 6 سنين');
        $turn = app(SalesBudget::class)->reserve($c, 'عاوز قصة عن الصلاة', (string) Str::uuid());
        $turn->update(['status' => 'processing']);
        Http::fake(['*' => Http::response($this->salesReply([['key' => 'story:'.$boy->id, 'reason' => 'الصلاة']], 'موعد النور مناسبة لبنتك'))]);
        $reply = app(SalesProvider::class)->answer($turn, []);
        $this->assertSame([], $reply['result']['recommendations']);
        $this->assertStringNotContainsString('موعد النور', $reply['result']['message']);
        $this->assertStringContainsString('ما لقيتش ترشيحًا مؤكدًا', $reply['result']['message']);
        $this->assertSame([], app(SalesCatalog::class)->search('عاوز قصة عن الصلاة', 6, 'girl'));
    }

    public function test_mcp_cannot_override_child_facts_with_contradictory_search_arguments_or_direct_item_lookup(): void
    {
        $this->salesEnabled();
        $girl = $this->salesStory('girl-prayer', 'نور الصلاة', 'حب الصلاة', '٦ - ٩ سنوات');
        $girl->update(['gender' => 'girl']);
        $boy = $this->salesStory('boy-prayer', 'صلاة الفجر مع بابا', 'حب الصلاة');
        $boy->update(['gender' => 'boy', 'age_range' => '12-15']);
        $c = $this->salesConversation();
        $this->remember($c, 'بنتي 6 سنين');
        $turn = app(SalesBudget::class)->reserve($c, 'الصلاة', (string) Str::uuid());
        $turn->update(['status' => 'processing']);
        $capabilities = app(SalesCapabilities::class);
        $resolved = $capabilities->resolve($capabilities->issue($turn, ['line_count' => 0, 'items' => []]));
        $result = $capabilities->tool($resolved, 'search_catalog', ['query' => 'الصلاة', 'gender' => 'boy', 'age' => 12]);
        $this->assertSame(['story:'.$girl->id], array_column($result['items'], 'key'));
        foreach (['get_catalog_item', 'get_purchase_requirements'] as $name) {
            try {
                $capabilities->tool($resolved, $name, ['key' => 'story:'.$boy->id]);
                $this->fail('Contradictory catalog item was exposed.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $this->assertSame(1, $turn->fresh()->tool_calls);
    }

    public function test_manual_catalog_fallback_uses_the_same_owned_child_context_without_ai(): void
    {
        $this->salesEnabled();
        $girl = $this->salesStory('girl-prayer', 'نور الصلاة', 'حب الصلاة');
        $girl->update(['gender' => 'girl']);
        $boy = $this->salesStory('boy-prayer', 'موعد النور', 'حب الصلاة');
        $boy->update(['gender' => 'boy', 'age_range' => '12-15']);
        $this->openSales();
        $this->remember(SalesConversation::sole(), 'بنتي 6 سنين');
        $this->postJson(route('sales.catalog'), ['query' => 'الصلاة', 'age' => 12, 'gender' => 'boy'])
            ->assertOk()->assertJsonCount(1, 'cards')->assertJsonPath('cards.0.key', 'story:'.$girl->id);
        Http::assertNothingSent();
    }

    public function test_ambiguous_children_get_a_clarification_without_any_model_request(): void
    {
        $this->salesEnabled();
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'عندي ولد 4 سنين وبنت 6 سنين رشحلي قصة', (string) Str::uuid());
        $reply = app(SalesProvider::class)->answer($turn, []);
        $this->assertSame([], $reply['result']['recommendations']);
        $this->assertSame(['input_tokens' => 0, 'output_tokens' => 0], $reply['usage']);
        Http::assertNothingSent();
    }

    public function test_old_placeholder_is_cleaned_for_display_without_modifying_paid_history_or_receipts(): void
    {
        $c = $this->salesConversation();
        $turn = $this->remember($c, 'رشحلي قصة لبنتي');
        $turn->update(['result' => ['message' => '[بيانات خاصة في النموذج]، كام سنة عمرها؟', 'recommendations' => []], 'spent_micros' => 230]);
        $raw = $turn->getRawOriginal('result');
        $visible = app(SalesSessions::class)->turns($c);
        $this->assertSame('كام سنة عمرها؟', $visible[0]['result']['message']);
        $this->assertSame($raw, $turn->fresh()->getRawOriginal('result'));
        $this->assertSame(230, $turn->fresh()->spent_micros);
    }

    public function test_cart_completion_is_delivery_navigation_not_a_submitted_order_or_refusal_to_collect_details(): void
    {
        $this->salesEnabled();
        $this->salesStory();
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'ممكن ابعتلك البيانات وتكملي الاوردر؟', (string) Str::uuid());
        $reply = $this->salesReply([['key' => 'story:99999999', 'reason' => 'اقتراح غير موثوق يجب إسقاطه دون تعطيل السلة الحالية']], 'مش محتاجة تبعتيها هنا. بيانات التخصيص والصور مكتملة.');
        $parsed = json_decode($reply['output'][0]['content'][0]['text'], true);
        $parsed['next'] = 'cart';
        $reply['output'][0]['content'][0]['text'] = json_encode($parsed);
        Http::fake(['*' => Http::response($reply)]);
        $cart = ['line_count' => 1, 'items' => [['title' => 'قصة', 'has_child_data' => true, 'has_photos' => true]],
            'checkout' => ['cart_is_submitted_order' => false, 'delivery_review_required' => true, 'confirmation_required' => true],
            'private_intake_fields_present' => []];
        $answer = app(SalesProvider::class)->answer($turn, $cart);
        $this->assertSame('cart', $answer['result']['next']);
        $this->assertStringContainsString('نكمّل طلبك هنا', $answer['result']['message']);
        $this->assertStringNotContainsString('مش محتاجة', $answer['result']['message']);
        $this->assertStringContainsString('قبل تأكيد الطلب', $answer['result']['message']);
        $this->assertDatabaseCount('orders', 0);
        Http::assertSent(function ($request) {
            $data = json_decode($request->data()['input'][0]['content'], true);
            $this->assertFalse($data['current_cart']['checkout']['cart_is_submitted_order']);
            $this->assertTrue($data['current_cart']['checkout']['delivery_review_required']);
            $this->assertSame('gpt-6-luna', $request->data()['model']);

            return true;
        });
    }

    public function test_empty_cart_cannot_be_presented_as_an_existing_order_for_checkout(): void
    {
        $this->salesEnabled();
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'عاوز اعمل اوردر', (string) Str::uuid());
        $reply = $this->salesReply([], 'الأوردر موجود ومكتمل');
        $parsed = json_decode($reply['output'][0]['content'][0]['text'], true);
        $parsed['next'] = 'cart';
        $reply['output'][0]['content'][0]['text'] = json_encode($parsed);
        Http::fake(['*' => Http::response($reply)]);
        $answer = app(SalesProvider::class)->answer($turn, ['line_count' => 0, 'items' => []]);
        $this->assertSame('discover', $answer['result']['next']);
        $this->assertStringContainsString('السلة فاضية', $answer['result']['message']);
        $this->assertDatabaseCount('orders', 0);
    }
}
