<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-black">إعدادات محادثات واتساب — RoboDesk</h1></x-slot>
    <form method="POST" action="{{ route('admin.robodesk.conversation-settings.update') }}" class="mx-auto max-w-2xl space-y-5 rounded-2xl border bg-white p-6 shadow-sm">
        @csrf @method('PUT')
        <p class="text-sm text-gray-600">حساب تكامل مخصص لديه صلاحية قراءة المحادثات. لا نرسل رسائل ولا نغيّر حالة الطلب. بيانات الدخول محفوظة مشفّرة على الخادم فقط.</p>
        @if(session('success'))<p role="status" class="rounded-xl bg-emerald-50 p-3 text-emerald-800">{{ session('success') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-3 text-red-700">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <label class="block"><span class="mb-2 block font-bold">بريد حساب RoboDesk</span><input required type="email" name="email" value="{{ old('email', $conversationSettings?->email) }}" autocomplete="off" dir="ltr" class="w-full rounded-xl border-gray-300"></label>
        <label class="block"><span class="mb-2 block font-bold">كلمة المرور</span><input type="password" name="password" autocomplete="new-password" dir="ltr" class="w-full rounded-xl border-gray-300" placeholder="{{ filled($conversationSettings?->password) ? 'محفوظة — اترك الحقل فارغاً للإبقاء عليها' : 'أدخل كلمة المرور' }}"><span class="mt-2 block text-xs text-gray-500">لن تُعرض كلمة المرور المحفوظة. تغييرها هنا يستبدلها؛ إيقاف الربط لا يحذف الرسائل.</span></label>
        <label class="block"><span class="mb-2 block font-bold">عدد المحادثات الأخيرة التي تُجلب عند الفتح</span><input type="number" min="1" max="50" required name="conversation_limit" value="{{ old('conversation_limit', $conversationSettings?->conversation_limit ?? 20) }}" class="w-24 rounded-xl border-gray-300"><span class="mt-2 block text-xs text-gray-500">من 1 إلى 50 محادثة. كل الرسائل التي تصل تُحفظ؛ حد RoboDesk لا يحذف الرسائل القديمة من HeroKid.</span></label>
        <label class="flex items-center gap-3"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $conversationSettings?->enabled)) class="rounded"><span class="font-bold">تفعيل قراءة محادثات واتساب</span></label>
        <div class="flex gap-3"><button class="rounded-xl bg-violet-600 px-5 py-3 font-bold text-white">حفظ الإعدادات</button><a href="{{ route('admin.orders.index') }}" class="rounded-xl border px-5 py-3">العودة إلى الطلبات</a></div>
        <p class="text-xs text-gray-500">صلاحية عرض المحادثات تُمنح للموظفين من إدارة الصلاحيات: «عرض ومزامنة محادثات واتساب العملاء». طريقة تسجيل الدخول الحالية مؤقتة لحين إصدار مفتاح تكامل من RoboDesk.</p>
    </form>
</x-admin-layout>
