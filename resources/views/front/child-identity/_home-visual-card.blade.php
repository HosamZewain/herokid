<div class="relative mx-auto w-full max-w-lg">
    <div class="absolute -inset-5 rounded-[2.5rem] bg-gradient-to-br from-indigo-300/50 via-violet-200/40 to-orange-200/50 blur-2xl"></div>
    <div class="relative overflow-hidden rounded-[2rem] border border-white/80 bg-white p-4 shadow-2xl sm:p-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-black text-violet-600">من صورتين إلى هوية واحدة</p>
                <p class="mt-1 text-lg font-black text-slate-950">نفس طفلك من كل زاوية</p>
            </div>
            <span class="rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-black text-emerald-700">جاهزة للقصة</span>
        </div>

        <div class="mt-5 overflow-hidden rounded-3xl bg-slate-100">
            <img src="{{ url(setting('img_home_child_identity', \App\Support\SiteImages::path('img_home_child_identity'))) }}"
                 alt="صورتان حقيقيتان لنفس الطفل تتحولان إلى هوية متناسقة من زوايا وتعبيرات متعددة"
                 width="1200"
                 height="900"
                 class="aspect-[4/3] w-full object-cover"
                 loading="lazy"
                 decoding="async">
        </div>

        <div class="mt-4 grid grid-cols-3 gap-2 text-center text-xs font-black">
            <span class="rounded-xl bg-indigo-50 px-2 py-3 text-indigo-700">صورتان حقيقيتان</span>
            <span class="rounded-xl bg-violet-50 px-2 py-3 text-violet-700">هوية متناسقة</span>
            <span class="rounded-xl bg-orange-50 px-2 py-3 text-orange-700">جاهزة للقصة</span>
        </div>
    </div>
</div>
