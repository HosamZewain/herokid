<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class InternationalMobileNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Phone::isValidMobile($value)) {
            $fail('أدخل رقم موبايل صحيحًا. الرقم المصري مقبول بصيغته العادية، وللأرقام الدولية استخدم رمز الدولة.');
        }
    }
}
