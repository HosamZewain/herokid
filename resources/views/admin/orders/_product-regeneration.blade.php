@php
    $generationActor = auth()->user();
    $latestProductGeneration = \App\Models\PuzzleGeneration::where('order_item_id', $productPrompt['item']->id)->latest('id')->first();
@endphp
@if($latestProductGeneration && $productPrompt['component_count'] === 1 && $generationActor?->is_active && ! $generationActor->agent_api_enabled
    && $generationActor->hasPermission('orders.update') && $generationActor->hasPermission('orders.photos.view')
    && $generationActor->hasPermission('orders.production_prompt.manage'))
    <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-3 text-sm" data-product-regeneration="{{ $productPrompt['item']->id }}">
        <p>آخر محاولة لهذا المنتج: {{ $latestProductGeneration->attempt_number }} — {{ \App\Models\PuzzleGeneration::LABELS[$latestProductGeneration->status] ?? $latestProductGeneration->status }}</p>
        @if(in_array($latestProductGeneration->status, ['succeeded', 'review', 'blocked'], true) && app(\App\Services\ProductAutomation\PuzzleAutomation::class)->enabled())
            <form method="POST" action="{{ route('admin.orders.products.regenerate', [$productPrompt['item']->order_id, $productPrompt['item']->id]) }}" class="mt-2">
                @csrf
                <input type="hidden" name="previous_run_id" value="{{ $latestProductGeneration->id }}">
                <p class="mb-2 text-xs">احفظ تعديلات المنتج أولًا. سيستخدم البرومبت والصور والملاحظات الحالية في محاولة مدفوعة جديدة لهذا البند فقط، مع الاحتفاظ بالصورة السابقة.</p>
                <button type="submit" name="confirm" value="1" class="rounded-lg bg-indigo-700 px-4 py-2 font-bold text-white">إعادة التوليد</button>
            </form>
        @else
            <p class="mt-2 text-xs">إعادة التوليد غير متاحة أثناء التنفيذ أو قبل مراجعة نتيجة غير مؤكدة.</p>
        @endif
        <x-input-error :messages="$errors->get('product_generation')" class="mt-2" />
    </div>
@endif
