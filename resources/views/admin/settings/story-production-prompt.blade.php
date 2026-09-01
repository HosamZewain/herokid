<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1 text-right">
            <h2 class="text-xl font-bold text-gray-800">قالب برومبت إنتاج القصة</h2>
            <p class="text-sm text-gray-500">Story Production Prompt Template</p>
        </div>
    </x-slot>

    <div class="py-8" dir="rtl">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('success'))
                <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-right font-bold text-green-700">{{ session('success') }}</div>
            @endif

            <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
                <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-5 border-b pb-4">
                        <div class="text-right">
                            <h3 class="text-lg font-black text-gray-900">القالب العام</h3>
                            <p class="mt-1 text-sm leading-6 text-gray-500">
                                استخدم المتغيرات مثل <code dir="ltr" class="rounded bg-gray-100 px-1">&#123;&#123;child_name&#125;&#125;</code> ليتم استبدالها تلقائيًا ببيانات الطلب.
                            </p>
                            @if($setting)
                                <p class="mt-2 text-xs text-gray-400">
                                    آخر تحديث: {{ app_datetime($setting->updated_at, 'Y-m-d H:i') }}
                                    @if($setting->editor)
                                        بواسطة {{ $setting->editor->name }}
                                    @endif
                                </p>
                            @endif
                        </div>
                        @can('settings.production_prompt.manage')
                            <form action="{{ route('admin.settings.story-production-prompt.reset') }}" method="POST" onsubmit="return confirm('سيتم استعادة القالب الافتراضي. هل تريد المتابعة؟')">
                                @csrf
                                <button class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-bold text-amber-700 hover:bg-amber-100">
                                    استعادة القالب الافتراضي
                                </button>
                            </form>
                        @endcan
                    </div>

                    <form id="story-production-template-form" action="{{ route('admin.settings.story-production-prompt.update') }}" method="POST" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <textarea
                            id="story-production-template"
                            name="template"
                            rows="34"
                            dir="ltr"
                            spellcheck="false"
                            class="block w-full rounded-xl border-gray-300 bg-slate-50 text-left font-mono text-sm leading-6 text-slate-800 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            @cannot('settings.production_prompt.manage') readonly @endcannot
                        >{{ old('template', $template) }}</textarea>
                        <x-input-error :messages="$errors->get('template')" class="mt-2" />
                        @can('settings.production_prompt.manage')
                        <div class="flex flex-col sm:flex-row gap-3 sm:justify-between">
                            <button type="submit" class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white hover:bg-indigo-700">
                                حفظ القالب
                            </button>
                        </div>
                        @endcan
                    </form>
                </section>

                <aside class="space-y-6">
                    <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <h3 class="mb-4 text-base font-black text-gray-900 text-right">المتغيرات المتاحة</h3>
                        <div class="space-y-3 max-h-[720px] overflow-y-auto pr-1">
                            @foreach($variables as $name => $meta)
                                @php $token = '{{'.$name.'}}'; @endphp
                                <div class="rounded-xl border border-gray-100 bg-slate-50 p-3 text-right">
                                    <code dir="ltr" class="block text-xs font-black text-indigo-700">{{ $token }}</code>
                                    <p class="mt-1 text-xs font-bold text-gray-700">{{ $meta['label'] }}</p>
                                    <p class="mt-1 text-[11px] text-gray-400 break-words">{{ $meta['example'] }}</p>
                                    @can('settings.production_prompt.manage')
                                        <button type="button" data-insert-variable="{{ $token }}" class="mt-2 rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-indigo-600 border border-indigo-100 hover:bg-indigo-50">
                                            إدراج
                                        </button>
                                    @endcan
                                </div>
                            @endforeach
                        </div>
                    </section>
                </aside>
            </div>

            @can('settings.production_prompt.manage')
            <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <div class="mb-4 text-right border-b pb-4">
                    <h3 class="text-lg font-black text-gray-900">Preview باستخدام طلب موجود</h3>
                    <p class="mt-1 text-sm text-gray-500">اختبر القالب الحالي على طلب حقيقي قبل الحفظ.</p>
                </div>

                <form method="GET" action="{{ route('admin.settings.story-production-prompt.edit') }}" class="mb-4 flex flex-col md:flex-row gap-3">
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="ابحث برقم الطلب أو اسم الطفل أو ولي الأمر..." class="flex-1 rounded-xl border-gray-300 text-right">
                    <button class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-bold text-white">بحث</button>
                </form>

                <div class="grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-5">
                    <form action="{{ route('admin.settings.story-production-prompt.preview') }}" method="POST" class="space-y-3">
                        @csrf
                        <textarea id="preview-template-input" name="template" class="hidden">{{ old('template', $template) }}</textarea>
                        <select name="preview_order_id" required class="w-full rounded-xl border-gray-300 text-right">
                            <option value="">اختر طلب للمعاينة...</option>
                            @foreach($orders as $order)
                                <option value="{{ $order->id }}" @selected($previewOrder?->id === $order->id)>
                                    {{ $order->order_number }} - {{ $order->child_name ?? $order->parent_name ?? 'طلب' }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('preview_order_id')" class="mt-1" />
                        <button id="preview-template-button" class="w-full rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">عرض المعاينة</button>
                    </form>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <button type="button" id="copy-template-preview" @disabled(! $previewPrompt) class="rounded-xl border border-green-200 bg-green-50 px-4 py-2 text-sm font-bold text-green-700 disabled:opacity-40">
                                Copy Preview
                            </button>
                            <p class="text-sm font-bold text-gray-600 text-right">
                                @if($previewOrder)
                                    معاينة الطلب #{{ $previewOrder->order_number }}
                                @else
                                    اختر طلباً لعرض المعاينة
                                @endif
                            </p>
                        </div>
                        <textarea id="story-production-template-preview" rows="18" readonly dir="ltr" class="block w-full rounded-xl border-gray-300 bg-slate-50 text-left font-mono text-sm leading-6 text-slate-800">{{ $previewPrompt }}</textarea>
                    </div>
                </div>
            </section>
            @endcan
        </div>
    </div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var editor = document.getElementById('story-production-template');

    document.querySelectorAll('[data-insert-variable]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!editor) return;
            var token = button.getAttribute('data-insert-variable');
            var start = editor.selectionStart || 0;
            var end = editor.selectionEnd || 0;
            editor.value = editor.value.slice(0, start) + token + editor.value.slice(end);
            editor.focus();
            editor.selectionStart = editor.selectionEnd = start + token.length;
        });
    });

    var copyPreview = document.getElementById('copy-template-preview');
    var preview = document.getElementById('story-production-template-preview');
    if (copyPreview && preview) {
        copyPreview.addEventListener('click', function () {
            preview.select();
            navigator.clipboard?.writeText(preview.value).catch(function () {
                document.execCommand('copy');
            });
        });
    }

    var previewTemplateInput = document.getElementById('preview-template-input');
    var previewTemplateButton = document.getElementById('preview-template-button');
    if (previewTemplateInput && previewTemplateButton && editor) {
        previewTemplateButton.closest('form').addEventListener('submit', function () {
            previewTemplateInput.value = editor.value;
        });
    }
});
</script>
@endpush
</x-admin-layout>
