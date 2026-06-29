<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class StoryProductionPrompt
{
    private const NOT_AVAILABLE = 'Not available';

    public static function forOrder(Order $order): string
    {
        $story = $order->story;
        $age = self::value($order->child_age);

        return strtr(self::template(), [
            '[ORDER_NUMBER]' => self::value($order->order_number),
            '[ORDER_URL]' => route('admin.orders.show', $order),
            '[LANGUAGE]' => self::language($order->language ?? $story?->language),
            '[CHILD_NAME]' => self::value($order->child_name),
            '[AGE]' => $age,
            '[GENDER]' => self::gender($order->child_gender),
            '[INTERESTS]' => self::value($order->interests),
            '[LESSON]' => self::value($order->lesson ?? $story?->lesson_value),
            '[DEDICATION]' => self::value($order->gift_note),
            '[STORY_TITLE]' => self::value($story?->title),
            '[STORY_SHORT_DESCRIPTION]' => self::value($story?->short_desc),
            '[STORY_CONTENT]' => self::storyContent($story?->full_story ?? $story?->full_desc),
            '[CUSTOMER_NOTES]' => self::value($order->parent_notes),
            '[CHILD_IMAGE_REFERENCES]' => self::childImageReferences($order),
        ]);
    }

    private static function value(mixed $value): string
    {
        if ($value === null) {
            return self::NOT_AVAILABLE;
        }

        $cleaned = self::cleanText((string) $value);

        return $cleaned === '' ? self::NOT_AVAILABLE : $cleaned;
    }

    private static function storyContent(?string $value): string
    {
        if ($value === null) {
            return self::NOT_AVAILABLE;
        }

        $cleaned = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $cleaned = preg_replace("/[ \t]+\n/", "\n", $cleaned) ?? '';
        $cleaned = preg_replace("/\n{3,}/", "\n\n", $cleaned) ?? '';

        return $cleaned === '' ? self::NOT_AVAILABLE : $cleaned;
    }

    private static function cleanText(string $value): string
    {
        return Str::squish(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function language(?string $language): string
    {
        return match ($language) {
            'ar' => 'Arabic',
            'en' => 'English',
            default => self::value($language),
        };
    }

    private static function gender(?string $gender): string
    {
        return match ($gender) {
            'boy' => 'Boy',
            'girl' => 'Girl',
            default => self::value($gender),
        };
    }

    private static function childImageReferences(Order $order): string
    {
        $photos = array_values(array_filter($order->uploaded_photos ?? [], 'is_string'));

        if ($photos === []) {
            return 'No child images were attached to this order.';
        }

        return collect($photos)
            ->map(fn (string $photo, int $index): string => ($index + 1) . '. ' . URL::signedRoute('orders.production-photo', [
                'order' => $order,
                'index' => $index,
            ]))
            ->implode("\n");
    }

    private static function template(): string
    {
        return <<<'PROMPT'
# Hero Kid – Story Production Brief

You are producing a personalized, print-ready Hero Kid children’s story.

## Order Information
- Order number: [ORDER_NUMBER]
- Order page URL: [ORDER_URL]
- Production language: [LANGUAGE]

## Child Profile
- Child name: [CHILD_NAME]
- Age: [AGE]
- Gender: [GENDER]
- Interests / favorite themes: [INTERESTS]
- Educational lesson / value: [LESSON]
- Dedication text: [DEDICATION]

## Selected Story
- Story title: [STORY_TITLE]
- Short description: [STORY_SHORT_DESCRIPTION]
- Full story description / selected story content:
[STORY_CONTENT]

## Customer Notes
[CUSTOMER_NOTES]

## Child Image References
Use the following child images as the visual reference for the main character. Preserve the child’s recognizable facial identity, age appearance, skin tone, hairstyle, and general proportions consistently across all illustrations.

[CHILD_IMAGE_REFERENCES]

## Required Deliverables

Create a complete Hero Kid personalized book package, including:

1. An original Arabic children’s story customized for the child’s name, age, gender, interests, selected theme, and educational lesson.
2. A front cover and back cover.
3. Interior story pages with one clear scene per page.
4. High-quality illustrations for every story page.
5. A print-ready A3 landscape production layout.
6. A preview contact sheet.
7. A production brief containing the final story text, page plan, image prompts, and quality-check result.

## Story Writing Rules

- Write an original story. Do not copy, reproduce, or closely imitate copyrighted characters, films, books, brands, or franchises.
- Keep the tone warm, magical, positive, and appropriate for the child’s age.
- Use simple Modern Standard Arabic suitable for a child aged [AGE].
- Use the child’s name naturally throughout the story without overusing it.
- Make the child the active hero of the story, not a passive observer.
- Include a clear beginning, challenge, adventure, resolution, and positive ending.
- Make the educational lesson appear naturally through the story events, not as a forced lecture.
- Avoid frightening scenes, violence, sadness, inappropriate language, political content, religious controversy, or unsafe behavior.
- Keep each text page concise and readable. Target approximately 35–60 Arabic words per text page.
- Create a clear page-by-page story plan before generating illustrations.

## Visual Consistency Rules

- Keep the child’s face, hairstyle, skin tone, apparent age, and body proportions consistent in every illustration.
- Use the supplied child photos only as reference for the child’s visual identity.
- Do not transform the child into a different-looking character.
- The art style must be polished, colorful, magical, premium children’s-book artwork.
- Maintain one consistent art direction, character design, lighting style, and color palette across the complete book.
- Each illustration must have one clear focal point and sufficient clean negative space where appropriate.
- Do not place text over the child’s face, hands, important objects, or key visual action.
- Do not generate any visible text inside the illustrations unless explicitly required for a title or approved design element.

## Print Layout Rules

The final book will be printed on A3 paper, duplex, folded from the center, then stapled along the fold to become an A4 portrait booklet.

Therefore:

- Final production spreads must be A3 landscape.
- Each A3 spread must contain exactly two A4 portrait pages side by side.
- Final A3 landscape canvas size must be exactly: `4961 × 3508 px` at `300 DPI`.
- Each A4 page area must be exactly: `2480 × 3508 px` at `300 DPI`.
- Keep safe margins of at least `120 px` from all trim edges.
- Keep all critical text, faces, and important visual elements at least `160 px` away from the center fold / gutter.
- No text may cross the center fold.
- No text may be cut off or placed too close to any edge.
- Text must appear inside a clean, readable, intentionally designed text area. Never place it directly over busy artwork.
- Use clear Arabic-supporting fonts that remain readable when printed.
- The cover must be designed according to the final booklet page count and appear correctly after folding.
- Add a back cover containing Hero Kid branding and the website: `hero-kid.com`.
- The total final booklet page count must be divisible by 4.
- Generate a print-order manifest showing the correct A3 sheet order for duplex booklet printing and center stapling.

## Quality Assurance Checklist

Before marking the work complete, verify and report all of the following:

- Child name is correct everywhere.
- Gender and pronouns are correct.
- Story language is correct.
- Child appearance is visually consistent across all pages.
- No copyrighted character, brand, movie, or book was copied or closely imitated.
- Every final A3 spread is exactly `4961 × 3508 px`.
- Every final spread includes `300 DPI` metadata.
- All text and critical elements respect safe margins.
- No text overlaps faces or important visual elements.
- No Arabic text is reversed, broken, cut off, or unreadable.
- The final booklet page count is divisible by 4.
- Front cover, interior pages, and back cover are included.
- A preview contact sheet is included.
- The final output folder is clearly named using the order number.
- A final QA report is included.

Do not change the live order status and do not upload final files automatically unless explicitly requested.
PROMPT;
    }
}
