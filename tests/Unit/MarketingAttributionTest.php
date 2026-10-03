<?php

namespace Tests\Unit;

use App\Support\MarketingAttribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarketingAttributionTest extends TestCase
{
    #[DataProvider('sources')]
    public function test_source_is_classified_from_evidence_not_the_website_channel(array $data, string $type): void
    {
        $this->assertSame($type, MarketingAttribution::present($data)['type']);
    }

    public static function sources(): array
    {
        return [
            'meta paid UTM' => [['utm_source' => 'Facebook', 'utm_medium' => 'paid_social'], 'meta_ad'],
            'instagram ad ID' => [['utm_source' => 'ig', 'ad_id' => '123'], 'meta_ad'],
            'meta click with ad ID' => [['fbclid' => 'click', 'ad_id' => '123'], 'meta_ad'],
            'fbclid is not paid evidence' => [['fbclid' => 'click'], 'meta'],
            'organic facebook referral' => [['referrer' => 'https://l.facebook.com/'], 'meta'],
            'meta UTM without paid evidence' => [['utm_source' => 'instagram'], 'meta'],
            'other paid source' => [['utm_source' => 'google', 'utm_medium' => 'cpc'], 'campaign'],
            'direct landing' => [['landing_url' => 'https://hero-kid.com/shop'], 'direct'],
            'untracked legacy order' => [[], 'unknown'],
            'internal referral is not direct' => [['landing_url' => 'https://hero-kid.com/cart', 'referrer' => 'https://www.hero-kid.com/shop'], 'unknown'],
            'external referral' => [['landing_url' => 'https://hero-kid.com/shop', 'referrer' => 'https://example.com/'], 'referral'],
            'lookalike meta domain' => [['referrer' => 'https://facebook.com.example.com/'], 'referral'],
        ];
    }

    public function test_ad_names_are_not_guessed_from_campaign_or_content(): void
    {
        $result = MarketingAttribution::present(['utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'Summer', 'utm_content' => 'video_a']);
        $this->assertNull($result['ad_name']);
        $this->assertSame('video_a', $result['content']);
        $this->assertSame('Summer', $result['campaign']);
    }

    public function test_structured_names_and_ids_are_preserved(): void
    {
        $result = MarketingAttribution::present(['utm_source' => 'meta', 'utm_medium' => 'paid_social', 'campaign_name' => 'قصص الصيف', 'adset_name' => 'مجموعة أ', 'ad_name' => 'فيديو ميا', 'ad_id' => '123', 'adset_id' => '456', 'campaign_id' => '789']);
        foreach (['campaign_name' => 'قصص الصيف', 'adset_name' => 'مجموعة أ', 'ad_name' => 'فيديو ميا', 'ad_id' => '123', 'adset_id' => '456', 'campaign_id' => '789'] as $key => $value) {
            $this->assertSame($value, $result[$key]);
        }
    }

    public function test_arrays_unknown_parameters_and_unresolved_macros_are_ignored_and_values_fit_columns(): void
    {
        $result = MarketingAttribution::sanitize(['utm_source' => ['meta'], 'ad_name' => '{{ad.name}}', 'utm_campaign' => str_repeat('a', 600), 'fbclid' => str_repeat('b', 800), 'token' => 'secret']);
        $this->assertSame(['utm_campaign' => str_repeat('a', 255), 'fbclid' => str_repeat('b', 512)], $result);
    }

    public function test_raw_urls_and_click_ids_are_not_returned_in_display_data(): void
    {
        $result = MarketingAttribution::present(['landing_url' => 'https://hero-kid.com/private/secret-path?token=secret-query', 'referrer' => 'https://facebook.com/?secret=secret-referrer', 'fbclid' => 'secret-click-id']);
        $encoded = json_encode($result);
        $this->assertStringNotContainsString('secret-', $encoded);
        $this->assertSame('hero-kid.com', $result['landing_site']);
        $this->assertTrue($result['meta_click']);
    }

    public function test_manual_creation_channel_remains_available_when_marketing_is_unknown(): void
    {
        $this->assertSame('واتساب', MarketingAttribution::present([], 'whatsapp')['label']);
    }
}
