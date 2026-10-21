<?php

namespace App\Services\RemoteStudio;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Remote schema 1: desktop v9 reusable settings, with asset IDs replacing local paths. */
class StudioSettingsSchema
{
    public static function defaults(): array
    {
        return [
            'production_typography' => ['default_font_id' => 'cairo', 'default_font_weight' => 400, 'default_text_box_page_side' => 'right'],
            'export_preferences' => ['default_mode' => 'reading-order', 'allow_warnings' => false],
            'ui_preferences' => ['show_safe_area' => false, 'show_bleed' => false, 'show_gutter' => true],
            'text_style_presets' => [],
            'openai_cover' => ['enabled' => false, 'model' => 'gpt-image-2', 'quality' => 'high', 'size' => '1024x1536',
                'prompt_template' => 'Create one print-ready front cover for {{story_title}} starring {{child_name}}. Theme: {{story_theme}}. Age: {{child_age}}. Gender: {{child_gender}}. Language: {{language}}. Dedication: {{dedication}}. {{cover_style_notes}}. Preserve the reference identity. Leave space for the official logo; do not draw a logo.',
                'coloring_book_prompt_template' => 'Create exactly one colorful A4 front cover for a children coloring book titled {{story_title}}, starring {{child_name}}. Theme: {{story_theme}}. Age: {{child_age}}. Gender: {{child_gender}}. Language: {{language}}. Dedication: {{dedication}}. {{cover_style_notes}}. Preserve the reference identity. No interior pages or mockup. Leave space for the official logo; do not draw a logo.',
                'use_identity_image' => true, 'cover_style_notes' => ''],
            'default_back_cover' => null, 'brand_logo' => null, 'product_production_profiles' => [],
        ];
    }

    public function validate(array $settings): array
    {
        if (strlen(json_encode($settings, JSON_THROW_ON_ERROR)) > 2_000_000) {
            $this->fail('Settings exceed 2 MB.');
        }
        $this->node($settings, $this->schema(), 'settings');
        $this->safeStrings($settings);
        $this->uniqueIds($settings['text_style_presets']);
        $this->uniqueIds($settings['product_production_profiles']);
        [$width, $height] = array_map('intval', explode('x', $settings['openai_cover']['size']));
        if ($width < 1 || $height < 1 || $width % 16 || $height % 16 || max($width, $height) > 3840
            || max($width, $height) / min($width, $height) > 3 || $width * $height < 655360 || $width * $height > 8294400) {
            $this->fail('Cover dimensions must be safe 16-pixel increments (655360–8294400 pixels, maximum edge 3840, ratio at most 3:1).');
        }
        if (in_array($settings['openai_cover']['model'], ['gpt-image-1', 'gpt-image-2'], true)
            && in_array($settings['openai_cover']['quality'], ['xhigh', 'max'], true)) {
            $this->fail('This model does not support the selected quality.');
        }
        foreach ($settings['product_production_profiles'] as $profile) {
            $this->uniqueIds($profile['fields']);
            if ($profile['sideCount'] === 1 && ($profile['templates']['back'] ?? null)) {
                $this->fail('One-sided profiles cannot use back templates.');
            }
            foreach ([$profile['photo']['rect'], ...array_column($profile['fields'], 'rect')] as $rect) {
                if ($rect['xPercent'] + $rect['widthPercent'] > 100 || $rect['yPercent'] + $rect['heightPercent'] > 100) {
                    $this->fail('A profile region extends beyond its canvas.');
                }
            }
        }

        return $settings;
    }

    public function assetIds(array $settings): array
    {
        $ids = array_filter([$settings['default_back_cover']['asset_id'] ?? null, $settings['brand_logo']['asset_id'] ?? null]);
        foreach ($settings['product_production_profiles'] as $profile) {
            foreach (['front', 'back'] as $side) {
                if (! empty($profile['templates'][$side])) {
                    $ids[] = $profile['templates'][$side]['asset_id'];
                }
            }
        }
        sort($ids);

        return array_values(array_unique($ids));
    }

