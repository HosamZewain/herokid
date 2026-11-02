<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Catalog roles, not an instruction to execute a chargeable workflow. */
class ProductProductionStage
{
    public const LABELS = [
        'standard' => 'إنتاج عادي',
        'book_cover' => 'كفر كتاب التلوين — بعد تأكيد الطلب',
        'book_interior' => 'صفحات كتاب التلوين — بعد اعتماد الكفر وتسجيل دفعة',
    ];

    public static function coverPrompt(): string
    {
        return <<<'PROMPT'
HEROKID_COVER_ART_DIRECTION_V2
Create ONE premium full-color personalized children's coloring-book FRONT COVER, a coherent richly illustrated scene, not a collage or scrapbook.
Child: {{child_full_name}}. Age: {{child_age}}. Gender: {{child_gender}}.
Product: {{product_name}}. Parent notes: {{special_notes}}.
IDENTITY: The attached photos are multiple references of ONE child, not photographs to paste into a layout. Show the child ONCE as the main photorealistic subject. Preserve recognizable facial proportions, skin tone, age, hair and appearance. Never use a background person, merge children, mirror clothing text, or add secondary photographic portraits. Integrate the child into the scene with natural pose, coherent light, contact shadows and appropriate clothing; avoid a cropped floating head, white cutout halos or sticker outlines around the child.
ART DIRECTION: Build one believable, delightful setting inspired by the explicitly purchased theme and parent notes (e.g. a vivid sports setting for a sports theme, a magical art atelier for a fantasy theme). With no theme, use a joyful colorful art studio. Rich but controlled colors, depth, softly lit dimensional materials, purposeful foreground coloring pencils and an open coloring book. Include a few simple black-outline coloring drawings on actual pages or an easel within the scene, not a wallpaper of repeated faces. Keep the composition balanced and cinematic, never a collection of unrelated stickers.
TYPE: Upper quarter contains the exact child name in its original Arabic/English spelling, large and highly legible, with the exact subtitle "كتاب تلوين". Do not invent or translate names, surnames, dedications, ages, hobbies or words. Include extra printed text only when explicitly requested in the parent instructions; do not automatically add page-count badges, birthday banners or giant age numerals from raw variant metadata. Reference examples guide style only; none of their names or identities belong here.
LAYOUT: Flat portrait cover, not a 3D book, spine or back cover. Child is the visual focus in the middle, with expressive activity and enough surrounding scene to feel immersive. Keep the lower center as a naturally lit, low-detail part of the SAME scene for a small transparent publisher logo. It is not a blank hole: no white rectangle, empty cloud, dotted outline, speech bubble, bordered plaque or conspicuous logo placeholder. Do not draw any logo or publisher text; the application inserts the exact transparent HeroKid asset. Keep face, hands, title and important objects outside that lower-center logo footprint (central 24% width, bottom 13% height).
PRINT: Final A4 portrait 210 x 297 mm. Fill the full canvas with scene/background, no white sidebars or external border. If generated on a 2:3 portrait canvas, only the outermost 3% at top and bottom may be trimmed to A4, with no stretching. Keep ALL lettering, faces, hands and essential objects within the middle 84% of height and 90% of width, so they retain safe margins after trim. One finished cover artwork only.
Reference images: {{child_image_references}}
PROMPT;
    }

    public static function legacyCoverPrompt(): string
    {
        return <<<'PROMPT'
Create exactly ONE full-color, flat front-cover illustration for a personalized children's coloring book.
Child: {{child_full_name}}. Age: {{child_age}}. Gender: {{child_gender}}.
Product: {{product_name}}. Parent notes: {{special_notes}}.
Use only the attached reference photos for this child. Preserve the child's recognizable face, age, hair and appearance. Do not combine children or copy any background person.
Create a cheerful full-color A4 portrait front cover (210 x 297 mm composition), like a premium personalized coloring book. Keep the child's main face photorealistic and recognizable, surrounded by colorful pencils, a few small cartoon/line-art versions of THIS same child, and playful motifs.
Use the exact child name as a large readable Arabic/English title in its original language, plus the exact Arabic subtitle "كتاب تلوين". Do not invent a surname, dedication, age text, hobby or other child. Theme follows the purchased product and explicit customer notes; otherwise use a neutral joyful coloring world. Optional linked-story data belongs to this child only.
Keep faces, lettering and important art 10 mm inside the A4 trim. Keep a quiet clear area in the lower-left 30% width and 15% height for the real HeroKid logo, which the application will overlay; do not draw a logo yourself. No back cover, spine or 3D book mockup. Output one front-cover artwork only. If the provider uses a different portrait canvas, keep all critical art in the central safe A4 composition; paper padding may be added without stretching or cropping.
Reference images: {{child_image_references}}
PROMPT;
    }

    public static function interiorPrompt(): string
    {
        return <<<'PROMPT'
Create {{book_page_count}} DISTINCT portrait A4 interior coloring pages for {{child_full_name}}, age {{child_age}}, {{child_gender}}. Parent notes: {{special_notes}}. Product: {{product_name}}.
Deliver one PNG per requested page, no PDF, cover, back cover, title page or blank filler. A4 is 210 x 297 mm; keep important art 10 mm inside trim. Request 2480 x 3508 native pixels where supported; never upscale an image and call it native 300 DPI.
Use the supplied child photos as the identity source and the approved cover as theme guidance. Preserve this child's face, age, hair and distinctive appearance, and never copy another person or a sample child's identity. Show the child prominently on every page in a new pose or activity. Age-appropriate clean black outlines on white paper with closed shapes and usable coloring areas; no heavy gray backgrounds. Preserve recognizable facial detail. No printed text unless the customer's instructions explicitly require it.
If approved linked-story scenes are supplied, adapt ALL those scenes in their supplied chronological order, one scene per assigned page, before adding distinct complementary activities. Do not invent a linked story. Without linked scenes, use distinct joyful everyday/adventure/coloring activities guided by the customer's notes and the approved cover. Never repeat a previous page.
Reference images: {{child_image_references}}
PROMPT;
    }

    public static function validate(array $components): void
    {
        $active = collect($components)->filter(fn ($row) => (bool) ($row['is_active'] ?? false));
        $books = $active->filter(fn ($row) => in_array($row['production_stage'] ?? 'standard', ['book_cover', 'book_interior'], true));
        if ($books->isEmpty()) {
            return;
        }
        if ($books->count() !== 2 || $active->count() !== 2
            || $books->where('production_stage', 'book_cover')->count() !== 1
            || $books->where('production_stage', 'book_interior')->count() !== 1) {
            throw ValidationException::withMessages(['production_components' => 'كتاب التلوين يحتاج جزأين فعالين فقط: كفر واحد وصفحات داخلية.']);
        }
        foreach ($components as $i => $component) {
            if (! ($component['is_active'] ?? false)) {
                continue;
            }
            if ($component['production_stage'] === 'book_cover' && (int) $component['quantity_per_item'] !== 1) {
                throw ValidationException::withMessages(["production_components.$i.quantity_per_item" => 'الكفر صورة واحدة لكل كتاب.']);
            }
            if ($component['production_stage'] === 'book_interior' && (int) $component['quantity_per_item'] > 1000) {
                throw ValidationException::withMessages(["production_components.$i.quantity_per_item" => 'عدد الصفحات الداخلية من 1 إلى 1000، بدون الكفر.']);
            }
        }
    }
}
