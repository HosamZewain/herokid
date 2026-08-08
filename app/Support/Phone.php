<?php

namespace App\Support;

class Phone
{
    public static function normalize(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', $phone) ?: '';

        if (str_starts_with($normalized, '00')) {
            $normalized = substr($normalized, 2);
        }

        return $normalized !== '' ? $normalized : null;
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
}
