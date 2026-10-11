<x-front-layout>
    <x-slot name="pageTitle">طلب حذف حساب HeroKid وبياناته</x-slot>
    <x-slot name="pageDescription">قدّم طلب حذف حساب HeroKid بأمان، وتعرّف على خطوات المعالجة والبيانات التي يشملها الحذف والسجلات التي قد يلزم الاحتفاظ بها.</x-slot>
    <x-slot name="robots">noindex, follow</x-slot>

    <div class="bg-slate-50 py-12 sm:py-16">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6">
            <header class="rounded-3xl bg-indigo-950 p-6 text-white sm:p-8">
                <p class="text-sm font-bold text-indigo-200">خصوصيتك وبيانات طفلك</p>
                <h1 class="mt-3 text-3xl font-black leading-normal">طلب حذف حساب HeroKid وبياناته</h1>
                <p class="mt-4 leading-8 text-indigo-100">يمكنك تقديم الطلب هنا مباشرة، سواء أنشأت حسابك على الموقع أو التطبيق. حذف التطبيق من هاتفك لا يحذف حسابك، وتعطيل الحساب وحده ليس حذفًا للبيانات.</p>
                <a href="#request-form" class="mt-4 inline-block rounded-xl bg-white px-5 py-3 font-bold text-indigo-950">انتقل إلى نموذج الطلب</a>
            </header>

            @if(session('account_deletion_receipt'))
                <section role="status" aria-labelledby="deletion-receipt-title" class="rounded-3xl border border-emerald-200 bg-emerald-50 p-6">
                    <h2 id="deletion-receipt-title" class="text-xl font-black text-emerald-900">تم تسجيل طلب حذف الحساب</h2>
                    <p class="mt-3 leading-8 text-emerald-900">الحالة: {{ session('account_deletion_receipt.status') }}. هذا تأكيد استلام الطلب، وليس تأكيدًا أن الحساب والملفات حُذفت بالفعل.</p>
                    <p class="mt-3 text-sm text-emerald-900">احتفظ برقم الطلب للمتابعة:</p>
                    <p class="mt-2 break-all font-bold text-emerald-950"><bdi dir="ltr">{{ session('account_deletion_receipt.reference') }}</bdi></p>
                    <p class="mt-3 leading-8 text-emerald-900">موعد المعالجة المتوقع: {{ session('account_deletion_receipt.due_date') }}. عند اكتمال المعالجة يتم تأكيد ما حُذف وأي بيانات يلزم الاحتفاظ بها.</p>
                </section>
            @endif

            <section aria-labelledby="deletion-steps-title" class="rounded-3xl bg-white p-6 shadow-sm sm:p-8">
                <h2 id="deletion-steps-title" class="text-xl font-black text-slate-950">كيف أقدّم الطلب؟</h2>
                <ol class="mt-4 list-decimal space-y-3 pr-5 leading-8 text-slate-700">
                    <li>أدخل رقم الموبايل أو البريد الإلكتروني المرتبط بحسابك وكلمة مرور الحساب في النموذج أدناه.</li>
                    <li>اقرأ تفاصيل الحذف، ثم وافق واضغط «إرسال طلب حذف الحساب».</li>
                    <li>احتفظ برقم الطلب الذي سيظهر لك. يراجعه الفريق ضمن طلبات الخصوصية؛ المدة المتوقعة للمعالجة حتى 30 يومًا.</li>
                </ol>
                <p class="mt-4 leading-8 text-slate-600">التقديم مجاني، ولا يلزم سبب للحذف أو مكالمة أو رسالة دعم. لا تحذف الصفحة أي بيانات فور الضغط، ولا تلغي طلب شراء أو تعيد دفعة مالية تلقائيًا.</p>
            </section>

            <section aria-labelledby="deletion-scope-title" class="rounded-3xl bg-white p-6 shadow-sm sm:p-8">
                <h2 id="deletion-scope-title" class="text-xl font-black text-slate-950">ما الذي يشمله طلب الحذف؟</h2>
                <ul class="mt-4 list-disc space-y-3 pr-5 leading-8 text-slate-700">
                    <li>حسابك وبيانات التواصل والعناوين المحفوظة المرتبطة به، ما لم يلزم الاحتفاظ بجزء منها للأسباب الموضحة أدناه.</li>
                    <li>ملفات الأطفال وبيانات التخصيص والصور المحفوظة لإعادة الاستخدام، والبيانات المرتبطة بالحساب التي لا يلزم الاحتفاظ بها.</li>
                    <li>جلسات الدخول ورموز الوصول وربط أجهزة التطبيق بالحساب عند تنفيذ الحذف.</li>
                </ul>
                <h3 class="mt-6 text-lg font-black text-slate-950">ما الذي قد يُحتفظ به، ولماذا؟</h3>
                <ul class="mt-4 list-disc space-y-3 pr-5 leading-8 text-slate-700">
                    <li>الحد الأدنى من سجلات الطلبات والفواتير والدفعات والشحن اللازم للالتزامات المحاسبية والقانونية، أو لمعالجة نزاع قائم.</li>
                    <li>البيانات اللازمة لتنفيذ طلب شراء قائم تُراجع مع الفريق. صور ومرفقات الطلبات تدخل ضمن مراجعة الحذف؛ لا تُحتفظ بها لمجرد تسهيل إعادة الطلب بعد حذف الحساب.</li>
                    <li>سجل محدود لطلب الخصوصية وإجراءات تنفيذه، لإثبات الاستجابة. إذا لزم إبقاء بيانات في سجل ملزم أو نسخة احتياطية، يُوضح سبب الاحتفاظ ومدته عند معالجة الطلب.</li>
                </ul>
                <p class="mt-4 leading-8 text-slate-600">لا تُستخدم البيانات المحتفظ بها لهذه الأسباب في التسويق أو لإنشاء تخصيصات جديدة. يُوضَّح لك عند إتمام الطلب ما حُذف وما بقي وسبب ومدة الاحتفاظ به. اقرأ أيضًا <a href="{{ route('privacy') }}" class="font-bold text-indigo-700 underline">سياسة الخصوصية</a>.</p>
            </section>

            <section id="request-form" aria-labelledby="deletion-form-title" class="rounded-3xl bg-white p-6 shadow-sm sm:p-8">
                <h2 id="deletion-form-title" class="text-xl font-black text-slate-950">تقديم طلب حذف الحساب</h2>
                <p class="mt-3 leading-8 text-slate-600">تأكيد كلمة المرور يحمي حسابك من طلبات الحذف غير المصرّح بها. لا تطلب منك هذه الصفحة بيانات طفلك أو صوره أو بيانات دفع.</p>
                @if($errors->any())
                    <div role="alert" class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-900">
                        <p class="font-bold">لم يتم تسجيل الطلب. راجع البيانات التالية:</p>
                        <ul class="mt-2 list-disc space-y-2 pr-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif
                <form method="POST" action="{{ route('account-deletion.store') }}" class="mt-6 space-y-5">
                    @csrf
                    <div>
                        <label for="deletion-login" class="block font-bold text-slate-800">رقم الموبايل أو البريد الإلكتروني</label>
                        <input id="deletion-login" name="login" type="text" autocomplete="username" required maxlength="255" value="{{ old('login', auth()->user()?->phone ?: auth()->user()?->email) }}" dir="ltr" class="mt-2 w-full rounded-xl border-slate-300 bg-white text-slate-950">
                    </div>
                    <div>
                        <label for="deletion-password" class="block font-bold text-slate-800">كلمة مرور حساب HeroKid</label>
                        <input id="deletion-password" name="password" type="password" autocomplete="current-password" required maxlength="1024" dir="ltr" class="mt-2 w-full rounded-xl border-slate-300 bg-white text-slate-950">
                        <a href="{{ route('password.request') }}" class="mt-2 inline-block text-sm font-bold text-indigo-700 underline">نسيت كلمة المرور؟</a>
                    </div>
                    <label for="deletion-confirmation" class="flex items-start gap-3 rounded-2xl bg-slate-50 p-4 leading-8 text-slate-800">
                        <input id="deletion-confirmation" type="checkbox" name="confirmation" value="DELETE_MY_ACCOUNT" required class="mt-2 rounded border-slate-300 text-indigo-600">
                        <span>أطلب حذف حسابي وبياناته، وفهمت نطاق الحذف والاستثناءات ومدة المعالجة. الطلب ليس مجرد تعطيل مؤقت للحساب.</span>
                    </label>
                    <button type="submit" class="w-full rounded-2xl bg-indigo-700 px-6 py-4 font-black text-white hover:bg-indigo-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-600">إرسال طلب حذف الحساب</button>
                </form>
                <p class="mt-5 text-sm leading-7 text-slate-600">إذا تعذر الوصول لحسابك، يمكنك طلب مساعدة استعادة الوصول عبر <a href="{{ route('contact') }}" class="font-bold text-indigo-700 underline">صفحة التواصل</a>. هذا مسار مساعدة اختياري، وليس شرطًا لتقديم الطلب بالنموذج.</p>
            </section>
        </div>
    </div>
</x-front-layout>
