@php
    $generationActor = auth()->user();
    $latestProductGeneration = \App\Models\PuzzleGeneration::where('order_item_id', $productPrompt['item']->id)->latest('id')->first();
@endphp
@if($latestProductGeneration && ($productPrompt['component_count'] === 1 || ($productPrompt['production_stage'] ?? '') === 'book_cover') && $generationActor?->is_active && ! $generationActor->agent_api_enabled
    && $generationActor->hasPermission('orders.update') && $generationActor->hasPermission('orders.photos.view')
    && $generationActor->hasPermission('orders.production_prompt.manage'))
    <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-3 text-sm" data-product-regeneration="{{ $productPrompt['item']->id }}">
        <p>آخر محاولة لهذا المنتج: {{ $latestProductGeneration->attempt_number }} — {{ \App\Models\PuzzleGeneration::LABELS[$latestProductGeneration->status] ?? $latestProductGeneration->status }}</p>
        @if($latestProductGeneration->photoWarning())<p class="mt-2 font-bold">{{ $latestProductGeneration->photoWarning() }}</p>@endif
        @if($latestProductGeneration->error_code)
            <p class="mt-2">{{ $latestProductGeneration->failureDescription(\App\Services\Orders\AutomationControl::blocked($latestProductGeneration->checkout_key) || \App\Services\Orders\AutomationHandoff::held($latestProductGeneration->checkout_key)) }}</p>
        @endif
        @if((in_array($latestProductGeneration->status, ['succeeded', 'review', 'blocked', 'manual_artwork'], true) || $latestProductGeneration->canAuthorizeUncertainRetry()) && app(\App\Services\ProductAutomation\PuzzleAutomation::class)->enabled())
            <form method="POST" action="{{ route('admin.orders.products.regenerate', [$productPrompt['item']->order_id, $productPrompt['item']->id]) }}" class="mt-2">
                @csrf
                <input type="hidden" name="previous_run_id" value="{{ $latestProductGeneration->id }}">
                <p class="mb-2 text-xs">استلم الطلب من الأوتوميشن واحفظ تعديلات المنتج أولًا. سيستخدم البرومبت والصور والملاحظات الحالية في محاولة مدفوعة جديدة لهذا البند فقط، مع الاحتفاظ بالصورة السابقة.</p>
                @php($regenerationPrompt = \App\Support\ProductProductionPrompt::forItem($productPrompt['item'], privateImageInputs: true)->firstWhere('unit_key', $productPrompt['unit_key']))
                <p class="mb-2 text-xs">لتغيير الصور، احفظ الصور الجديدة لهذا الطفل من <a href="{{ route('admin.orders.groups.edit', $productPrompt['item']->order_id) }}" class="font-bold underline">تعديل الطلب</a> أولًا. البرومبت التالي يخص هذه المحاولة فقط ولا يغيّر برومبت المنتج في المتجر.</p>
                <details class="my-3"><summary class="cursor-pointer font-bold">تعديل برومبت إعادة التوليد</summary>
                    <textarea name="prompt" rows="10" minlength="20" maxlength="65000" dir="auto" class="mt-2 w-full rounded-xl border-gray-300 font-mono text-sm">{{ $regenerationPrompt['prompt'] ?? '' }}</textarea>
                </details>
                @if($latestProductGeneration->canAuthorizeUncertainRetry())
                    <label class="my-3 block font-bold"><input type="checkbox" name="authorize_uncertain_retry" value="1" required>
                        أوافق على محاولة جديدة لهذا المنتج؛ نتيجة وتكلفة المحاولة السابقة غير محسومتين وقد تُحسب تكلفة إضافية.
                    </label>
                @endif
                <button type="submit" name="confirm" value="1" class="rounded-lg bg-indigo-700 px-4 py-2 font-bold text-white">{{ ($productPrompt['production_stage'] ?? '') === 'book_cover' ? 'إعادة توليد الكفر' : 'إعادة التوليد' }}</button>
                <p class="mt-2 text-xs">بعد حفظ الصورة الجديدة، استخدم «استئناف الأوتوميشن» لاستكمال المعاينة. هذا الزر يولّد صورة المنتج فقط.</p>
            </form>
        @else
            <p class="mt-2 text-xs">إعادة التوليد غير متاحة أثناء التنفيذ أو قبل مراجعة نتيجة غير مؤكدة.</p>
        @endif
        <x-input-error :messages="$errors->get('regeneration')" class="mt-2" />
        <x-input-error :messages="$errors->get('prompt')" class="mt-2" />
        <x-input-error :messages="$errors->get('product_generation')" class="mt-2" />
    </div>
@endif
