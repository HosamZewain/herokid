<?php

namespace App\Services\Stories;

class StorySceneParser
{
    public const SCENE_COUNT = 13;

    /**
     * @return array<int, array{scene_number: int, title: string, text_template: string}>|null
     */
    public function parse(?string $content): ?array
    {
        $text = $this->normalizeContent((string) $content);

        if ($text === '') {
            return null;
        }

        preg_match_all(
            '/(?:^|\R)\s*(?:Scene|مشهد)\s*([0-9٠-٩]+)\s*[:：\\-–]?\s*(.*?)(?=(?:\R\s*(?:Scene|مشهد)\s*[0-9٠-٩]+\s*[:：\\-–]?)|\z)/su',
            $text,
            $matches,
            PREG_SET_ORDER,
        );

        if (count($matches) !== self::SCENE_COUNT) {
            return null;
        }

        $scenes = [];

        foreach ($matches as $match) {
            $sceneNumber = $this->normalizeNumber((string) ($match[1] ?? ''));

            if ($sceneNumber < 1 || $sceneNumber > self::SCENE_COUNT || isset($scenes[$sceneNumber])) {
                return null;
            }

            $body = trim((string) ($match[2] ?? ''));
            $lines = collect(preg_split('/\R/u', $body) ?: [])
                ->map(fn (string $line): string => trim($line))
                ->filter(fn (string $line): bool => $line !== '')
                ->values();

            $title = '';
            $writtenText = $body;

            if ($lines->count() > 1) {
                $title = (string) $lines->first();
                $writtenText = $lines->slice(1)->implode("\n");
            }

            $scenes[$sceneNumber] = [
                'scene_number' => $sceneNumber,
                'title' => $title,
                'text_template' => $writtenText,
            ];
        }

        ksort($scenes);

        if (array_keys($scenes) !== range(1, self::SCENE_COUNT)) {
            return null;
        }

        return array_values($scenes);
    }

    private function normalizeNumber(string $number): int
    {
        return (int) strtr($number, [
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);
    }

    private function normalizeContent(string $content): string
    {
        $content = preg_replace('/<(?:br\s*\/?|\/p|\/div|\/h[1-6])>/iu', "\n", $content) ?? $content;
        $content = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace(["\r\n", "\r"], "\n", $content));
    }
}
