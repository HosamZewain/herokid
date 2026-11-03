<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MobileFamilyPrivacyDisclosureTest extends TestCase
{
    public function test_policy_discloses_actual_providers_and_separates_usage_from_customer_media(): void
    {
        $policy = file_get_contents(__DIR__.'/../../resources/views/front/pages/privacy.blade.php');
        foreach (['03/11/2026', 'OpenAI API', 'Expo', 'APNs', 'FCM', 'Firebase Analytics', '7 أيام', 'لا توجد حاليًا مدة حذف تلقائي ثابتة', 'لا نربط أحداث اللعب بحساب الشراء', 'موافقة ولي الأمر قبل الرفع'] as $text) {
            self::assertStringContainsString($text, $policy);
        }
        self::assertStringNotContainsString("date('d/m/Y')", $policy);
        self::assertStringNotContainsString('جميع الأطراف الثالثة ملزمون بعقود سرية صارمة', $policy);
    }
}
