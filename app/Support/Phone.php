<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

class Phone
{
    public static function normalize(?string $phone): ?string
    {
        $phone = trim(self::asciiDigits((string) $phone));

        if ($phone === '') {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', $phone) ?: '';

        if (str_starts_with($normalized, '00')) {
            $normalized = substr($normalized, 2);
        }

        return $normalized !== '' ? $normalized : null;
    }

    public static function isValidMobile(?string $phone, string $defaultRegion = 'EG'): bool
    {
        $phone = trim(self::asciiDigits((string) $phone));

        if ($phone === '') {
            return false;
        }

        $compact = preg_replace('/[^\d+]/', '', $phone) ?: '';

        if ($compact === '') {
            return false;
        }

        $candidates = [];

        if (str_starts_with($compact, '00')) {
            $candidates[] = ['+'.substr($compact, 2), null];
        } elseif (str_starts_with($compact, '+')) {
            $candidates[] = [$compact, null];
        } else {
            // Keep the familiar Egyptian local format (010...) frictionless.
            $candidates[] = [$compact, strtoupper($defaultRegion)];
            // Also accept international digits without forcing a leading plus.
            $candidates[] = ['+'.$compact, null];
        }

        $phoneUtil = PhoneNumberUtil::getInstance();

        foreach ($candidates as [$candidate, $region]) {
            try {
                $parsed = $phoneUtil->parse($candidate, $region);

                if (! $phoneUtil->isValidNumber($parsed)) {
                    continue;
                }

                if (in_array($phoneUtil->getNumberType($parsed), [
                    PhoneNumberType::MOBILE,
                    PhoneNumberType::FIXED_LINE_OR_MOBILE,
                ], true)) {
                    return true;
                }
            } catch (NumberParseException) {
                continue;
            }
        }

        return false;
    }

    public static function forWhatsApp(?string $phone, string $defaultCountryCode = '20'): ?string
    {
        $digits = preg_replace('/\D/', '', (string) self::normalize($phone)) ?: '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, $defaultCountryCode)) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $defaultCountryCode.$digits;
    }

    /** @return array<int, string> */
    public static function equivalentValues(?string $phone, string $defaultCountryCode = '20'): array
    {
        $normalized = self::normalize($phone);
        $international = self::forWhatsApp($phone, $defaultCountryCode);

        if ($international === null) {
            return array_values(array_filter([$normalized]));
        }

        $local = str_starts_with($international, $defaultCountryCode)
            ? '0'.substr($international, strlen($defaultCountryCode))
            : null;

        return array_values(array_unique(array_filter([
            $normalized,
            $international,
            '+'.$international,
            '00'.$international,
            $local,
        ])));
    }

    private static function asciiDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
