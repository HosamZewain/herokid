<?php

namespace App\Services\RoboDesk\Conversations;

use App\Support\Phone;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

class ConversationPhone
{
    public static function canonical(?string $value): ?string
    {
        $value = Phone::normalize($value);
        if ($value === null) {
            return null;
        }
        $util = PhoneNumberUtil::getInstance();
        $candidates = str_starts_with($value, '+') ? [[$value, null]] : [[$value, 'EG'], ['+'.$value, null]];
        foreach ($candidates as [$candidate, $region]) {
            try {
                $number = $util->parse($candidate, $region);
                if ($util->isValidNumber($number) && in_array($util->getNumberType($number), [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)) {
                    return $util->format($number, PhoneNumberFormat::E164);
                }
            } catch (NumberParseException) {
                continue;
            }
        }

        return null;
    }
}