    public function schema(): array
    {
        $id = 'required|string|max:200|regex:/^[a-zA-Z0-9_.:-]+$/D';
        $font = 'required|string|max:100|regex:/^[a-zA-Z0-9_-]+$/D';
        $weight = 'required|integer|in:400,500,600,700,800';
        $bool = 'required|boolean';
        $color = ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'];
        $rect = ['xPercent' => 'required|numeric|between:0,100', 'yPercent' => 'required|numeric|between:0,100', 'widthPercent' => 'required|numeric|gt:0|max:100', 'heightPercent' => 'required|numeric|gt:0|max:100'];
        $typography = ['fontId' => $font, 'fontWeight' => $weight, 'fontSizePt' => 'required|numeric|between:5,160', 'color' => $color, 'direction' => 'required|in:rtl,ltr', 'alignment' => 'required|in:left,center,right'];
        $style = ['fontId' => $font, 'fontWeight' => $weight, 'fontSizePt' => 'required|numeric|between:6,96', 'lineHeight' => 'required|numeric|between:0.8,3', 'color' => $color, 'backgroundColor' => $color,
            'backgroundOpacity' => 'required|numeric|between:0,1', 'alignment' => 'required|in:left,center,right,start,end,justify', 'verticalAlignment' => 'required|in:start,center,end', 'textDirection' => 'required|in:rtl,ltr'];
        foreach (['paddingTopMm', 'paddingRightMm', 'paddingBottomMm', 'paddingLeftMm', 'borderRadiusMm'] as $key) {
            $style[$key] = 'required|numeric|between:0,30';
        }
        $asset = ['asset_id' => 'required|uuid'];

        return [
            'production_typography' => ['default_font_id' => $font, 'default_font_weight' => $weight, 'default_text_box_page_side' => 'required|in:left,right'],
            'export_preferences' => ['default_mode' => 'required|in:reading-order,booklet', 'allow_warnings' => $bool],
            'ui_preferences' => ['show_safe_area' => $bool, 'show_bleed' => $bool, 'show_gutter' => $bool],
            'text_style_presets' => ['@list' => ['id' => $id, 'name' => 'required|string|max:80', 'style' => $style, 'createdAt' => 'required|date_format:Y-m-d\TH:i:s.v\Z', 'updatedAt' => 'required|date_format:Y-m-d\TH:i:s.v\Z'], '@max' => 100],
            'openai_cover' => ['enabled' => $bool, 'model' => 'required|string|max:100|regex:/^[a-zA-Z0-9._-]+$/D', 'quality' => 'required|in:low,medium,high,xhigh,max', 'size' => 'required|regex:/^[0-9]{3,4}x[0-9]{3,4}$/D',
                'prompt_template' => 'required|string|max:20000', 'coloring_book_prompt_template' => 'required|string|max:20000', 'use_identity_image' => $bool, 'cover_style_notes' => 'present|string|max:2000'],
            'default_back_cover' => ['@nullable' => true, ...$asset, 'crop' => ['focalX' => 'required|numeric|between:0,1', 'focalY' => 'required|numeric|between:0,1', 'zoom' => 'required|numeric|between:1,4']],
            'brand_logo' => ['@nullable' => true, ...$asset, 'placement' => ['position' => 'required|in:top-left,top-center,top-right,center-left,center,center-right,bottom-left,bottom-center,bottom-right,custom', 'x' => 'required|numeric|between:0,1', 'y' => 'required|numeric|between:0,1', 'widthPercent' => 'required|numeric|between:0.1,0.6', 'opacity' => 'required|numeric|between:0.1,1']],
            'product_production_profiles' => ['@max' => 500, '@list' => [
                'schemaVersion' => 'required|integer|in:1', 'id' => $id, 'name' => 'required|string|max:200', 'workflow' => 'required|in:personalized-card,image-sticker-sheet,text-sticker-sheet,coloring-book',
                'match' => ['productId' => 'present|nullable|string|max:200', 'sku' => 'present|nullable|string|max:200', 'componentKey' => 'present|nullable|string|max:200'],
                'canvas' => ['widthMm' => 'required|numeric|between:10,1000', 'heightMm' => 'required|numeric|between:10,1000', 'orientation' => 'required|in:portrait,landscape'],
                'sideCount' => 'required|integer|in:1,2', 'defaultCopies' => 'required|integer|between:1,1000',
                'cut' => ['showBorder' => $bool, 'borderColor' => $color, 'borderWidthMm' => 'required|numeric|between:0,20', 'borderInsetMm' => 'required|numeric|between:0,50', 'bleedMm' => 'required|numeric|between:0,50'],
                'templates' => ['front' => ['@nullable' => true, ...$asset], 'back' => ['@nullable' => true, ...$asset]],
                'photo' => ['enabled' => $bool, 'side' => 'required|in:front,back', 'shape' => 'required|in:oval,circle,rectangle', 'rect' => $rect],
                'fields' => ['@max' => 50, '@list' => ['id' => $id, 'sourceKey' => $id, 'label' => 'required|string|max:200', 'side' => 'required|in:front,back', 'visible' => $bool, 'showLabel' => $bool, 'showWhenEmpty' => $bool, 'rect' => $rect, 'typography' => $typography]],
                'updatedAt' => 'required|date_format:Y-m-d\TH:i:s.v\Z',
            ]],
        ];
    }

