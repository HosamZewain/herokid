<section data-home-section="child_identity" class="relative overflow-hidden py-16 sm:py-20" dir="rtl"
         style="background: linear-gradient(145deg, #eef2ff 0%, #faf5ff 48%, #fff7ed 100%);">
    <div class="pointer-events-none absolute inset-0 opacity-25"
         style="background-image: radial-gradient(circle, #8b5cf6 1px, transparent 1px); background-size: 28px 28px;"></div>
    <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-violet-200/50 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-28 -left-20 h-72 w-72 rounded-full bg-orange-200/50 blur-3xl"></div>

    <div class="relative z-10 mx-auto grid max-w-7xl items-center gap-10 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
        <div class="text-right">
            <span class="inline-flex items-center gap-2 rounded-full border border-violet-200 bg-white/80 px-4 py-2 text-xs font-black text-violet-700 shadow-sm">
                ميزة جديدة • مجانية
            </span>
            <h2 class="mt-5 text-3xl font-black leading-tight text-slate-950 sm:text-4xl lg:text-5xl">
                {{ setting('home_child_identity_title', 'اصنع هوية طفلك قبل اختيار القصة') }}
            </h2>
            <p class="mt-5 max-w-2xl text-base leading-8 text-slate-600 sm:text-lg">
                {{ setting('home_child_identity_subtitle', 'ارفع صور طفلك مرة واحدة، واحصل على هوية بصرية جاهزة لتختار بعدها القصة المناسبة له.') }}
            </p>

            <div class="mt-8 lg:hidden">
                @include('front.child-identity._home-visual-card')
            </div>

            <div class="mt-7 grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-white bg-white/80 p-4 shadow-sm backdrop-blur">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-100 font-black text-indigo-700">١</span>
                    <p class="mt-3 text-sm font-black text-slate-900">ارفع صورتين أو ٣ صور</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">اختيار متعدد ورفع تلقائي.</p>
                </div>
                <div class="rounded-2xl border border-white bg-white/80 p-4 shadow-sm backdrop-blur">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-100 font-black text-violet-700">٢</span>
                    <p class="mt-3 text-sm font-black text-slate-900">ننشئ الهوية</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">تظهر النتيجة أمامك تلقائيًا.</p>
                </div>
                <div class="rounded-2xl border border-white bg-white/80 p-4 shadow-sm backdrop-blur">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-orange-100 font-black text-orange-700">٣</span>
                    <p class="mt-3 text-sm font-black text-slate-900">اختر القصة</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">استخدم الهوية في قصة طفلك.</p>
                </div>
            </div>

            <a href="{{ route('child-identity.index') }}"
               class="mt-8 inline-flex min-h-14 w-full items-center justify-center rounded-2xl bg-gradient-to-l from-indigo-600 via-violet-600 to-fuchsia-600 px-8 py-4 text-base font-black text-white shadow-xl shadow-violet-200 transition hover:-translate-y-1 hover:shadow-2xl sm:w-auto">
                {{ setting('home_child_identity_cta', 'ابدأ مجانًا') }}
            </a>
        </div>

        <div class="hidden lg:block">
            @include('front.child-identity._home-visual-card')
        </div>
    </div>
</section>
