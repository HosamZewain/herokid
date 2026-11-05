<?php

namespace Tests\Feature;

use App\Services\AdminAssistant\AssistantPlanValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminAssistantPlanValidatorTest extends TestCase
{
    private function valid(): array
    {
        return ['tool' => 'order', 'reference' => '123', 'range' => 'page', 'start_date' => null, 'end_date' => null, 'intent' => 'read'];
    }

    public function test_http_and_evaluation_contract_reject_unavailable_tool(): void
    {
        $this->expectException(ValidationException::class);
        app(AssistantPlanValidator::class)->validate(array_replace($this->valid(), ['tool' => 'payments']), ['order']);
    }

    public function test_incomplete_model_output_is_not_accepted_as_correct(): void
    {
        $this->expectException(ValidationException::class);
        app(AssistantPlanValidator::class)->validate(['tool' => 'order', 'reference' => '123'], ['order']);
    }

    public function test_valid_contract_and_execute_intent_are_preserved(): void
    {
        $plan = array_replace($this->valid(), ['intent' => 'execute']);
        $this->assertSame($plan, app(AssistantPlanValidator::class)->validate($plan, ['order']));
    }

    public function test_arbitrary_order_reference_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(AssistantPlanValidator::class)->validate(array_replace($this->valid(), ['reference' => '/etc/passwd']), ['order']);
    }
}
