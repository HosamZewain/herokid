<?php

namespace App\Services\AdminAssistant;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** The HTTP reader and offline evaluation must reject the same model contracts. */
final class AssistantPlanValidator
{
    public function validate(array $plan, array $tools): array
    {
        // Reject unrecognized contracts rather than repairing or retrying model output.
        abort_if(array_diff(array_keys($plan), ['tool', 'reference', 'range', 'start_date', 'end_date', 'intent']), 422);

        return Validator::make($plan, [
            'tool' => ['required', Rule::in([...$tools, 'unsupported'])],
            'reference' => ['present', 'nullable', 'string', 'max:32', 'regex:/^(?:[1-9][0-9]*|HK[0-9]{1,2}-[0-9]+)$/'],
            'range' => ['required', Rule::in(['page', 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_year', 'custom'])],
            'start_date' => 'present|nullable|date_format:Y-m-d', 'end_date' => 'present|nullable|date_format:Y-m-d|after_or_equal:start_date',
            'intent' => ['required', Rule::in(['read', 'execute'])],
        ])->validate();
    }
}