    private function node(mixed $value, array|string $schema, string $path): void
    {
        if (is_string($schema) || array_is_list($schema)) {
            Validator::make(['value' => $value], ['value' => $schema], [], ['value' => $path])->validate();
            // JSON booleans must stay booleans; numeric strings are not a typed snapshot.
            $rules = is_string($schema) ? explode('|', $schema) : $schema;
            if (in_array('boolean', $rules, true) && ! is_bool($value)) {
                $this->fail($path.' must be a JSON boolean.');
            }
            if (in_array('integer', $rules, true) && ! is_int($value)) {
                $this->fail($path.' must be a JSON integer.');
            }
            if (in_array('numeric', $rules, true) && ! is_int($value) && ! is_float($value)) {
                $this->fail($path.' must be a JSON number.');
            }

            return;
        }
        if (($schema['@nullable'] ?? false) && $value === null) {
            return;
        }
        if (! is_array($value)) {
            $this->fail($path.' must be an object or array.');
        }
        if (isset($schema['@list'])) {
            if (! array_is_list($value) || count($value) > $schema['@max']) {
                $this->fail($path.' exceeds its list limit.');
            }
            foreach ($value as $index => $entry) {
                $this->node($entry, $schema['@list'], $path.'.'.$index);
            }

            return;
        }
        unset($schema['@nullable']);
        if (array_diff(array_keys($value), array_keys($schema)) || array_diff(array_keys($schema), array_keys($value))) {
            $this->fail($path.' has missing or unknown fields.');
        }
        foreach ($schema as $key => $child) {
            $this->node($value[$key], $child, $path.'.'.$key);
        }
    }

    private function uniqueIds(array $list): void
    {
        if (count($list) !== count(array_unique(array_column($list, 'id')))) {
            $this->fail('IDs must be unique within each collection.');
        }
    }

    public function safeStrings(array $values): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                $this->safeStrings($value);
            }
            if (is_string($value) && (strip_tags($value) !== $value || preg_match('~(?:/Users/|/home/|/private/|/var/|[A-Z]:[\\\\/]|\\\\\\\\|sk-[A-Za-z0-9_-]{12,}|Bearer\s+[A-Za-z0-9])~i', $value))) {
                $this->fail('Settings cannot contain HTML, local filesystem paths or credentials.');
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['settings' => $message]);
    }
}
