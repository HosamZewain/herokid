<?php

namespace Tests\Feature;

use App\Models\SalesTurn;
use App\Services\SalesAssistant\SalesIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\SalesAssistantFixtures;
use Tests\TestCase;

class SalesAssistantIntakeTest extends TestCase
{
    use RefreshDatabase, SalesAssistantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->salesEnabled();
        $this->openSales();
    }

    public function test_batch_details_are_captured_once_without_ai_cost_or_private_values_in_history_events(): void
    {
        $nonce = (string) Str::uuid();
        $text = 'اسم الطفل: ليان، النوع: بنت، العمر: ٦، اسم ولي الأمر: مريم أحمد، رقم الموبايل: 01012345678، العنوان: 12 شارع الورد، الدور الثاني';
        $payload = ['message' => $text, 'client_nonce' => $nonce];
        $this->postJson(route('sales.message'), $payload)->assertStatus(202)->assertJsonPath('status', 'completed');
        $this->postJson(route('sales.message'), $payload)->assertStatus(202);
        $state = $this->postJson(route('sales.state'))->assertOk();
        $state->assertJsonPath('private_details.child_name', 'ليان')->assertJsonPath('private_details.child_gender', 'girl')
            ->assertJsonPath('private_details.child_age', '6')->assertJsonPath('private_details.parent_name', 'مريم أحمد')
            ->assertJsonPath('private_details.phone', '01012345678')->assertJsonPath('private_details.street', '12 شارع الورد، الدور الثاني');
        $this->assertDatabaseCount('sales_turns', 1);
        $this->assertDatabaseCount('orders', 0);
        $turn = SalesTurn::sole();
        foreach (['ليان', 'مريم أحمد', '01012345678', 'شارع الورد'] as $private) {
            $this->assertStringNotContainsString($private, $turn->request_text.json_encode($turn->result, JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsString($private, json_encode(DB::table('sales_assistant_events')->get(), JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsString($private, session('sales_assistant.intake'));
        }
        $this->assertEquals(0, $turn->spent_micros);
        $this->assertEquals(0, $turn->reserved_micros);
        $this->assertSame(1, DB::table('sales_assistant_events')->where('type', 'intake_received')->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_private_question_accepts_a_single_answer_but_never_guesses_an_unlabeled_name(): void
    {
        $parser = app(SalesIntake::class);
        $this->assertFalse($parser->extract('عاوز قصة عن الصلاة')['handled']);
        $this->assertFalse($parser->extract('مريم أحمد')['handled']);
        $this->assertFalse($parser->extract('ممكن قصة عن الصلاة؟', 'child_name')['handled']);
        $this->assertSame('12 شارع عمر بن الخطاب', $parser->extract('العنوان: 12 شارع عمر بن الخطاب')['fields']['street']);
        $colloquial = $parser->extract('بنتي ليان عندها ست سنين');
        $this->assertSame('ليان', $colloquial['fields']['child_name']);
        $this->assertSame('6', $colloquial['fields']['child_age']);
        $this->assertSame('girl', $colloquial['fields']['child_gender']);
        $this->postJson(route('sales.message'), ['message' => 'مريم أحمد', 'private_step' => 'parent_name', 'client_nonce' => (string) Str::uuid()])->assertStatus(202);
        $this->postJson(route('sales.state'))->assertJsonPath('private_details.parent_name', 'مريم أحمد');
        $this->postJson(route('sales.message'), ['message' => '6 سنين', 'private_step' => 'child_age', 'client_nonce' => (string) Str::uuid()])->assertStatus(202);
        $this->postJson(route('sales.state'))->assertJsonPath('private_details.child_age', '6');
        $this->postJson(route('sales.message'), ['message' => 'admin', 'private_step' => 'payment_status', 'client_nonce' => (string) Str::uuid()])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_multiple_children_and_two_phones_require_clarification_without_mixing(): void
    {
        $capture = app(SalesIntake::class)->extract('ابني اسمه عمر 4 سنين وبنتي اسمها ليان 6 سنين، موبايلي 01012345678 و01112345678');
        $this->assertTrue($capture['ambiguous']);
        $this->assertArrayNotHasKey('child_name', $capture['fields']);
        $this->assertArrayNotHasKey('child_age', $capture['fields']);
        $this->assertArrayNotHasKey('phone', $capture['fields']);
    }

    public function test_new_child_does_not_inherit_previous_age_gender_and_foreign_session_has_no_draft(): void
    {
        $this->postJson(route('sales.message'), ['message' => 'اسم الطفل: ليان، بنت 6 سنين', 'client_nonce' => (string) Str::uuid()])->assertStatus(202);
        $this->postJson(route('sales.message'), ['message' => 'اسم الطفل: يوسف', 'client_nonce' => (string) Str::uuid()])->assertStatus(202);
        $this->postJson(route('sales.state'))->assertJsonPath('private_details', ['child_name' => 'يوسف']);
        $this->withCookie(config('session.cookie'), 'unrelated-session-id');
        $this->postJson(route('sales.state'))->assertJsonPath('private_details', []);
        Http::assertNothingSent();
    }

    public function test_embedded_story_add_always_keeps_native_cart_without_next_or_with_continue_intent(): void
    {
        Storage::fake('local');
        config(['media.private_disk' => 'local']);
        $story = $this->salesStory();
        foreach ([null, 'stories'] as $next) {
            $this->post(route('cart.store', $story->slug), ['child_name' => 'طفلة تجريبية', 'child_age' => 6, 'child_gender' => 'girl', '_sales_assistant_embed' => 1, 'next' => $next,
                'photos' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png')]])
                ->assertSessionHasNoErrors()->assertRedirect(route('cart.index').'?_sales_assistant_embed=1');
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_model_cart_summary_exposes_only_draft_presence_and_still_requires_delivery_and_confirmation(): void
    {
        $this->postJson(route('sales.message'), ['message' => 'اسم ولي الأمر: مريم أحمد، رقم الموبايل: 01012345678، العنوان: 12 شارع الورد', 'client_nonce' => (string) Str::uuid()])->assertStatus(202);
        $this->withSession(['cart.items' => ['synthetic-line' => ['story_id' => $this->salesStory()->id, 'child_name' => 'طفل سري', 'uploaded_photos' => ['private/synthetic.png']]]]);
        $response = $this->postJson(route('sales.state'))->assertOk();
        $response->assertJsonPath('cart.checkout.cart_is_submitted_order', false)->assertJsonPath('cart.checkout.delivery_review_required', true)->assertJsonPath('cart.checkout.confirmation_required', true);
        $cart = $response->json('cart');
        $this->assertContains('phone', $cart['private_intake_fields_present']);
        foreach (['مريم أحمد', '01012345678', 'شارع الورد', 'طفل سري', 'private/synthetic.png'] as $value) {
            $this->assertStringNotContainsString($value, json_encode($cart, JSON_UNESCAPED_UNICODE));
        }
        Http::assertNothingSent();
    }
}
