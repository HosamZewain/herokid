<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-gray-800">تعديل العميل</h2>
                <p class="text-xs text-gray-500 mt-1">{{ $customer['name'] }} — {{ $customer['type_label'] }}</p>
            </div>
            <a href="{{ route('admin.customers.show', $customerKey) }}"
                class="px-4 py-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm font-bold transition">
                العودة للعميل
            </a>
        </div>
    </x-slot>

    <div class="max-w-3xl space-y-6">
        @if($customer['type'] === 'guest')
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-right text-sm text-amber-800">
                هذا العميل أرسل طلباً بدون حساب. عند حفظ هذه الصفحة سيتم إنشاء حساب عميل وربط طلباته السابقة به.
                كلمة المرور مطلوبة حتى يمكنك إرسال بيانات الدخول له عبر واتساب.
            </div>
        @endif

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <form method="POST" action="{{ route('admin.customers.update', $customerKey) }}" class="space-y-5" dir="rtl">
                @csrf
                @method('PUT')

                <div>
                    <label for="name" class="block text-sm font-bold text-gray-700 mb-1.5">اسم العميل</label>
                    <input id="name" name="name" type="text" required
                        value="{{ old('name', $customer['name'] !== 'Not available' ? $customer['name'] : '') }}"
                        class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label for="phone" class="block text-sm font-bold text-gray-700 mb-1.5">الهاتف / واتساب</label>
                        <input id="phone" name="phone" type="tel" required dir="ltr"
                            value="{{ old('phone', $customer['phone'] !== 'Not available' ? $customer['phone'] : '') }}"
                            class="block w-full rounded-xl border-gray-300 text-left shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-bold text-gray-700 mb-1.5">البريد الإلكتروني (اختياري)</label>
                        <input id="email" name="email" type="email" dir="ltr"
                            value="{{ old('email', $customer['email'] !== 'Not available' ? $customer['email'] : '') }}"
                            class="block w-full rounded-xl border-gray-300 text-left shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                    <h3 class="text-sm font-extrabold text-gray-900 mb-2">
                        {{ $customer['type'] === 'guest' ? 'إنشاء كلمة مرور للحساب الجديد' : 'تغيير كلمة المرور (اختياري)' }}
                    </h3>
                    <p class="text-xs text-gray-500 mb-4">
                        {{ $customer['type'] === 'guest' ? 'سيستخدم العميل الهاتف أو البريد مع كلمة المرور للدخول ومتابعة الطلب.' : 'اتركها فارغة إذا لم ترغب في تغيير كلمة المرور الحالية.' }}
                    </p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label for="password" class="block text-sm font-bold text-gray-700 mb-1.5">كلمة المرور</label>
                            <input id="password" name="password" type="text"
                                {{ $customer['type'] === 'guest' ? 'required' : '' }}
                                class="block w-full rounded-xl border-gray-300 text-left shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                dir="ltr"
                                autocomplete="new-password">
                            <x-input-error :messages="$errors->get('password')" class="mt-1" />
                        </div>

                        <div>
                            <label for="password_confirmation" class="block text-sm font-bold text-gray-700 mb-1.5">تأكيد كلمة المرور</label>
                            <input id="password_confirmation" name="password_confirmation" type="text"
                                {{ $customer['type'] === 'guest' ? 'required' : '' }}
                                class="block w-full rounded-xl border-gray-300 text-left shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                dir="ltr"
                                autocomplete="new-password">
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3 justify-end">
                    <a href="{{ route('admin.customers.show', $customerKey) }}"
                        class="inline-flex justify-center rounded-xl border border-gray-200 px-5 py-3 text-sm font-bold text-gray-600 transition hover:bg-gray-50">
                        إلغاء
                    </a>
                    <button type="submit"
                        class="inline-flex justify-center rounded-xl bg-indigo-600 px-6 py-3 text-sm font-bold text-white transition hover:bg-indigo-700">
                        {{ $customer['type'] === 'guest' ? 'إنشاء الحساب وربط الطلبات' : 'حفظ التعديلات' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-admin-layout>
