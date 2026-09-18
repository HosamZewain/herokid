<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1 text-right">
            <h2 class="text-xl font-bold text-gray-800">قالب برومبت إنتاج هوية القصة</h2>
            <p class="text-sm text-gray-500">Story Child Identity Production Prompt</p>
        </div>
    </x-slot>

    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6">
            @if(session('success'))
                <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-right font-bold text-green-700">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-right font-bold text-red-700">{{ $errors->first() }}</div>
            @endif

            <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
                <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                    <div class="mb-5 flex flex-col gap-4 border-b pb-4 text-right lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="text-lg font-black text-gray-900">القالب العام لهوية القصص</h3>
                            <p class="mt-1 text-sm leading-6 text-gray-500">
                                هذا الجزء يحدد تعليمات إنشاء هوية الطفل التي تظهر في الطلبات التي لا تحتوي على برومبت خاص.
                                بيانات الطفل والقصة والصور الآمنة تضاف تلقائيًا أسفل القالب.
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
                            <form action="{{ route('admin.settings.story-child-identity-prompt.reset') }}" method="POST" onsubmit="return confirm('سيتم استعادة قالب هوية القصة الافتراضي. هل تريد المتابعة؟')">
                                @csrf
                                <button class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-bold text-amber-700 hover:bg-amber-100">
                                    استعادة القالب الافتراضي
                                </button>
                            </form>
                        @endcan
                    </div>

                    <form action="{{ route('admin.settings.story-child-identity-prompt.update') }}" method="POST" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <textarea
                            name="template"
                            rows="28"
                            dir="ltr"
                            spellcheck="false"
                            class="block w-full rounded-xl border-gray-300 bg-slate-50 text-left font-mono text-sm leading-6 text-slate-800 shadow-sm focus:border-fuchsia-500 focus:ring-fuchsia-500"
                            @cannot('settings.production_prompt.manage') readonly @endcannot
                        >{{ old('template', $template) }}</textarea>
                        @can('settings.production_prompt.manage')
                            <button type="submit" class="rounded-xl bg-fuchsia-600 px-6 py-3 text-sm font-black text-white hover:bg-fuchsia-700">
                                حفظ قالب هوية القصص
                            </button>
                        @endcan
                    </form>
                </section>

                <aside class="space-y-6">
                    <section class="rounded-2xl border border-fuchsia-100 bg-fuchsia-50/60 p-5 text-right">
                        <h3 class="text-base font-black text-fuchsia-950">كيف يطبق التغيير؟</h3>
                        <ul class="mt-3 space-y-2 text-sm font-bold leading-6 text-fuchsia-900">
                            <li>الطلبات التي تستخدم القالب الافتراضي ستعرض النسخة الجديدة فورًا.</li>
                            <li>البرومبت الخاص المحفوظ لطلب معين لا يتغير تلقائيًا.</li>
                            <li>النسخ المحفوظة للتاريخ تبقى كما هي ولا يتم حذفها.</li>
                            <li>لا تضع روابط صور أو بيانات طفل داخل القالب العام.</li>
                        </ul>
                    </section>
                    <section class="rounded-2xl border border-gray-100 bg-white p-5 text-right">
                        <h3 class="text-base font-black text-gray-900">مكان استخدام القالب</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600">
                            من صفحة الطلب يمكنك تعديل برومبت واحد وحفظه كبرومبت خاص. عند إزالة التعديل الخاص، سيعود الطلب تلقائيًا لهذا القالب العام.
                        </p>
                    </section>
                </aside>
            </div>
        </div>
    </div>
</x-admin-layout>
