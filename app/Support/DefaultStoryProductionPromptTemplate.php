<?php

namespace App\Support;

class DefaultStoryProductionPromptTemplate
{
    public static function text(): string
    {
        return strtr(self::legacyTemplate(), [
            '[ORDER_NUMBER]' => '{{order_number}}',
            '[ORDER_URL]' => '{{order_url}}',
            '[LANGUAGE]' => '{{production_language}}',
            '[CHILD_NAME]' => '{{child_name}}',
            '[AGE]' => '{{child_age}}',
            '[GENDER]' => '{{child_gender}}',
            '[INTERESTS]' => '{{child_interests}}',
            '[LESSON]' => '{{story_educational_value}}',
            '[DEDICATION]' => '{{dedication}}',
            '[STORY_TITLE]' => '{{story_title}}',
            '[STORY_AGE_RANGE]' => '{{story_age_range}}',
            '[STORY_SHORT_DESCRIPTION]' => '{{story_short_description}}',
            '[STORY_CONTENT]' => '{{story_full_content}}',
            '[CUSTOMER_NOTES]' => '{{customer_notes}}',
            '[CHILD_IMAGE_REFERENCES]' => '{{child_image_references}}',
        ]);
    }

    private static function legacyTemplate(): string
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
- Selected story age range: [STORY_AGE_RANGE]
- Short description: [STORY_SHORT_DESCRIPTION]
- Full story description / selected story content:
[STORY_CONTENT]

Important age handling rule:
The parent intentionally selected this story template and age range. Use the selected story age range as the primary guide for writing level, pacing, vocabulary, puzzle complexity, and emotional depth. Use the child’s actual age only for personalization and visual appearance. Do not automatically simplify the story based on the child’s age unless the order explicitly requests that.

## Customer Notes
[CUSTOMER_NOTES]

## Child Image References
Use the following child images as the visual reference for the main character. Preserve the child’s recognizable facial identity, age appearance, skin tone, hairstyle, and general proportions consistently across all illustrations.

[CHILD_IMAGE_REFERENCES]

## Required Deliverables

Create a complete Hero Kid personalized book package, including:

1. Final Arabic story content for exactly 13 scenes.
2. A front cover for Page 1.
3. A back cover for Page 28.
4. Reader-order scene layouts for Pages 2–27.
5. High-quality connected illustrations for all 13 story scenes.
6. Reader-order preview files.
7. Print-imposed A3 landscape files for 7 duplex sheets.
8. A print-order manifest.
9. A contact sheet preview.
10. A production brief containing final story text, scene plan, image prompts, and QA result.
11. A final QA report.

## Fixed Booklet Format

The final Hero Kid book must always contain exactly 28 A4 portrait pages.

Physical print format:
- 7 physical A3 sheets
- Duplex printed
- Folded from the center
- Stapled along the center fold
- 14 printed A3 landscape sides
- 28 final A4 portrait booklet pages

Reader page structure:
- Page 1: Front cover
- Pages 2–27: Interior story content
- Page 28: Back cover

Story structure:
- The story must contain exactly 13 complete scenes.
- Each scene occupies one logical reader spread consisting of two consecutive A4 pages.
- Scene 1 uses pages 2–3.
- Scene 2 uses pages 4–5.
- Continue this pattern until Scene 13 uses pages 26–27.

Do not generate fewer or more than 28 A4 pages.
Do not generate fewer or more than 13 story scenes.
Do not add blank filler pages unless they are intentionally designed and approved as part of the reading experience.

## Reader Order and Print Imposition Rules

Design the book first in normal reader order.

Logical reader spreads:
- Front cover: Page 1
- Scene 1: Pages 2–3
- Scene 2: Pages 4–5
- Scene 3: Pages 6–7
- Scene 4: Pages 8–9
- Scene 5: Pages 10–11
- Scene 6: Pages 12–13
- Scene 7: Pages 14–15
- Scene 8: Pages 16–17
- Scene 9: Pages 18–19
- Scene 10: Pages 20–21
- Scene 11: Pages 22–23
- Scene 12: Pages 24–25
- Scene 13: Pages 26–27
- Back cover: Page 28

After designing the book in reader order, create a separate print-ready booklet-imposed A3 output for duplex printing, center folding, and center stapling.

The final production output must include:
1. Reader-order preview files.
2. Print-imposed A3 landscape files.
3. A print-order manifest showing the exact sheet number, front side, back side, page numbers, and flip direction.

Do not confuse reader-order scene spreads with printer-imposed A3 sheet sides.

## Story Writing Rules

- Write an original story. Do not copy, reproduce, or closely imitate copyrighted characters, films, books, brands, or franchises.
The child’s interests are parent-provided creative preferences and may mention known characters, films, books, brands, or franchises. Preserve those interests exactly as context and use them only as broad creative inspiration for mood, themes, adventure type, colors, setting, or emotional tone.

Do not copy, reproduce, name, visually imitate, or create a confusingly similar version of any copyrighted character, franchise, costume, world, logo, or signature design. Create an original Hero Kid world, original supporting characters, and original visual identity inspired only by the general themes requested by the parent.
- Keep the tone warm, magical, positive, and appropriate for the selected story age range.
- Use Modern Standard Arabic suitable for the selected story age range: [STORY_AGE_RANGE].
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

## Spread Illustration and Text Layout Rules

Each of the 13 story scenes must be designed as one single connected full-width A3 landscape illustration across two facing A4 pages.

- Every scene must feel like one complete cinematic moment.
- The artwork must continue naturally from one A4 page to the other.
- Do not place two unrelated illustrations next to each other.
- The main visual action and primary character focus may appear on either page.
- The facing page must contain a natural continuation of the same scene.
- The story text must appear over a calm, low-detail, readable area within that continuation.
- The text page must still include artwork as part of the same connected scene.
- Reserve a clean, low-detail, high-contrast area behind the text.
- Use an intentional readable treatment when needed, such as a subtle translucent panel, parchment area, clean sky, calm water, wall, sand, mist, or other low-detail environment.
- Do not place text over the child’s face, hands, body, important objects, major action, glowing effects, detailed foliage, detailed patterns, or high-contrast areas.
- Text placement may alternate between the right and left page across scenes when it improves composition and Arabic RTL reading flow.
- Maintain Arabic RTL reading flow throughout the full book.

## Print Layout Rules

- Final production canvas for each A3 spread: exactly 4961 × 3508 px.
- Orientation: A3 landscape.
- Resolution metadata: exactly 300 DPI.
- Each A3 canvas represents two facing A4 portrait page zones.
- The center fold / gutter is at the exact midpoint of the full A3 spread.
- Keep all critical text, faces, important objects, and key visual action at least 160 px away from the center fold.
- Keep all critical content at least 120 px away from the outer trim edges.
- No text may cross the center fold.
- No critical visual element may be unintentionally cut by the fold or trim.
- Use clear Arabic-supporting fonts that remain readable when printed.
- Do not state or enforce that each A4 half must be exactly 2480 px wide. Use the full A3 canvas and a center-fold guide.

## Quality Assurance Checklist

Before marking the work complete, verify and report all of the following:

- Child name is correct everywhere.
- Gender and pronouns are correct.
- Story language is correct.
- Child appearance is visually consistent across all pages.
- No copyrighted character, brand, movie, or book was copied or closely imitated.
- The book contains exactly 28 A4 pages.
- The book contains exactly 13 complete story scenes.
- Page 1 is the front cover.
- Page 28 is the back cover.
- Pages 2–27 contain the 13 story scenes in reader order.
- Every story scene is one connected illustration across two facing A4 pages.
- Every text area is placed over a calm, readable continuation of the same scene.
- Text does not cover the child’s face, hands, body, important action, or key objects.
- The selected story age range was used as the primary writing-complexity reference.
- The child’s raw parent-provided interests were preserved in the prompt.
- All reader-order layouts are complete.
- The booklet imposition is correct for 7 duplex A3 sheets.
- The print-order manifest includes sheet number, front side, back side, page numbers, and flip direction.
- Every final print A3 spread is exactly 4961 × 3508 px.
- Every final print spread includes 300 DPI metadata.
- All text and critical elements respect safe margins.
- No text overlaps faces or important visual elements.
- No Arabic text is reversed, broken, cut off, or unreadable.
- Front cover, interior pages, and back cover are included.
- A preview contact sheet is included.
- The final output folder is clearly named using the order number.
- A final QA report is included.

Do not change the live order status and do not upload final files automatically unless explicitly requested.
PROMPT;
    }
}
