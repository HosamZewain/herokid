<?php

namespace App\Services\Stories;

use App\Models\Order;
use App\Models\Story;
use App\Models\StorySceneTemplate;

class ProductionSceneVariantResolver
{
    public static function gender(?string $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'girl', 'female' => 'female',
            'boy', 'male' => 'male',
            default => null,
        };
    }

    /** Existing storage: original belongs to story.gender, alternate to the opposite gender.
     * A both/unspecified story is explicitly neutral; never infer grammar from text.
     */
    public function resolve(?StorySceneTemplate $template, Order $order, ?Story $story): array
    {
        $base = self::gender($story?->gender);
        $child = self::gender($order->child_gender);
        $alternate = $base !== null && $child !== null && $base !== $child;
        if ($alternate && filled($template?->alternate_text_template)) {
            return ['text' => $template->alternate_text_template, 'variant' => 'alternate',
                'resolved_variant' => $child, 'uses_fallback' => false];
        }

        return ['text' => $template?->text_template,
            'variant' => $alternate ? 'original_fallback' : 'original',
            'resolved_variant' => $base ?? 'neutral', 'uses_fallback' => $alternate];
    }
}
