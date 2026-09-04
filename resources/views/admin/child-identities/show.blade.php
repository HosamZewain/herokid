<x-admin-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-black text-slate-900">هوية الطفل: {{ $identity->child_name }}</h2>
            <p class="mt-1 text-xs text-slate-500" dir="ltr">{{ $identity->uuid }}</p>
        </div>
    </x-slot>

    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if(session('success'))<div class="rounded-xl bg-emerald-50 p-4 font-bold text-emerald-800">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="rounded-xl bg-red-50 p-4 font-bold text-red-700">{{ $errors->first() }}</div>@endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-2">
                    <a href="{{ $identity->trashed() ? route('admin.child-identities.trash') : route('admin.child-identities.index') }}" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-600">العودة للقائمة</a>
                    @if($identity->convertedOrder)
                        <a href="{{ route('admin.orders.groups.show', $identity->convertedOrder) }}" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white">فتح عملية الشراء</a>
                    @endif
                </div>
                <span class="rounded-full bg-violet-100 px-4 py-2 text-sm font-black text-violet-700">{{ $identity->statusLabel() }}{{ $identity->trashed() ? ' • محذوف مؤقتًا' : '' }}</span>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
                    <h3 class="border-b border-slate-100 pb-3 text-lg font-black text-slate-900">البيانات والربط</h3>
                    <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                        <div><dt class="font-bold text-slate-500">ولي الأمر</dt><dd class="mt-1 font-black">{{ $identity->parent_name }}</dd></div>
                        <div><dt class="font-bold text-slate-500">الهاتف / واتساب</dt><dd class="mt-1"><a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $identity->parent_phone) }}" class="font-black text-emerald-600" target="_blank">{{ $identity->parent_phone }}</a></dd></div>
                        <div><dt class="font-bold text-slate-500">البريد</dt><dd class="mt-1">{{ $identity->parent_email ?: '—' }}</dd></div>
                        <div><dt class="font-bold text-slate-500">حساب العميل</dt><dd class="mt-1">{{ $identity->user?->name ?: 'زائر' }}</dd></div>
                        <div>
                            <dt class="font-bold text-slate-500">الطفل</dt>
                            <dd class="mt-1 font-black">
                                {{ $identity->child_name }} •
                                {{ $identity->child_age !== null ? arabic_number($identity->child_age).' سنوات' : $identity->age_range }}
                                @if($identity->gender) • {{ $identity->genderLabel() }} @endif
                            </dd>
                        </div>
                        <div><dt class="font-bold text-slate-500">الفئة العمرية المحفوظة</dt><dd class="mt-1">{{ $identity->age_range }}</dd></div>
                        <div><dt class="font-bold text-slate-500">التصنيف والقصة</dt><dd class="mt-1">{{ $identity->selectedCategory?->name ?: '—' }} / {{ $identity->selectedStory?->title ?: '—' }}</dd></div>
                        <div><dt class="font-bold text-slate-500">الطلب المرتبط</dt><dd class="mt-1">{{ $identity->convertedOrder?->order_number ?: 'لم يتحول' }}{{ $identity->convertedOrder ? ' • '.$identity->convertedOrder->status : '' }}</dd></div>
                        <div><dt class="font-bold text-slate-500">موافقة المعالجة</dt><dd class="mt-1">{{ $identity->consent_version }} • {{ app_datetime($identity->consent_accepted_at, 'd/m/Y H:i') }}</dd></div>
                        <div><dt class="font-bold text-slate-500">موافقة التسويق</dt><dd class="mt-1">{{ app_datetime($identity->marketing_consent_at, 'd/m/Y H:i', '') ?: 'لم يوافق' }}</dd></div>
                        <div><dt class="font-bold text-slate-500">UTM</dt><dd class="mt-1 break-words">{{ collect([$identity->utm_source, $identity->utm_medium, $identity->utm_campaign, $identity->utm_content, $identity->utm_term])->filter()->implode(' / ') ?: '—' }}</dd></div>
                        <div><dt class="font-bold text-slate-500">صفحة الإحالة</dt><dd class="mt-1 break-all text-xs" dir="ltr">{{ $identity->referrer ?: '—' }}</dd></div>
                        <div><dt class="font-bold text-slate-500">آخر نشاط</dt><dd class="mt-1">{{ app_datetime($identity->last_activity_at, 'd/m/Y H:i', '') ?: '—' }}</dd></div>
                    </dl>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-black text-slate-900">ملخص التوليد</h3>
                    <dl class="mt-4 space-y-4 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">كل المحاولات</dt><dd class="font-black">{{ arabic_number($identity->total_attempts) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">نتائج قابلة للاستخدام</dt><dd class="font-black text-emerald-600">{{ arabic_number($identity->successful_attempts) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">المحاولات الفاشلة</dt><dd class="font-black text-red-600">{{ arabic_number($identity->failed_attempts) }}</dd></div>
                        @can('child_identities.view_costs')
                            <div class="border-t pt-4">
                                <dt class="text-slate-500">إجمالي التكلفة الداخلية</dt>
                                <dd class="mt-1 text-xl font-black text-indigo-700">{{ $identity->total_cost_usd !== null ? '$'.number_format((float) $identity->total_cost_usd, 6) : 'USD غير معروف' }}</dd>
                                <p class="mt-1 text-xs text-slate-400">EGP غير متاح — لا يُفترض سعر صرف.</p>
                            </div>
                        @endcan
                        <div class="border-t pt-4"><dt class="text-slate-500">المحاولة المعتمدة</dt><dd class="mt-1 font-black">{{ $identity->approvedAttempt ? 'رقم '.$identity->approvedAttempt->attempt_number : 'لا توجد' }}</dd></div>
                    </dl>
                </section>
            </div>

            <section class="rounded-2xl border border-violet-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-black text-slate-900">برومبت OpenAI لهذا الطلب</h3>
                        <p class="mt-1 text-xs leading-6 text-slate-500">
                            هذا هو النص الكامل الذي سيُرسل في المحاولة القادمة. أي تعديل هنا لا يغيّر لقطات البرومبت الثابتة للمحاولات السابقة.
                        </p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-black {{ $identity->prompt_override ? 'bg-violet-100 text-violet-700' : 'bg-slate-100 text-slate-600' }}">
                        {{ $identity->prompt_override ? 'مخصص لهذا الطلب' : 'البرومبت العام' }}
                    </span>
                </div>

                @if(!$identity->trashed())
                    @can('child_identities.generate')
                        <form method="POST" action="{{ route('admin.child-identities.prompt.update', $identity->id) }}" class="mt-5">
                            @csrf
                            @method('PATCH')
                            <textarea name="prompt_override" rows="13" dir="ltr" required minlength="50" maxlength="20000"
                                      class="w-full rounded-xl border-slate-300 font-mono text-xs leading-6 focus:border-violet-500 focus:ring-violet-500">{{ old('prompt_override', $nextPrompt) }}</textarea>
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                <p class="text-xs font-bold text-amber-700">احفظ أولًا، ثم استخدم «توليد / إعادة توليد إداري» لإنشاء محاولة جديدة بالنص المعدّل.</p>
                                <button class="rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-black text-white">حفظ برومبت الطلب</button>
                            </div>
                        </form>

                        @if($identity->prompt_override)
                            <form method="POST" action="{{ route('admin.child-identities.prompt.update', $identity->id) }}" class="mt-3">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="use_global_prompt" value="1">
                                <button class="text-xs font-black text-slate-500 underline decoration-dotted underline-offset-4">إلغاء التخصيص والعودة للبرومبت العام</button>
                            </form>
                        @endif
                    @else
                        <pre class="mt-5 whitespace-pre-wrap break-words rounded-xl bg-slate-950 p-4 text-xs leading-6 text-slate-100" dir="ltr">{{ $nextPrompt }}</pre>
                    @endcan
                @else
                    <pre class="mt-5 whitespace-pre-wrap break-words rounded-xl bg-slate-950 p-4 text-xs leading-6 text-slate-100" dir="ltr">{{ $nextPrompt }}</pre>
                @endif
            </section>

            @include('admin.child-identities._sharing')

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <div><h3 class="text-lg font-black text-slate-900">الصور الأصلية</h3><p class="mt-1 text-xs text-slate-500">تبقى الصور المرفوضة أو المزالة مسجلة ومحفوظة.</p></div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-black">{{ arabic_number($identity->photos->count()) }} صور</span>
                </div>
                @can('child_identities.view_media')
                    <div class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-5">
                        @foreach($identity->photos as $photo)
                            <article class="overflow-hidden rounded-xl border border-slate-200">
                                <img src="{{ $media['photos'][$photo->id] }}" alt="صورة أصلية خاصة" class="aspect-square w-full object-cover" referrerpolicy="no-referrer">
                                <div class="space-y-1 p-3 text-xs">
                                    <p class="font-black">{{ $photo->original_filename }}</p>
                                    <p class="text-slate-500">{{ $photo->width }}×{{ $photo->height }} • {{ number_format($photo->file_size / 1024) }} KB</p>
                                    <p class="{{ $photo->validation_status === 'valid' ? 'text-emerald-600' : 'text-amber-600' }}">{{ $photo->upload_status }} / {{ $photo->validation_status }}</p>
                                    @if($photo->validation_notes)<p class="text-slate-500">{{ $photo->validation_notes }}</p>@endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @else
                    <p class="mt-4 rounded-xl bg-amber-50 p-4 font-bold text-amber-800">تحتاج صلاحية عرض الوسائط الخاصة.</p>
                @endcan
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div><h3 class="text-lg font-black text-slate-900">كل محاولات التوليد</h3><p class="mt-1 text-xs text-slate-500">السجل لا يُستبدل عند إعادة التوليد.</p></div>
                    @if(!$identity->trashed())
                        @can('child_identities.generate')
                            <form method="POST" action="{{ route('admin.child-identities.generate', $identity->id) }}">
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                <button class="rounded-xl bg-violet-600 px-4 py-2 text-sm font-black text-white">توليد / إعادة توليد إداري</button>
                            </form>
                        @endcan
                    @endif
                </div>

                <div class="mt-5 space-y-5">
                    @forelse($identity->attempts->sortByDesc('attempt_number') as $attempt)
                        <article class="grid overflow-hidden rounded-2xl border {{ $identity->approved_attempt_id === $attempt->id ? 'border-emerald-400 ring-2 ring-emerald-100' : 'border-slate-200' }} lg:grid-cols-3">
                            @can('child_identities.view_media')
                                <div class="bg-slate-100">
                                    @if(isset($media['attempts'][$attempt->id]))
                                        <img src="{{ $media['attempts'][$attempt->id] }}" alt="مخرج المحاولة" class="h-full max-h-80 w-full object-contain" referrerpolicy="no-referrer">
                                    @else
                                        <div class="flex min-h-48 items-center justify-center text-sm text-slate-400">لا يوجد مخرج محفوظ</div>
                                    @endif
                                </div>
                            @endcan
                            <div class="space-y-3 p-5 text-sm {{ auth()->user()->hasPermission('child_identities.view_media') ? 'lg:col-span-2' : 'lg:col-span-3' }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h4 class="text-base font-black">المحاولة {{ arabic_number($attempt->attempt_number) }} <span class="text-xs font-normal text-slate-400">({{ $attempt->initiated_by === 'admin' ? 'إدارية' : 'بواسطة العميل' }})</span></h4>
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-black">{{ $attempt->statusLabel() }}</span>
                                </div>
                                <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    <div><dt class="text-slate-400">المزود / النموذج</dt><dd class="font-bold">{{ $attempt->provider }} / {{ $attempt->model }}</dd></div>
                                    <div><dt class="text-slate-400">نسخة البرومبت</dt><dd class="font-bold">{{ $attempt->prompt_version }}</dd></div>
                                    <div><dt class="text-slate-400">الحجم / الجودة</dt><dd class="font-bold">{{ $attempt->image_size }} / {{ $attempt->image_quality }}</dd></div>
                                    <div><dt class="text-slate-400">صور الإدخال</dt><dd class="font-bold">{{ arabic_number($attempt->input_photos_count) }}</dd></div>
                                    <div><dt class="text-slate-400">API request ID</dt><dd class="break-all font-mono text-xs">{{ $attempt->api_request_id ?: '—' }}</dd></div>
                                    <div><dt class="text-slate-400">المدة</dt><dd class="font-bold">{{ $attempt->duration_ms !== null ? number_format($attempt->duration_ms).' ms' : '—' }}</dd></div>
                                    <div><dt class="text-slate-400">بدأت</dt><dd class="font-bold">{{ app_datetime($attempt->started_at, 'd/m/Y H:i:s', '') ?: '—' }}</dd></div>
                                    <div><dt class="text-slate-400">اكتملت</dt><dd class="font-bold">{{ app_datetime($attempt->completed_at, 'd/m/Y H:i:s', '') ?: '—' }}</dd></div>
                                    @can('child_identities.view_costs')
                                        <div><dt class="text-slate-400">التكلفة USD</dt><dd class="font-bold">{{ $attempt->cost_usd !== null ? '$'.number_format((float) $attempt->cost_usd, 6) : 'غير معروفة' }}</dd></div>
                                        <div><dt class="text-slate-400">الفوترة / الحساب</dt><dd class="font-bold">{{ $attempt->billing_status }} / {{ $attempt->cost_calculation_method }}</dd></div>
                                        <div><dt class="text-slate-400">EGP / سعر الصرف</dt><dd class="font-bold">غير متاح / غير متاح</dd></div>
                                    @endcan
                                </dl>
                                <details class="rounded-xl bg-slate-50 p-3">
                                    <summary class="cursor-pointer font-black text-slate-600">Prompt snapshot وبيانات التتبع</summary>
                                    <pre class="mt-3 whitespace-pre-wrap break-words text-xs leading-6">{{ $attempt->prompt_snapshot }}</pre>
                                    <p class="mt-3 font-mono text-xs text-slate-400">SHA-256: {{ $attempt->prompt_hash }}</p>
                                </details>
                                @can('child_identities.view_costs')
                                    <details class="rounded-xl bg-indigo-50 p-3">
                                        <summary class="cursor-pointer font-black text-indigo-700">Usage ولقطة قاعدة التسعير</summary>
                                        <pre class="mt-3 whitespace-pre-wrap break-words text-xs leading-6" dir="ltr">{{ json_encode([
                                            'usage' => data_get($attempt->response_metadata, 'usage'),
                                            'pricing_rule' => data_get($attempt->response_metadata, 'pricing_rule'),
                                            'billing_status' => $attempt->billing_status,
                                            'calculation_method' => $attempt->cost_calculation_method,
                                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endcan
                                @if($attempt->safe_error_message || $attempt->technical_error)
                                    <div class="rounded-xl border border-red-100 bg-red-50 p-3 text-red-700">
                                        <p class="font-bold">{{ $attempt->error_code }} — {{ $attempt->safe_error_message }}</p>
                                        @if($attempt->technical_error)<details class="mt-2 text-xs"><summary>تفاصيل تقنية</summary><p class="mt-2 break-words">{{ $attempt->technical_error }}</p></details>@endif
                                    </div>
                                @endif
                                @if(!$identity->trashed() && in_array($attempt->status, ['succeeded', 'rejected'], true))
                                    <div class="flex flex-wrap gap-2">
                                        @can('child_identities.approve')
                                            @if($identity->approved_attempt_id !== $attempt->id)
                                                <form method="POST" action="{{ route('admin.child-identities.attempts.approve', [$identity->id, $attempt]) }}">@csrf<button class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-black text-white">اعتماد هذه المحاولة</button></form>
                                            @endif
                                            @if($attempt->status === 'succeeded')
                                                <form method="POST" action="{{ route('admin.child-identities.attempts.reject', [$identity->id, $attempt]) }}" class="flex gap-2">
                                                    @csrf
                                                    <input name="reason" required minlength="3" placeholder="سبب الرفض" class="rounded-xl border-slate-300 text-xs">
                                                    <button class="rounded-xl bg-red-600 px-4 py-2 text-xs font-black text-white">رفض</button>
                                                </form>
                                            @endif
                                        @endcan
                                    </div>
                                @endif
                            </div>
                        </article>
                    @empty
                        <p class="rounded-xl bg-slate-50 p-8 text-center text-slate-500">لا توجد محاولات بعد.</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-lg font-black text-slate-900">السجل الزمني الكامل</h3>
                <div class="mt-5 space-y-4 border-r-2 border-indigo-100 pr-5">
                    @foreach($identity->events->sortByDesc('created_at') as $event)
                        <article class="relative">
                            <span class="absolute -right-[1.63rem] top-1 h-3 w-3 rounded-full bg-indigo-500 ring-4 ring-white"></span>
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-black text-slate-800">{{ $event->event_type }}</p>
                                <span class="text-xs text-slate-400">{{ app_datetime($event->created_at, 'd/m/Y H:i:s') }} • {{ $event->actor_type }} / {{ $event->source }}</span>
                            </div>
                            @if($event->description)<p class="mt-1 text-sm text-slate-600">{{ $event->description }}</p>@endif
                            @if($event->from_status || $event->to_status)<p class="mt-1 text-xs text-slate-400">{{ $event->from_status }} ← {{ $event->to_status }}</p>@endif
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="rounded-2xl border border-red-200 bg-red-50 p-5">
                <h3 class="font-black text-red-900">إدارة الاحتفاظ والحذف</h3>
                <p class="mt-2 text-sm leading-7 text-red-700">الحذف المؤقت يحتفظ بكل الملفات. الحذف النهائي يمسح الوسائط والسجلات التابعة ويترك بيان تدقيق للطلبات المتأثرة.</p>
                @if(!$identity->trashed())
                    @can('child_identities.delete')
                        <form method="POST" action="{{ route('admin.child-identities.destroy', $identity->id) }}" class="mt-4 grid gap-3 md:grid-cols-3">
                            @csrf @method('DELETE')
                            <input name="reason" required minlength="5" placeholder="سبب الحذف" class="rounded-xl border-red-200 md:col-span-2">
                            <input name="confirmation" required placeholder="اكتب UUID للتأكيد" class="rounded-xl border-red-200 font-mono text-xs" dir="ltr">
                            <button class="rounded-xl bg-red-600 px-4 py-3 font-black text-white md:col-span-3">نقل إلى سلة المحذوفات</button>
                        </form>
                    @endcan
                @else
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        @can('child_identities.restore')
                            <form method="POST" action="{{ route('admin.child-identities.restore', $identity->id) }}">@csrf<button class="w-full rounded-xl bg-emerald-600 px-4 py-3 font-black text-white">استعادة الطلب</button></form>
                        @endcan
                        @can('child_identities.force_delete')
                            <form method="POST" action="{{ route('admin.child-identities.force-delete', $identity->id) }}" class="space-y-3">
                                @csrf @method('DELETE')
                                <input name="reason" required minlength="10" placeholder="سبب الحذف النهائي" class="w-full rounded-xl border-red-200">
                                <input name="confirmation" required placeholder="اكتب UUID للتأكيد" class="w-full rounded-xl border-red-200 font-mono text-xs" dir="ltr">
                                <button class="w-full rounded-xl bg-slate-950 px-4 py-3 font-black text-white">حذف نهائي للطلب والوسائط</button>
                            </form>
                        @endcan
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-admin-layout>
