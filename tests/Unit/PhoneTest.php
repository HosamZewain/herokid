<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    #[DataProvider('validMobileNumbers')]
    public function test_it_accepts_valid_egyptian_and_international_mobile_numbers(string $phone): void
    {
        $this->assertTrue(Phone::isValidMobile($phone));
    }

    public static function validMobileNumbers(): array
    {
        return [
            'Egyptian local number' => ['01012345678'],
            'Egyptian Arabic digits' => ['٠١٠١٢٣٤٥٦٧٨'],
            'Saudi international number' => ['+966512345678'],
            'UK international number' => ['+447911123456'],
            'US fixed-or-mobile numbering plan' => ['+12025550123'],
            'international digits without plus' => ['966512345678'],
        ];
    }

    #[DataProvider('invalidMobileNumbers')]
    public function test_it_rejects_invalid_or_fixed_line_numbers(string $phone): void
    {
        $this->assertFalse(Phone::isValidMobile($phone));
    }

    public static function invalidMobileNumbers(): array
    {
        return [
            'too short' => ['12345'],
            'Egyptian fixed line' => ['+20223456789'],
            'letters only' => ['not-a-phone'],
            'blank' => [''],
        ];
    }

    public function test_normalize_preserves_the_existing_storage_contract_and_converts_arabic_digits(): void
    {
        $this->assertSame('01012345678', Phone::normalize('٠١٠ ١٢٣٤ ٥٦٧٨'));
        $this->assertSame('+966512345678', Phone::normalize('+966 51 234 5678'));
    }
}
