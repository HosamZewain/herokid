<?php

namespace App\Services\Stories;

use App\Models\Order;
use App\Models\Story;

class StorySceneTemplateRenderer
{
    public const SUPPORTED_VARIABLES = [
        'child_name',
        'child_age',
        'story_title',
    ];

    /**
     * @return list<string>
     */
    public function unknownVariables(?string $template): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/u', (string) $template, $matches);

        return collect($matches[1] ?? [])
            ->unique()
            ->reject(fn (string $variable): bool => in_array($variable, self::SUPPORTED_VARIABLES, true))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $context
     */
    public function render(?string $template, array $context): string
    {
        $rendered = (string) $template;

        foreach (self::SUPPORTED_VARIABLES as $variable) {
            $rendered = preg_replace(
                '/\{\{\s*'.preg_quote($variable, '/').'\s*\}\}/u',
                (string) ($context[$variable] ?? ''),
                $rendered,
            ) ?? $rendered;
        }

        return trim($rendered);
    }

    /**
     * @return array{child_name: string, child_age: string, story_title: string, child_gender: string, story_gender: string}
     */
    public function contextForOrder(Order $order, ?Story $story = null): array
    {
        $story ??= $order->story;

        return [
            'child_name' => trim((string) $order->child_name),
            'child_age' => trim((string) $order->child_age),
            'story_title' => trim((string) $story?->title),
            'child_gender' => trim((string) $order->child_gender),
            'story_gender' => trim((string) $story?->gender),
        ];
    }
}
