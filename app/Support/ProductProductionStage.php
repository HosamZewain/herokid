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
Create a cheerful, age-appropriate cover with the child as the main character and playful coloring-book motifs. Keep the face unobstructed and leave clear safe margins for later print layout.
Output only the cover artwork as one image: no interior pages, grids, mockups, book spine, back cover, watermarks or invented logos. Leave title/logo lettering to the production layout.
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
