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
