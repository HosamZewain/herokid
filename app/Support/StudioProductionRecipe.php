<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudioProductionRecipe
{
    public const VERSION = 1;

    public const WORKFLOWS = [
        'personalized-card',
        'image-sticker-sheet',
        'text-sticker-sheet',
        'coloring-book',
    ];

    public const INPUTS = [
        'child_photo',
        'child_name',
        'school_name',
        'class_name',
        'parent_phone_primary',
        'parent_phone_secondary',
        'special_notes',
    ];

    private const OUTPUT_TYPES = ['single-item-pdf', 'sheet-pdf', 'multi-page-pdf'];

    /** @return array<string, mixed> */
    public static function normalize(string $workflow, int $version, array $recipe, string $attribute = 'studio_recipe'): array
    {
        $payload = ['workflow_column' => $workflow, 'version_column' => $version, 'recipe' => $recipe];
        $validated = Validator::make($payload, [
            'workflow_column' => ['required', Rule::in(self::WORKFLOWS)],
            'version_column' => ['required', Rule::in([self::VERSION])],
            'recipe' => ['required', 'array:version,workflow,template_key,canvas,sides,output,cut,required_inputs,optional_inputs,template_variants'],
            'recipe.version' => ['required', 'integer', Rule::in([self::VERSION])],
            'recipe.workflow' => ['required', Rule::in(self::WORKFLOWS)],
            'recipe.template_key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'recipe.canvas' => ['required', 'array:width_mm,height_mm,allowed_orientations,default_orientation'],
            'recipe.canvas.width_mm' => ['required', 'numeric', 'min:10', 'max:1000'],
            'recipe.canvas.height_mm' => ['required', 'numeric', 'min:10', 'max:1000'],
            'recipe.canvas.allowed_orientations' => ['required', 'array', 'min:1', 'max:2'],
            'recipe.canvas.allowed_orientations.*' => ['required', 'distinct', Rule::in(['portrait', 'landscape'])],
            'recipe.canvas.default_orientation' => ['required', Rule::in(['portrait', 'landscape'])],
            'recipe.sides' => ['required', 'array:allowed,default'],
            'recipe.sides.allowed' => ['required', 'array', 'min:1', 'max:2'],
            'recipe.sides.allowed.*' => ['required', 'integer', 'distinct', Rule::in([1, 2])],
            'recipe.sides.default' => ['required', 'integer', Rule::in([1, 2])],
            'recipe.output' => ['required', 'array:type,default_copies,scale_percent'],
            'recipe.output.type' => ['required', Rule::in(self::OUTPUT_TYPES)],
            'recipe.output.default_copies' => ['required', 'integer', 'min:1', 'max:1000'],
            'recipe.output.scale_percent' => ['required', 'numeric', 'min:10', 'max:500'],
            'recipe.cut' => ['required', 'array:show_border,border_color,border_width_mm,border_inset_mm,bleed_mm'],
            'recipe.cut.show_border' => ['required', 'boolean'],
            'recipe.cut.border_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'recipe.cut.border_width_mm' => ['required', 'numeric', 'min:0', 'max:50'],
            'recipe.cut.border_inset_mm' => ['required', 'numeric', 'min:0', 'max:100'],
            'recipe.cut.bleed_mm' => ['required', 'numeric', 'min:0', 'max:100'],
            'recipe.required_inputs' => ['present', 'array', 'max:20'],
            'recipe.required_inputs.*' => ['required', 'distinct', Rule::in(self::INPUTS)],
            'recipe.optional_inputs' => ['present', 'array', 'max:20'],
            'recipe.optional_inputs.*' => ['required', 'distinct', Rule::in(self::INPUTS)],
            'recipe.template_variants' => ['present', 'array', 'max:20'],
            'recipe.template_variants.*' => ['array:key,label,gender'],
            'recipe.template_variants.*.key' => ['required', 'string', 'max:80', 'distinct', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'recipe.template_variants.*.label' => ['required', 'string', 'max:100', 'not_regex:/[<>]/'],
            'recipe.template_variants.*.gender' => ['nullable', Rule::in(['male', 'female'])],
        ], [
            'workflow_column.in' => 'نوع مسار HeroKid Studio غير مدعوم.',
            'version_column.in' => 'إصدار وصفة HeroKid Studio غير مدعوم.',
            'recipe.array' => 'تحتوي وصفة HeroKid Studio على حقول غير مدعومة.',
            'recipe.*.array' => 'تحتوي إعدادات وصفة HeroKid Studio على حقول غير مدعومة.',
            'recipe.canvas.width_mm.min' => 'عرض التصميم يجب ألا يقل عن 10 مم.',
            'recipe.canvas.height_mm.min' => 'ارتفاع التصميم يجب ألا يقل عن 10 مم.',
            'recipe.cut.*.min' => 'لا يمكن أن تكون قياسات القص أو الهوامش سالبة.',
            'recipe.cut.border_color.regex' => 'لون إطار القص يجب أن يكون بصيغة مثل #000000.',
            'recipe.template_variants.*.label.not_regex' => 'اسم نسخة القالب يجب أن يكون نصًا عاديًا بدون HTML.',
        ])->validate();

        $normalized = $validated['recipe'];
        if ((int) $normalized['version'] !== $version || $normalized['workflow'] !== $workflow) {
            throw ValidationException::withMessages([$attribute => 'إصدار ومسار الوصفة يجب أن يطابقا إعدادات الجزء.']);
        }

        if (! in_array($normalized['canvas']['default_orientation'], $normalized['canvas']['allowed_orientations'], true)) {
            throw ValidationException::withMessages([$attribute.'.canvas.default_orientation' => 'الاتجاه الافتراضي يجب أن يكون ضمن الاتجاهات المتاحة.']);
        }

        if (! in_array((int) $normalized['sides']['default'], array_map('intval', $normalized['sides']['allowed']), true)) {
            throw ValidationException::withMessages([$attribute.'.sides.default' => 'عدد الأوجه الافتراضي يجب أن يكون ضمن الأعداد المتاحة.']);
        }

        if (array_intersect($normalized['required_inputs'], $normalized['optional_inputs']) !== []) {
            throw ValidationException::withMessages([$attribute.'.optional_inputs' => 'لا يمكن أن يكون حقل الإنتاج مطلوبًا واختياريًا في الوقت نفسه.']);
        }

        $normalized['version'] = (int) $normalized['version'];
        foreach (['width_mm', 'height_mm'] as $key) {
            $normalized['canvas'][$key] = (float) $normalized['canvas'][$key];
        }
        $normalized['sides']['allowed'] = array_map('intval', $normalized['sides']['allowed']);
        $normalized['sides']['default'] = (int) $normalized['sides']['default'];
        $normalized['output']['default_copies'] = (int) $normalized['output']['default_copies'];
        $normalized['output']['scale_percent'] = (float) $normalized['output']['scale_percent'];
        $normalized['cut']['show_border'] = filter_var($normalized['cut']['show_border'], FILTER_VALIDATE_BOOL);
        foreach (['border_width_mm', 'border_inset_mm', 'bleed_mm'] as $key) {
            $normalized['cut'][$key] = (float) $normalized['cut'][$key];
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'version' => self::VERSION,
            'workflow' => 'personalized-card',
            'template_key' => 'child-id-v1',
            'canvas' => ['width_mm' => 60, 'height_mm' => 90, 'allowed_orientations' => ['portrait', 'landscape'], 'default_orientation' => 'portrait'],
            'sides' => ['allowed' => [1, 2], 'default' => 1],
            'output' => ['type' => 'single-item-pdf', 'default_copies' => 1, 'scale_percent' => 100],
            'cut' => ['show_border' => true, 'border_color' => '#000000', 'border_width_mm' => 0.5, 'border_inset_mm' => 0.5, 'bleed_mm' => 0],
            'required_inputs' => [],
            'optional_inputs' => ['child_photo', 'child_name', 'school_name', 'class_name', 'parent_phone_primary', 'parent_phone_secondary'],
            'template_variants' => [
                ['key' => 'boy', 'label' => 'Boy', 'gender' => 'male'],
                ['key' => 'girl', 'label' => 'Girl', 'gender' => 'female'],
            ],
        ];
    }
}
