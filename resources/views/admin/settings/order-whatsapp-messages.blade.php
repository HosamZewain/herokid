<x-admin-layout>
    <x-slot name="header">
        <div class="text-right">
            <h2 class="text-xl font-black text-gray-900">رسائل واتساب للطلبات</h2>
            <p class="mt-1 text-sm font-bold text-gray-500">أنشئ أزرار رسائل جاهزة، واستعمل بيانات الطلب داخل الرسالة تلقائيًا.</p>
        </div>
    </x-slot>

    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-black text-emerald-800">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-bold text-rose-800" role="alert">
                    @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                </div>
            @endif

            <section class="rounded-3xl border border-indigo-100 bg-indigo-50 p-5">
                <h3 class="font-black text-indigo-950">المتغيرات المتاحة</h3>
                <p class="mt-1 text-xs font-bold text-indigo-700">اضغط على أي متغير لنسخه، ثم الصقه داخل نص الرسالة. يتم استبداله ببيانات الطلب وقت فتح واتساب.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($variables as $key => $description)
                        @php($variableToken = chr(123).chr(123).$key.chr(125).chr(125))
                        <button type="button" data-copy-variable="{{ $variableToken }}" class="rounded-xl border border-indigo-200 bg-white px-3 py-2 text-right text-xs font-black text-indigo-800" title="{{ $description }}">
                            <span dir="ltr">{{ $variableToken }}</span>
                            <span class="block pt-1 text-[10px] font-bold text-gray-500">{{ $description }}</span>
                        </button>
                    @endforeach
                </div>
            </section>

            <form method="POST" action="{{ route('admin.settings.order-whatsapp-messages.update') }}" class="space-y-5">
                @csrf
                @method('PUT')
                <div id="whatsapp-template-list" class="space-y-4">
                    @foreach(old('templates', $templates) as $index => $template)
                        @include('admin.settings._order-whatsapp-template-row', ['index' => $index, 'template' => $template])
                    @endforeach
                </div>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <button type="button" id="add-whatsapp-template" class="rounded-xl border border-indigo-200 bg-indigo-50 px-5 py-3 text-sm font-black text-indigo-700">+ إضافة زر رسالة</button>
                    @can('settings.site.update')
                        <button type="submit" class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white hover:bg-indigo-700">حفظ رسائل واتساب</button>
                    @endcan
                </div>
            </form>
        </div>
    </div>

    <template id="whatsapp-template-row-template">
        @include('admin.settings._order-whatsapp-template-row', [
            'index' => '__INDEX__',
            'template' => ['id' => '', 'title' => '', 'message' => '', 'is_active' => true, 'sort_order' => 10],
        ])
    </template>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const list = document.getElementById('whatsapp-template-list');
                const template = document.getElementById('whatsapp-template-row-template');
                const addButton = document.getElementById('add-whatsapp-template');
                let nextIndex = list.children.length;

                addButton?.addEventListener('click', () => {
                    const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
                    list.insertAdjacentHTML('beforeend', html);
                    list.lastElementChild?.querySelector('input[name$="[title]"]')?.focus();
                });

                list?.addEventListener('click', (event) => {
                    const remove = event.target.closest('[data-remove-template]');
                    if (!remove) return;
                    if (list.children.length <= 1) {
                        window.alert('يجب الاحتفاظ بقالب رسالة واحد على الأقل. يمكنك تعطيله بدل حذفه.');
                        return;
                    }
                    remove.closest('[data-template-row]')?.remove();
                });

                document.querySelectorAll('[data-copy-variable]').forEach((button) => {
                    button.addEventListener('click', async () => {
                        const value = button.dataset.copyVariable;
                        try {
                            await navigator.clipboard.writeText(value);
                            const original = button.querySelector('span').textContent;
                            button.querySelector('span').textContent = 'تم النسخ ✓';
                            setTimeout(() => button.querySelector('span').textContent = original, 1200);
                        } catch (_) {
                            window.prompt('انسخ المتغير:', value);
                        }
                    });
                });
            });
        </script>
    @endpush
</x-admin-layout>
