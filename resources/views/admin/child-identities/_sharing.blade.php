<section class="rounded-2xl border border-fuchsia-200 bg-white p-5 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-lg font-black text-slate-900">مشاركة الهوية</h3>
            <p class="mt-1 text-xs text-slate-500">بطاقات عامة منفصلة؛ لا تعرض الصور الأصلية أو بيانات ولي الأمر.</p>
        </div>
        @can('child_identities.view_share_report')
            <a href="{{ route('admin.child-identities.share-report') }}" class="rounded-xl bg-fuchsia-50 px-4 py-2 text-sm font-black text-fuchsia-700">فتح تقرير المشاركة</a>
        @endcan
    </div>

    @if(!$identity->share)
        <p class="mt-5 rounded-xl bg-slate-50 p-5 text-center font-bold text-slate-500">لم ينشئ ولي الأمر مشاركة عامة لهذه الهوية.</p>
    @else
        @php $share = $identity->share; @endphp
        <div class="mt-5 grid gap-5 lg:grid-cols-3">
            <div class="space-y-3 rounded-2xl bg-slate-50 p-4 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">الحالة</span><strong>{{ $share->status }}</strong></div>
                <div class="flex justify-between"><span class="text-slate-500">الرابط</span><strong>{{ $share->share_enabled ? 'مفعّل' : 'متوقف' }}</strong></div>
                <div class="flex justify-between"><span class="text-slate-500">المحاولة</span><strong>{{ $share->generationAttempt?->attempt_number ?: '—' }}</strong></div>
                <div class="flex justify-between"><span class="text-slate-500">الاسم</span><strong>{{ $share->display_child_first_name ? 'الاسم الأول' : 'بدون اسم' }}</strong></div>
                <div class="flex justify-between"><span class="text-slate-500">الموافقة</span><strong>{{ app_datetime($share->consent_accepted_at, 'd/m/Y H:i') }}</strong></div>
                <div class="flex justify-between"><span class="text-slate-500">آخر مشاهدة</span><strong>{{ app_datetime($share->last_viewed_at, 'd/m/Y H:i', '') ?: '—' }}</strong></div>
                <label class="block text-slate-500">الرابط العام
                    <input readonly value="{{ $sharePublicUrl }}" class="mt-1 w-full rounded-xl border-slate-200 bg-white text-xs" dir="ltr">
                </label>
                @if($share->share_enabled && $share->status === 'ready')
                    <a href="{{ $sharePublicUrl }}" target="_blank" rel="noopener noreferrer" class="block rounded-xl bg-indigo-600 px-4 py-2 text-center font-black text-white">فتح الصفحة العامة</a>
                @endif
            </div>

            <div class="grid grid-cols-3 gap-2 lg:col-span-2">
                @foreach(['feed' => 'Feed', 'story' => 'Story', 'og' => 'OG'] as $variant => $label)
                    <article class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                        @if(isset($shareMedia[$variant]))
                            <img src="{{ $shareMedia[$variant] }}" alt="بطاقة {{ $label }}" class="aspect-[4/5] w-full object-contain">
                        @else
                            <div class="flex aspect-[4/5] items-center justify-center text-xs text-slate-400">غير متاحة</div>
                        @endif
                        <p class="p-2 text-center text-xs font-black">{{ $label }}</p>
                    </article>
                @endforeach
            </div>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach([
                'المشاهدات' => $share->total_views,
                'نقرات CTA' => $share->total_cta_clicks,
                'هويات جديدة' => $share->total_identity_starts,
                'هويات مكتملة' => $share->total_identity_completions,
                'طلبات' => $share->total_orders,
                'التحويل' => $share->total_views ? number_format(($share->total_orders / $share->total_views) * 100, 1).'%' : '0%',
            ] as $label => $value)
                <div class="rounded-xl bg-fuchsia-50 p-3 text-center"><strong class="block text-lg text-fuchsia-800">{{ is_numeric($value) ? arabic_number($value) : $value }}</strong><span class="text-xs text-fuchsia-600">{{ $label }}</span></div>
            @endforeach
        </div>

        <details class="mt-5 rounded-xl border border-slate-200 p-4">
            <summary class="cursor-pointer font-black text-slate-700">النص والهاشتاجات والقنوات</summary>
            <pre class="mt-3 whitespace-pre-wrap rounded-xl bg-slate-50 p-3 text-xs leading-6">{{ $share->caption_snapshot }}

