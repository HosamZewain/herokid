@php
    $recipeDefaults = \App\Support\StudioProductionRecipe::defaults();
    $storedRecipe = data_get($component, 'studio_recipe');
    $recipe = is_array($storedRecipe) ? array_replace_recursive($recipeDefaults, $storedRecipe) : $recipeDefaults;
    if (is_array($storedRecipe)) {
        foreach (['canvas.allowed_orientations', 'sides.allowed', 'required_inputs', 'optional_inputs', 'template_variants'] as $listPath) {
            $storedList = data_get($storedRecipe, $listPath);
            if (is_array($storedList)) {
                data_set($recipe, $listPath, $storedList);
            }
        }
    }
    $prefix = 'production_components['.$componentIndex.']';
    $studioEnabled = filter_var(data_get($component, 'studio_enabled', false), FILTER_VALIDATE_BOOL);
@endphp

<div class="mt-4 rounded-xl border border-indigo-100 bg-indigo-50/50 p-4" data-studio-production>
    <label class="inline-flex items-center gap-2 text-sm font-black text-indigo-900">
        <input type="hidden" name="{{ $prefix }}[studio_enabled]" value="0">
        <input type="checkbox" name="{{ $prefix }}[studio_enabled]" value="1" @checked($studioEnabled) data-studio-enabled>
        تفعيل هذا الجزء داخل HeroKid Studio
    </label>

    <div class="mt-4 space-y-4 {{ $studioEnabled ? '' : 'hidden' }}" data-studio-settings>
        <div class="grid gap-3 md:grid-cols-3">
            <label class="text-xs font-black text-slate-600">مسار العمل
                <select name="{{ $prefix }}[studio_workflow]" class="mt-1.5 w-full rounded-xl border-indigo-200 text-sm">
                    @foreach(\App\Support\StudioProductionRecipe::WORKFLOWS as $workflow)
                        <option value="{{ $workflow }}" @selected(data_get($component, 'studio_workflow', $recipe['workflow']) === $workflow)>{{ $workflow }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs font-black text-slate-600">إصدار الوصفة
                <input type="number" readonly name="{{ $prefix }}[studio_recipe_version]" value="{{ data_get($component, 'studio_recipe_version', 1) ?: 1 }}" class="mt-1.5 w-full rounded-xl border-indigo-200 bg-slate-50 text-center text-sm">
            </label>
            <label class="text-xs font-black text-slate-600">مفتاح القالب
                <input name="{{ $prefix }}[studio_recipe][template_key]" value="{{ $recipe['template_key'] }}" maxlength="100" dir="ltr" class="mt-1.5 w-full rounded-xl border-indigo-200 text-left text-sm">
            </label>
        </div>
        <input type="hidden" name="{{ $prefix }}[studio_recipe][version]" value="1">
        <input type="hidden" name="{{ $prefix }}[studio_recipe][workflow]" value="{{ data_get($component, 'studio_workflow', $recipe['workflow']) }}" data-studio-recipe-workflow>

        <div class="grid gap-3 md:grid-cols-4">
            <label class="text-xs font-black text-slate-600">العرض (مم)<input type="number" step="0.01" min="10" max="1000" name="{{ $prefix }}[studio_recipe][canvas][width_mm]" value="{{ $recipe['canvas']['width_mm'] }}" class="mt-1.5 w-full rounded-xl border-indigo-200 text-center text-sm"></label>
            <label class="text-xs font-black text-slate-600">الارتفاع (مم)<input type="number" step="0.01" min="10" max="1000" name="{{ $prefix }}[studio_recipe][canvas][height_mm]" value="{{ $recipe['canvas']['height_mm'] }}" class="mt-1.5 w-full rounded-xl border-indigo-200 text-center text-sm"></label>
            <label class="text-xs font-black text-slate-600">الاتجاه الافتراضي
                <select name="{{ $prefix }}[studio_recipe][canvas][default_orientation]" class="mt-1.5 w-full rounded-xl border-indigo-200 text-sm">
                    <option value="portrait" @selected($recipe['canvas']['default_orientation'] === 'portrait')>طولي</option>
                    <option value="landscape" @selected($recipe['canvas']['default_orientation'] === 'landscape')>عرضي</option>
                </select>
            </label>
            <div class="text-xs font-black text-slate-600">الاتجاهات المتاحة
                <div class="mt-2 flex gap-3">
                    @foreach(['portrait' => 'طولي', 'landscape' => 'عرضي'] as $value => $label)
                        <label><input type="checkbox" name="{{ $prefix }}[studio_recipe][canvas][allowed_orientations][]" value="{{ $value }}" @checked(in_array($value, $recipe['canvas']['allowed_orientations'], true))> {{ $label }}</label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-4">
            <div class="text-xs font-black text-slate-600">عدد الأوجه المتاح
                <div class="mt-2 flex gap-3">@foreach([1, 2] as $side)<label><input type="checkbox" name="{{ $prefix }}[studio_recipe][sides][allowed][]" value="{{ $side }}" @checked(in_array($side, array_map('intval', $recipe['sides']['allowed']), true))> {{ $side }}</label>@endforeach</div>
            </div>
            <label class="text-xs font-black text-slate-600">عدد الأوجه الافتراضي<select name="{{ $prefix }}[studio_recipe][sides][default]" class="mt-1.5 w-full rounded-xl border-indigo-200 text-sm"><option value="1" @selected((int) $recipe['sides']['default'] === 1)>1</option><option value="2" @selected((int) $recipe['sides']['default'] === 2)>2</option></select></label>
            <label class="text-xs font-black text-slate-600">عدد النسخ<input type="number" min="1" max="1000" name="{{ $prefix }}[studio_recipe][output][default_copies]" value="{{ $recipe['output']['default_copies'] }}" class="mt-1.5 w-full rounded-xl border-indigo-200 text-center text-sm"></label>
            <label class="text-xs font-black text-slate-600">نسبة القياس %<input type="number" min="10" max="500" step="0.01" name="{{ $prefix }}[studio_recipe][output][scale_percent]" value="{{ $recipe['output']['scale_percent'] }}" class="mt-1.5 w-full rounded-xl border-indigo-200 text-center text-sm"></label>
        </div>
        <label class="block text-xs font-black text-slate-600">نوع الملف
            <select name="{{ $prefix }}[studio_recipe][output][type]" class="mt-1.5 w-full rounded-xl border-indigo-200 text-sm"><option value="single-item-pdf" @selected($recipe['output']['type'] === 'single-item-pdf')>PDF لقطعة واحدة</option><option value="sheet-pdf" @selected($recipe['output']['type'] === 'sheet-pdf')>PDF شيت</option><option value="multi-page-pdf" @selected($recipe['output']['type'] === 'multi-page-pdf')>PDF متعدد الصفحات</option></select>
        </label>

        <div class="grid gap-3 md:grid-cols-5">
            <label class="inline-flex items-center gap-2 text-xs font-black text-slate-600"><input type="hidden" name="{{ $prefix }}[studio_recipe][cut][show_border]" value="0"><input type="checkbox" name="{{ $prefix }}[studio_recipe][cut][show_border]" value="1" @checked($recipe['cut']['show_border'])> إظهار إطار القص</label>
            <label class="text-xs font-black text-slate-600">لون الإطار<input type="color" name="{{ $prefix }}[studio_recipe][cut][border_color]" value="{{ $recipe['cut']['border_color'] }}" class="mt-1.5 h-10 w-full rounded-xl border-indigo-200"></label>
            <label class="text-xs font-black text-slate-600">سمك الإطار (مم)<input type="number" min="0" max="50" step="0.01" name="{{ $prefix }}[studio_recipe][cut][border_width_mm]" value="{{ $recipe['cut']['border_width_mm'] }}" class="mt-1.5 w-full rounded-xl border-indigo-200 text-center text-sm"></label>
            <label class="text-xs font-black text-slate-600">إزاحة الإطار (مم)<input type="number" min="0" max="100" step="0.01" name="{{ $prefix }}[studio_recipe][cut][border_inset_mm]" value="{{ $recipe['cut']['border_inset_mm'] }}" class="mt-1.5 w-full rounded-xl border-indigo-200 text-center text-sm"></label>
            <label class="text-xs font-black text-slate-600">Bleed (مم)<input type="number" min="0" max="100" step="0.01" name="{{ $prefix }}[studio_recipe][cut][bleed_mm]" value="{{ $recipe['cut']['bleed_mm'] }}" class="mt-1.5 w-full rounded-xl border-indigo-200 text-center text-sm"></label>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            @foreach(['required_inputs' => 'حقول مطلوبة', 'optional_inputs' => 'حقول اختيارية'] as $group => $title)
                <fieldset class="rounded-xl border border-indigo-100 bg-white p-3"><legend class="px-2 text-xs font-black text-indigo-900">{{ $title }}</legend><div class="grid grid-cols-2 gap-2 text-xs text-slate-700">
                    @foreach(\App\Support\StudioProductionRecipe::INPUTS as $input)<label><input type="checkbox" name="{{ $prefix }}[studio_recipe][{{ $group }}][]" value="{{ $input }}" @checked(in_array($input, $recipe[$group], true))> {{ $input }}</label>@endforeach
                </div></fieldset>
            @endforeach
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            @foreach([0 => ['key' => 'boy', 'label' => 'Boy', 'gender' => 'male'], 1 => ['key' => 'girl', 'label' => 'Girl', 'gender' => 'female']] as $variantIndex => $variantDefault)
                @php($variant = $recipe['template_variants'][$variantIndex] ?? $variantDefault)
                <div class="grid grid-cols-3 gap-2 rounded-xl border border-indigo-100 bg-white p-3">
                    <input name="{{ $prefix }}[studio_recipe][template_variants][{{ $variantIndex }}][key]" value="{{ $variant['key'] }}" dir="ltr" class="rounded-lg border-indigo-200 text-sm">
                    <input name="{{ $prefix }}[studio_recipe][template_variants][{{ $variantIndex }}][label]" value="{{ $variant['label'] }}" class="rounded-lg border-indigo-200 text-sm">
                    <select name="{{ $prefix }}[studio_recipe][template_variants][{{ $variantIndex }}][gender]" class="rounded-lg border-indigo-200 text-sm"><option value="male" @selected($variant['gender'] === 'male')>ذكر</option><option value="female" @selected($variant['gender'] === 'female')>أنثى</option></select>
                </div>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('production_components.'.$componentIndex.'.studio_recipe')" class="mt-2" />
    </div>
</div>
