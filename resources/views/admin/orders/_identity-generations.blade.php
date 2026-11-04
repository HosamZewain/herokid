@can('orders.photos.view')
    @if($order->child_identity_request_id)
        @php
            $identityAttempts = $order->childIdentityRequest->attempts()->latest('id')->get();
            $latestIdentity = $identityAttempts->first();
            $identityRegenerations = \App\Models\OrderIdentityRegeneration::where('order_id', $order->id)->get()->keyBy('attempt_id');
            $canRegenerateIdentity = auth()->user()?->is_active && ! auth()->user()->agent_api_enabled
                && auth()->user()->hasPermission('orders.update') && auth()->user()->hasPermission('orders.production_prompt.manage');
            $nextIdentityPrompt = $order->childIdentityPromptOverride?->prompt_text ?: app(\App\Services\Orders\OrderChildIdentityPromptService::class)->forOrder($order);
            $nextIdentityPrompt = preg_replace('/## Secure Original Child Photo References.*?<!-- HERO_KID_IDENTITY_CONTEXT_END -->/s', '<!-- HERO_KID_IDENTITY_CONTEXT_END -->', $nextIdentityPrompt);
        @endphp
        <section class="mt-4 rounded-2xl border border-violet-200 bg-white p-4" data-identity-generations="{{ $order->id }}">
            <h4 class="font-black text-violet-950">هويات الطفل — {{ $order->child_name }}</h4>
            <p class="mt-1 text-xs text-gray-600">كل المحاولات محفوظة هنا. ظهور الصورة أو إرفاقها لا يعني اعتمادها.</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach($identityAttempts as $identityAttempt)
                    @php($manualIdentity = $identityRegenerations->get($identityAttempt->id))
                    <div class="rounded-xl border border-violet-100 p-3" data-identity-attempt="{{ $identityAttempt->id }}">
                        <p class="font-bold">المحاولة {{ $identityAttempt->attempt_number }} — {{ $identityAttempt->statusLabel() }}</p>
                        <p class="mt-1 text-sm font-black {{ $order->child_identity_approved_attempt_id === $identityAttempt->id ? 'text-emerald-700' : 'text-amber-700' }}">{{ $order->child_identity_approved_attempt_id === $identityAttempt->id ? 'معتمدة' : 'غير معتمدة' }}</p>
                        @if($identityAttempt->output_storage_path)
                            <a href="{{ route('admin.orders.identity-attempts.image', [$order, $identityAttempt]) }}" target="_blank" rel="noopener">
                                <img src="{{ route('admin.orders.identity-attempts.image', [$order, $identityAttempt, 'thumbnail' => 1]) }}" alt="هوية {{ $order->child_name }} — المحاولة {{ $identityAttempt->attempt_number }}" loading="lazy" class="mt-2 h-40 w-full rounded-lg bg-gray-50 object-contain">
                                <span class="text-sm font-bold text-violet-700">فتح صورة الهوية</span>
                            </a>
                        @endif
                        @if($manualIdentity && in_array($manualIdentity->status, ['blocked', 'uncertain', 'review'], true))
                            <p class="mt-2 text-xs text-amber-800">{{ $manualIdentity->status === 'uncertain' ? 'نتيجة غير مؤكدة — راجع المحاولة قبل أي توليد جديد.' : 'تحتاج مراجعة البيانات أو الصورة؛ لم يتم اعتمادها.' }}</p>
                        @endif
                        @if($identityAttempt->completed_at)
                            <p class="mt-2 text-xs text-gray-500">{{ app_datetime($identityAttempt->completed_at, 'd/m/Y h:i A') }}</p>
                        @endif
                        @can('orders.production_prompt.manage')
                            <details class="mt-2"><summary class="cursor-pointer text-xs font-bold">برومبت هذه المحاولة</summary><pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap text-xs" dir="auto">{{ $identityAttempt->prompt_snapshot }}</pre></details>
                        @endcan
                        @if($canRegenerateIdentity && $latestIdentity->id === $identityAttempt->id && !$order->child_identity_approved_attempt_id && $identityAttempt->status === 'succeeded' && (!$manualIdentity || $manualIdentity->status === 'succeeded'))
                            <form method="POST" action="{{ route('admin.orders.identity-attempts.approve', [$order, $identityAttempt]) }}" class="mt-3">
                                @csrf
                                <button type="submit" name="confirm" value="1" class="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-bold text-white">اعتماد هذه الهوية بواسطة الفريق</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
            @if($canRegenerateIdentity && $latestIdentity)
                <details class="mt-4 rounded-xl border border-violet-200 p-3">
                    <summary class="cursor-pointer font-bold text-violet-800">إعادة توليد الهوية / تعديل البرومبت والصور</summary>
                    <p class="my-3 text-xs leading-6">استلم الطلب من الأوتوميشن أولًا. لتغيير صور الطفل، احفظها من <a href="{{ route('admin.orders.groups.edit', $order->id) }}" class="font-bold underline">تعديل الطلب</a> ثم افتح إعادة التوليد. ستُستخدم صور هذه القصة فقط (أول ٣ صور)، ولن تُحذف المحاولات السابقة.</p>
                    <form method="POST" action="{{ route('admin.orders.identity.regenerate', $order) }}">
                        @csrf
                        <input type="hidden" name="previous_attempt_id" value="{{ $latestIdentity->id }}">
                        <label class="text-sm font-bold">برومبت المحاولة الجديدة</label>
                        <textarea name="prompt" required minlength="50" maxlength="65000" rows="12" dir="auto" class="mt-2 w-full rounded-xl border-gray-300 font-mono text-sm">{{ $nextIdentityPrompt }}</textarea>
                        <p class="my-2 text-xs leading-6">التعديل لهذه المحاولة فقط. إعادة التوليد مدفوعة وتلغي اعتماد الهوية السابق. النتيجة غير معتمدة، ولا تُرسل رسالة للعميل تلقائيًا.</p>
                        <button type="submit" name="confirm" value="1" class="rounded-xl bg-violet-700 px-4 py-3 text-sm font-bold text-white">إعادة توليد الهوية</button>
                    </form>
                </details>
            @endif
            <x-input-error :messages="$errors->get('regeneration')" class="mt-2" />
            <x-input-error :messages="$errors->get('prompt')" class="mt-2" />
        </section>
    @endif
@endcan
