@isset($workspaceOrder)
    @if(auth()->user()->hasPermission('orders.photos.view') && auth()->user()->hasPermission('orders.production_prompt.manage') && app(\App\Services\Orders\OrderAccess::class)->controls($workspaceOrder, auth()->user()))
        @foreach($legacyBooks ?? [] as $book)
            <section class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="font-bold">{{ $book->title }} — {{ $book->order->child_name }}</p>
                <p class="my-2 text-sm">الكتاب محفوظ بإعدادات قديمة ولم يدخل توليد الصفحات. يمكنك تجهيز المسار باستخدام الكفر الموجود.</p>
                <a class="font-bold text-indigo-700 underline" href="{{ route('admin.orders.workspace.legacy-book', [$workspaceOrder, $book]) }}">تجهيز الكتاب بالكفر الموجود</a>
            </section>
        @endforeach
        @foreach($unstartedBooks as $book)
        @php($bookAdmissionReason = app(\App\Services\CodexScenes\BookPageService::class)->admissionReason($book))
        <form method="POST" action="{{ route('admin.orders.workspace.book-start',[$workspaceOrder,$book]) }}" class="mt-3 rounded-xl border p-3">
            @csrf
            <p class="text-sm font-bold">{{ $book->title }} — الصفحات الداخلية عبر Codex</p>
            <input type="hidden" name="expected_updated_at" value="{{ $book->updated_at->toISOString() }}">
            @if($bookAdmissionReason)<p class="my-2 text-sm font-bold text-amber-800">{{ $bookAdmissionReason }}</p>@endif
            @if($bookAdmissionReason && ($book->item_snapshot['legacy_book_adopted'] ?? false))
                <a class="text-sm font-bold text-indigo-700 underline" href="{{ route('admin.orders.workspace.legacy-book', [$workspaceOrder, $book]) }}">مراجعة واعتماد الكفر الموجود</a>
            @endif
            @if(!$bookAdmissionReason)
            <label class="my-2 block text-xs"><input type="checkbox" name="confirm" value="1" required> فحص الاعتماد والدفعة والبيانات الحالية، ثم إدخال هذا الكتاب فقط للطابور إن كان مؤهلًا.</label>
            <button class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white">فحص وبدء صفحات التلوين</button>
            @endif
        </form>
        @endforeach
    @endif
@endisset
