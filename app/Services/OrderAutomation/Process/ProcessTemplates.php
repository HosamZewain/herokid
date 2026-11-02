<?php

namespace App\Services\OrderAutomation\Process;

/** Explicit provider contracts, not an unrestricted template sender. */
class ProcessTemplates
{
    public const REVIEW = 'herokid_preview_review_payment';

    public const REVIEW_FIELDS = ['customer_name', 'order_reference', 'review_url', 'remaining_amount'];

    public const IDENTITY = 'herokid_identity_review_payment';

    public const IDENTITY_FIELDS = ['customer_name', 'order_reference', 'story_names', 'child_names', 'product_names', 'identity_preview_url', 'order_total', 'remaining_amount'];

    public const EXTRA_VARIABLES = ['review_url' => 'رابط المعاينة المجمعة الثابتة', 'story_names' => 'أسماء القصص', 'child_names' => 'أسماء الأطفال', 'product_names' => 'أسماء المنتجات', 'identity_preview_url' => 'رابط معاينة هويات هذا المسار', 'remaining_amount' => 'الرصيد المتبقي بعد المدفوعات'];

    public static function fields(string $template): ?array
    {
        return match ($template) {
            'herokid_order_confirmation' => array_keys(ProcessDefinition::VARIABLES),
            self::IDENTITY => self::IDENTITY_FIELDS,
            self::REVIEW => self::REVIEW_FIELDS,
            default => null,
        };
    }
}
