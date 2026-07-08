<x-admin-layout>
    <x-slot name="header">
        <div class="text-right">
            <h2 class="text-xl font-bold text-gray-800">إعداد {{ $provider->public_name }}</h2>
            <p class="text-sm text-gray-500" dir="ltr">{{ $provider->driver }}</p>
        </div>
    </x-slot>

    <div class="space-y-6" dir="rtl">
        @if(session('success'))
            <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-right font-bold text-green-700">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-right font-bold text-red-700">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-right text-sm font-bold text-red-700">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_360px] gap-6">
            <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <div class="mb-5 border-b border-gray-100 pb-4 text-right">
                    <h3 class="text-lg font-black text-gray-950">إعدادات المزود</h3>
                    <p class="mt-1 text-sm text-gray-500">المفتاح الحالي لا يظهر ولا يتم إرساله للمتصفح. اترك حقل المفتاح فارغًا للاحتفاظ بالمفتاح الحالي.</p>
                </div>

                <form method="POST" action="{{ route('admin.settings.ai-providers.update', $provider) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="block text-right">
                            <span class="text-sm font-black text-gray-700">اسم المزود</span>
                            <input name="display_name" value="{{ old('display_name', $provider->public_name) }}" @cannot('settings.ai_providers.manage') readonly @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-right">
                        </label>
                        <label class="block text-right">
                            <span class="text-sm font-black text-gray-700">تفعيل المزود</span>
                            <select name="is_active" @cannot('settings.ai_providers.enable_disable') disabled @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-right">
                                <option value="0" @selected(!old('is_active', $provider->is_active))>Disabled</option>
                                <option value="1" @selected(old('is_active', $provider->is_active))>Enabled</option>
                            </select>
                        </label>
                        <label class="block text-right">
                            <span class="text-sm font-black text-gray-700">Timeout بالثواني</span>
                            <input type="number" name="default_timeout_seconds" value="{{ old('default_timeout_seconds', $provider->default_timeout_seconds ?? 180) }}" min="10" max="600" @cannot('settings.ai_providers.manage') readonly @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-right">
                        </label>
                        <label class="block text-right">
                            <span class="text-sm font-black text-gray-700">Max retries</span>
                            <input type="number" name="default_max_retries" value="{{ old('default_max_retries', $provider->default_max_retries ?? 2) }}" min="0" max="5" @cannot('settings.ai_providers.manage') readonly @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-right">
                        </label>
                    </div>

                    @can('settings.ai_providers.manage_credentials')
                        <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4 text-right">
                            <label class="block">
                                <span class="text-sm font-black text-gray-800">{{ $definition['credential_label'] ?? 'API Key' }}</span>
                                <input type="password" name="api_key" value="" autocomplete="new-password" class="mt-2 w-full rounded-xl border-gray-300 text-left" dir="ltr">
                            </label>
                            <p class="mt-2 text-xs text-amber-800">Leave blank to keep the existing API key.</p>
                            @if($credential)
                                <label class="mt-3 flex items-center gap-2 text-sm font-bold text-amber-900">
                                    <input type="checkbox" name="confirm_replace_credential" value="1" class="rounded border-gray-300">
                                    أؤكد استبدال المفتاح الحالي
                                </label>
                            @endif
                        </div>
                    @endcan

                    @can('settings.ai_providers.manage')
                        <button class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white hover:bg-indigo-700">حفظ الإعدادات</button>
                    @endcan
                </form>
            </section>

            <aside class="space-y-4">
                <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm text-right">
                    <h3 class="font-black text-gray-950">حالة المفتاح</h3>
                    <p class="mt-2 text-sm font-bold text-gray-700">
                        {{ $credential?->last_four ? 'Configured: ••••••••'.$credential->last_four : 'Not configured' }}
                    </p>
                    <p class="mt-2 text-xs text-gray-500">لن يتم عرض المفتاح الكامل بعد الحفظ.</p>
                </section>

                <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm text-right">
                    <h3 class="font-black text-gray-950">اختبار الاتصال</h3>
                    <p class="mt-2 text-sm text-gray-500">{{ $provider->last_health_check_message ?? 'لم يتم الاختبار بعد.' }}</p>
                    @can('settings.ai_providers.test_connection')
                        <form method="POST" action="{{ route('admin.settings.ai-providers.test', $provider) }}" class="mt-3 space-y-3">
                            @csrf
                            <label class="flex items-center gap-2 text-xs font-bold text-gray-700">
                                <input type="checkbox" name="confirm_billable_test" value="1" class="rounded border-gray-300">
                                أوافق على اختبار قد يتطلب طلب تحقق من المزود
                            </label>
                            <button class="w-full rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-black text-indigo-700">Test Connection</button>
                        </form>
                    @endcan
                </section>

                @can('settings.ai_providers.manage_credentials')
                    @if($credential)
                        <section class="rounded-2xl border border-red-100 bg-red-50 p-5 shadow-sm text-right">
                            <h3 class="font-black text-red-800">حذف المفتاح</h3>
                            <form method="POST" action="{{ route('admin.settings.ai-providers.credential.destroy', $provider) }}" class="mt-3" onsubmit="return confirm('سيتم حذف المفتاح وإيقاف المزود. هل تريد المتابعة؟')">
                                @csrf
                                @method('DELETE')
                                <label class="mb-3 flex items-center gap-2 text-xs font-bold text-red-800">
                                    <input type="checkbox" name="confirm_remove_credential" value="1" required class="rounded border-red-300">
                                    أؤكد حذف المفتاح
                                </label>
                                <button class="w-full rounded-xl bg-red-600 px-4 py-2 text-sm font-black text-white">Remove Credential</button>
                            </form>
                        </section>
                    @endif
                @endcan

                <a href="{{ route('admin.settings.ai-providers.models', $provider) }}" class="block rounded-xl bg-gray-900 px-4 py-3 text-center text-sm font-black text-white">Manage Models</a>
            </aside>
        </div>
    </div>
</x-admin-layout>
