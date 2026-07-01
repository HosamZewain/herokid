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
}
