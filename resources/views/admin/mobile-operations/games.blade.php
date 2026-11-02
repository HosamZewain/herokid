<x-admin-layout>
<x-slot name="header"><h2 class="text-xl font-bold">إعدادات تطبيق الموبايل — الألعاب</h2></x-slot>
<div class="space-y-6" dir="rtl">
@include('admin.mobile-operations.tabs')
@if(session('success'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ session('success') }}</p>@endif
@if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<p class="rounded-xl bg-blue-50 p-4">الإعدادات تصل عند مزامنة نسخة الموبايل الداعمة لها. إيقاف لعبة يمنع جولة جديدة بعد المزامنة ويحافظ على الجولة الجارية. إعداد تقليل الحركة في الجهاز له الأولوية.</p>
<form method="POST" action="{{ route('admin.mobile-operations.games.settings') }}" class="rounded-2xl border bg-white p-5 space-y-4">
@csrf @method('PUT')<input type="hidden" name="config_version" value="{{ $config['version'] }}">
<fieldset @disabled(!auth()->user()->can('settings.mobile.manage')) class="flex flex-wrap items-center gap-5">
<label><input type="hidden" name="games_enabled" value="0"><input type="checkbox" name="games_enabled" value="1" @checked($config['data']['games_enabled'])> تفعيل قسم الألعاب</label>
<label>ترتيب القسم <input type="number" name="games_position" min="1" max="99" value="{{ $config['data']['games_position'] }}" class="w-20 rounded-lg border-gray-300"></label>
<label>مدة الاحتفاظ بالأحداث الخام (يوم) <input type="number" min="30" max="365" name="raw_retention_days" value="{{ $config['data']['raw_retention_days'] }}" class="w-24 rounded-lg border-gray-300"></label>
@can('settings.mobile.manage')<button class="rounded-xl bg-indigo-600 px-5 py-2 text-white">حفظ إعدادات القسم</button>@endcan
</fieldset>
<p class="text-sm text-slate-500">الإصدار {{ $config['version'] }} · آخر تعديل {{ app_datetime($config['updated_at'], 'Y-m-d H:i') }}. الحذف الدوري غير مفعّل؛ ضبط المدة لا يحذف بيانات.</p>
</form>
@foreach($config['data']['games'] as $game)
<details class="rounded-2xl border bg-white p-5" id="game-{{ $game['id'] }}">
<summary class="cursor-pointer text-lg font-bold">{{ $game['title']['ar'] }} · {{ $game['enabled'] ? 'مفعّلة' : 'متوقفة' }} · ترتيب {{ $game['sort_order'] }}</summary>
<form method="POST" enctype="multipart/form-data" action="{{ route('admin.mobile-operations.games.update', $game['id']) }}" class="mt-5 space-y-5" data-game-card-form>
@csrf @method('PUT')<input type="hidden" name="config_version" value="{{ $config['version'] }}">
<fieldset @disabled(!auth()->user()->can('settings.mobile.manage')) class="space-y-5">
<div class="grid gap-4 md:grid-cols-2">
<label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($game['enabled'])> تفعيل اللعبة</label>
<label>ترتيب العرض<input type="number" min="1" max="99" name="sort_order" value="{{ $game['sort_order'] }}" class="mx-3 w-20 rounded-lg border-gray-300"></label>
@foreach(['ar' => 'العربية', 'en' => 'الإنجليزية'] as $lang => $language)
@foreach(['title' => ['الاسم',80], 'description' => ['الوصف',240], 'instructions' => ['التعليمات',1000]] as $field => [$label,$limit])
<label class="text-sm font-bold">{{ $label }} — {{ $language }}<textarea name="{{ $field }}[{{ $lang }}]" maxlength="{{ $limit }}" required dir="{{ $lang === 'en' ? 'ltr' : 'rtl' }}" class="mt-2 w-full rounded-xl border-gray-300">{{ $game[$field][$lang] }}</textarea></label>
@endforeach
@endforeach
</div>
<div class="grid gap-5 md:grid-cols-2">
<div>
<label class="block font-bold">صورة كارت اللعبة<input type="file" name="image" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full" data-game-card-input></label>
<p class="mt-2 text-sm text-slate-500">صورة عامة فقط، بدون صور العملاء. بحد أقصى 5 MB. تُحفظ WebP أو PNG وتُزال بياناتها الوصفية. المعاينة التالية قبل الحفظ.</p>
<label class="mt-3 block"><input type="checkbox" name="remove_image" value="1"> العودة للصورة المدمجة بالتطبيق</label>
</div>
<div class="rounded-xl border bg-slate-50 p-4 text-center">
<img data-game-card-preview hidden alt="معاينة الصورة المختارة" class="mx-auto h-48 w-48 object-contain">
@if($game['card_image']['path'])<img data-game-card-fallback src="{{ secure_url('storage/'.$game['card_image']['path']) }}" alt="{{ $game['title']['ar'] }}" class="mx-auto h-48 w-48 object-contain">@else<p data-game-card-fallback class="p-10 text-slate-500">الصورة المدمجة في الموبايل</p>@endif
<p class="mt-2 font-bold">{{ $game['title']['ar'] }}</p><p class="text-sm">{{ $game['description']['ar'] }}</p>
</div>
</div>
<div class="flex flex-wrap items-center gap-5">
<span class="font-bold">المستويات المفعّلة:</span>
@foreach($definitions[$game['id']][2] as $level)<label><input type="checkbox" name="settings[enabled_levels][]" value="{{ $level }}" @checked(in_array($level, $game['settings']['enabled_levels']))> {{ $level }}{{ $game['id'] === 'memory' ? ' أزواج' : ($game['id'] === 'puzzle' ? ' قطعة' : '') }}</label>@endforeach
<label>الافتراضي <select name="settings[default_level]" class="rounded-lg border-gray-300">@foreach($definitions[$game['id']][2] as $level)<option value="{{ $level }}" @selected($level === $game['settings']['default_level'])>{{ $level }}</option>@endforeach</select></label>
</div>
@foreach(['memory' => ['themes','default_theme',['animals'=>'حيوانات','colors'=>'ألوان','letters'=>'حروف']], 'puzzle'=>['scenes','default_scene',['forest'=>'الغابة','space'=>'الفضاء','ocean'=>'المحيط']]] as $id => [$list,$default,$options])
@if($game['id'] === $id)<div class="flex flex-wrap items-center gap-5">
@foreach($options as $value => $label)<label><input type="checkbox" name="settings[{{ $list }}][]" value="{{ $value }}" @checked(in_array($value,$game['settings'][$list]))> {{ $label }}</label>@endforeach
<label>الافتراضي <select name="settings[{{ $default }}]" class="rounded-lg border-gray-300">@foreach($options as $value => $label)<option value="{{ $value }}" @selected($value === $game['settings'][$default])>{{ $label }}</option>@endforeach</select></label>
</div>@endif
@endforeach
<div class="flex flex-wrap gap-5">@foreach(['celebration_enabled'=>'الاحتفال', 'retry_feedback_enabled'=>'تشجيع إعادة المحاولة', 'sound_enabled'=>'الصوت', 'motion_enabled'=>'الحركة'] as $key=>$label)<label><input type="hidden" name="settings[{{ $key }}]" value="0"><input type="checkbox" name="settings[{{ $key }}]" value="1" @checked($game['settings'][$key])> {{ $label }}</label>@endforeach</div>
<p class="text-sm text-slate-500">{{ $game['id'] }} · إصدار الصورة {{ $game['card_image']['version'] }} · إصدار قواعد اللعب {{ $game['rules_version'] }}</p>
@can('settings.mobile.manage')<button class="rounded-xl bg-indigo-600 px-6 py-3 font-bold text-white">حفظ اللعبة</button>@endcan
</fieldset>
</form>
</details>
@endforeach
<section class="rounded-2xl border bg-white p-5"><h3 class="text-lg font-bold">سجل الإصدارات</h3><div class="overflow-x-auto"><table class="mt-4 w-full text-sm"><thead><tr><th class="p-3 text-right">الإصدار</th><th class="p-3 text-right">الموظف</th><th class="p-3 text-right">التاريخ</th><th class="p-3 text-right">الإجراء</th></tr></thead><tbody>
@foreach($history as $entry)<tr class="border-t"><td class="p-3">{{ $entry->version }} @if($entry->restored_from) (استعادة {{ $entry->restored_from }}) @endif</td><td class="p-3">{{ $entry->actor ?? 'النظام' }}</td><td class="p-3">{{ app_datetime($entry->created_at,'Y-m-d H:i') }}</td><td class="p-3">@can('settings.mobile.manage')@if($entry->version != $config['version'])<form method="POST" action="{{ route('admin.mobile-operations.games.restore',$entry->version) }}" onsubmit="return confirm('استعادة إعدادات هذا الإصدار لكل الألعاب؟ الصور القديمة والإحصائيات ستظل محفوظة.')">@csrf<input type="hidden" name="config_version" value="{{ $config['version'] }}"><button class="rounded-lg border px-3 py-2">استعادة</button></form>@endif
@endcan</td></tr>@endforeach
</tbody></table></div>{{ $history->links() }}</section>
</div>
@push('scripts')
<script>
document.querySelectorAll('[data-game-card-form]').forEach((form) => {
    const input = form.querySelector('[data-game-card-input]');
    const preview = form.querySelector('[data-game-card-preview]');
    const fallback = form.querySelector('[data-game-card-fallback]');
    let objectUrl = null;
    input.addEventListener('change', () => {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        const file = input.files[0];
        const valid = file && ['image/jpeg', 'image/png', 'image/webp'].includes(file.type) && file.size <= 5 * 1024 * 1024;
        input.setCustomValidity(file && !valid ? 'اختر صورة JPEG أو PNG أو WebP بحجم لا يتجاوز 5 MB.' : '');
        if (valid) {
            objectUrl = URL.createObjectURL(file);
            preview.src = objectUrl;
        } else {
            preview.removeAttribute('src');
        }
        preview.hidden = !valid;
        fallback.hidden = !!valid;
    });
    window.addEventListener('pagehide', () => { if (objectUrl) URL.revokeObjectURL(objectUrl); }, { once: true });
});
</script>
@endpush
</x-admin-layout>
