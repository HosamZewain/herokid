<?php

namespace App\Services\Cart;

use App\Models\Story;
use App\Services\Pricing\StoryPricingService;

class StoryCartItemBuilder
{
    public function __construct(private readonly StoryPricingService $pricing) {}

    public function build(Story $story, string $itemKey, array $personalization, array $photoPaths): array
    {
        $price = $this->pricing->snapshot($story);

        return [
            'key' => $itemKey,
            'item_type' => 'story',
            'story_id' => $story->id,
            'story_title' => $story->title,
            'story_slug' => $story->slug,
            'story_price' => $price['effective_price'],
            'story_regular_price' => $price['regular_price'],
            'story_offer_applied' => $price['offer_applied'],
            'story_offer_label' => $price['offer_label'],
            'story_cover_url' => $story->cover_url,
            'story_language' => $story->language,
            'story_lesson' => $story->lesson_value,
            'child_name' => $personalization['child_name'],
            'child_age' => $personalization['child_age'],
            'child_gender' => $personalization['child_gender'],
            'interests' => $personalization['interests'] ?? null,
            'gift_note' => $personalization['gift_note'] ?? null,
            'parent_notes' => $personalization['parent_notes'] ?? null,
            'uploaded_photos' => array_values($photoPaths),
            'child_identity_request_id' => $personalization['child_identity_request_id'] ?? null,
            'child_identity_approved_attempt_id' => $personalization['child_identity_approved_attempt_id'] ?? null,
            'child_identity_cost_usd' => $personalization['child_identity_cost_usd'] ?? null,
        ];
    }
}
