<?php

namespace Tests\Unit;

use App\Services\RoboDesk\Conversations\ReceiptClassification;
use PHPUnit\Framework\TestCase;

class ReceiptClassificationTest extends TestCase
{
    private function message(): array
    {
        return ['direction' => 'in', 'type' => 'image', 'attachments' => [['file' => 'receipt.png']],
            'classification' => ['type' => 'payment_receipt', 'source' => 'ai', 'at' => '2026-11-01T13:00:00+02:00']];
    }

    public function test_recognizes_document_submission_only_and_discards_extra_claims(): void
    {
        $message = $this->message();
        $message['classification'] += ['amount' => 440, 'paid' => true, 'url' => 'https://example.test/private'];
        $this->assertSame(['type' => 'payment_receipt', 'source' => 'ai', 'at' => '2026-11-01T11:00:00.000000Z'], ReceiptClassification::fromMessage($message));
    }

    public function test_unknown_classifications_and_acknowledgment_text_are_not_receipt_evidence(): void
    {
        foreach ([null, [], 'payment_receipt', ['type' => 'payment_receipt'],
            ['type' => 'other', 'source' => 'ai', 'at' => '2026-11-01T11:00:00Z'],
            ['type' => 'payment_receipt', 'source' => 'unknown', 'at' => '2026-11-01T11:00:00Z']] as $value) {
            $message = $this->message();
            $message['classification'] = $value;
            $message['text'] = 'وصلني إثبات التحويل 💛 الفريق هيراجعه';
            $this->assertNull(ReceiptClassification::fromMessage($message));
        }
        foreach ([['direction' => 'out'], ['type' => 'text'], ['attachments' => []]] as $override) {
            $this->assertNull(ReceiptClassification::fromMessage(array_replace($this->message(), $override)));
        }
    }

    public function test_invalid_classification_dates_remain_unknown_without_breaking_history(): void
    {
        foreach ([null, 123, [], 'tomorrow', '2026-11-01T11:00:00', '2026-02-30T11:00:00Z', '2026-11-01T25:00:00Z'] as $at) {
            $message = $this->message();
            $message['classification']['at'] = $at;
            $this->assertNull(ReceiptClassification::fromMessage($message));
        }
    }
}
