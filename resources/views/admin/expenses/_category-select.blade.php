@if($transaction->status === 'posted' && auth()->user()->hasPermission('expenses.edit'))
    <form method="POST" action="{{ route('admin.expenses.category.update', $transaction) }}" data-expense-category-form data-transaction-id="{{ $transaction->id }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="expected_category_id" value="{{ $transaction->category_id }}">
        <select name="category_id" aria-label="تصنيف العملية {{ $transaction->id }}" data-expense-category data-original-value="{{ $transaction->category_id }}" class="w-full min-w-40 rounded-xl border-gray-200 text-sm font-bold">
            @foreach($categories->where('type', $transaction->type) as $category)
                @if($category->is_active || $category->id === $transaction->category_id)
                    <option value="{{ $category->id }}" @selected($category->id === $transaction->category_id)>{{ $category->name }}{{ $category->is_active ? '' : ' (غير فعال)' }}</option>
                @endif
            @endforeach
        </select>
        <noscript><button class="mt-1 text-xs font-black text-indigo-600">حفظ التصنيف</button></noscript>
    </form>
@else
    {{ $transaction->category?->name }}
@endif
