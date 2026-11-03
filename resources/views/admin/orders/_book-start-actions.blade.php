@isset($workspaceOrder)
    @if(auth()->user()->hasPermission('orders.photos.view') && auth()->user()->hasPermission('orders.production_prompt.manage') && app(\App\Services\Orders\OrderAccess::class)->controls($workspaceOrder, auth()->user()))
        @foreach($unstartedBooks as $book)
        <form method="POST" action="{{ route('admin.orders.workspace.book-start',[$workspaceOrder,$book]) }}" class="mt-3 rounded-xl border p-3">
            @csrf
            <p class="text-sm font-bold">{{ $book->title }} — الصفحات الداخلية عبر Codex</p>
            <input type="hidden" name="expected_updated_at" value="{{ $book->updated_at->toISOString() }}">
            <label class="my-2 block text-xs"><input type="checkbox" name="confirm" value="1" required> فحص الاعتماد والدفعة والبيانات الحالية، ثم إدخال هذا الكتاب فقط للطابور إن كان مؤهلًا.</label>
            <button class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white">فحص وبدء صفحات التلوين</button>
        </form>
        @endforeach
    @endif
@endisset