{{ $share->hashtags_snapshot }}</pre>
            <div class="mt-3 flex flex-wrap gap-2">
                @forelse($shareChannelBreakdown as $channel => $count)
                    <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{{ $channel }}: {{ arabic_number($count) }}</span>
                @empty
                    <span class="text-xs text-slate-400">لا توجد نقرات قنوات بعد.</span>
                @endforelse
            </div>
        </details>

        @can('child_identities.manage_shares')
            <div class="mt-5 grid gap-3 rounded-2xl border border-slate-200 p-4 lg:grid-cols-2">
                <form method="POST" action="{{ route('admin.child-identity-shares.update', $share) }}" class="space-y-3">
                    @csrf @method('PATCH')
                    <label class="block text-sm font-bold text-slate-700">المحاولة المعتمدة للمشاركة
                        <select name="generation_attempt_id" class="mt-1 w-full rounded-xl border-slate-300">
                            @foreach($identity->attempts->where('id', $identity->approved_attempt_id) as $attempt)
                                <option value="{{ $attempt->id }}" @selected($share->generation_attempt_id === $attempt->id)>المحاولة {{ $attempt->attempt_number }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm font-bold">
                        <input type="checkbox" name="display_child_first_name" value="1" @checked($share->display_child_first_name) class="rounded border-slate-300 text-fuchsia-600">
                        عرض الاسم الأول فقط
                    </label>
                    <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-black text-white">حفظ وتجهيز بطاقات جديدة</button>
                </form>
                <div class="flex flex-wrap content-start gap-2">
                    <form method="POST" action="{{ route('admin.child-identity-shares.regenerate', $share) }}">@csrf<button class="rounded-xl bg-violet-600 px-4 py-2 text-sm font-black text-white">إعادة تجهيز البطاقات</button></form>
                    @if($share->share_enabled)
                        <form method="POST" action="{{ route('admin.child-identity-shares.revoke', $share) }}">@csrf<button class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-black text-white">إيقاف الرابط</button></form>
                    @else
                        <form method="POST" action="{{ route('admin.child-identity-shares.reenable', $share) }}">@csrf<button class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-black text-white">إعادة التفعيل</button></form>
                    @endif
                    <form method="POST" action="{{ route('admin.child-identity-shares.cards.destroy', $share) }}" class="flex gap-2">
                        @csrf @method('DELETE')
                        <input name="confirmation" required placeholder="اكتب {{ $share->id }}" class="w-28 rounded-xl border-red-200 text-xs" dir="ltr">
                        <button class="rounded-xl bg-red-600 px-4 py-2 text-sm font-black text-white">حذف البطاقات العامة</button>
                    </form>
                </div>
            </div>
        @endcan

        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            <div>
                <h4 class="font-black text-slate-800">الهويات المحالة</h4>
                <div class="mt-2 space-y-2">
                    @forelse($referredIdentities as $referredIdentity)
                        <a href="{{ route('admin.child-identities.show', $referredIdentity) }}" class="block rounded-xl bg-slate-50 p-3 text-sm font-bold text-indigo-700">
                            طلب هوية #{{ $referredIdentity->id }} — {{ $referredIdentity->statusLabel() }}
                        </a>
                    @empty
                        <p class="text-sm text-slate-400">لا توجد هويات محالة.</p>
                    @endforelse
                </div>
            </div>
            <div>
                <h4 class="font-black text-slate-800">الطلبات المحالة</h4>
                <div class="mt-2 space-y-2">
                    @forelse($referredOrders as $referredOrder)
                        <a href="{{ route('admin.orders.show', $referredOrder) }}" class="block rounded-xl bg-slate-50 p-3 text-sm font-bold text-indigo-700">
                            {{ $referredOrder->order_number ?: '#'.$referredOrder->id }}
                        </a>
                    @empty
                        <p class="text-sm text-slate-400">لا توجد طلبات محالة.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</section>
