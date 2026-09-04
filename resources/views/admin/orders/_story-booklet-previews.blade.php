@if(($storyOrders ?? collect())->isNotEmpty())
    <section id="order-previews" class="rounded-3xl border border-violet-100 bg-white p-5 shadow-sm sm:p-6" data-order-page-section="previews">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="rounded-full bg-violet-50 px-3 py-1.5 text-xs font-black text-violet-700">{{ $storyOrders->count() }} قصة</span>
            <div class="text-right">
                <h3 class="text-lg font-black text-gray-900">معاينات القصص للعميل</h3>
                <p class="mt-1 text-xs font-bold text-gray-500">ارفع أو استبدل ملف PDF لكل قصة من نفس صفحة الطلب.</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            @foreach($storyOrders as $storyOrder)
                @php
                    $bookletPreview = $storyOrder->bookletPreview;
                    $bookletPublicUrl = $bookletPreview?->publicUrl();
                @endphp
                <article class="rounded-2xl border border-violet-100 bg-violet-50/50 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        @if($bookletPreview)
                            <span class="rounded-full px-2.5 py-1 text-[10px] font-black {{ $bookletPreview->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">{{ $bookletPreview->status === 'active' ? 'الرابط فعال' : 'الرابط موقوف' }}</span>
                        @else
                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-[10px] font-black text-gray-600">لا توجد معاينة</span>
                        @endif
                        <div class="text-right">
                            <p class="text-sm font-black text-gray-900">{{ $storyOrder->story?->title ?: 'قصة مخصصة' }}</p>
                            <p class="mt-0.5 text-[10px] font-bold text-gray-500">{{ $storyOrder->child_name ?: 'اسم الطفل غير مسجل' }}</p>
                        </div>
                    </div>

                    @if($bookletPreview && $bookletPublicUrl)
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <a href="{{ $bookletPublicUrl }}" target="_blank" rel="noopener" class="rounded-xl bg-white px-3 py-2 text-center text-xs font-black text-violet-700">فتح المعاينة</a>
                            <button type="button" data-order-preview-copy="{{ $bookletPublicUrl }}" class="rounded-xl bg-violet-600 px-3 py-2 text-xs font-black text-white">نسخ الرابط</button>
                        </div>
                    @endif

                    @can('orders.preview.upload')
                        @if(!$group['trashed'])
                            <form action="{{ route('admin.orders.booklet-preview.store', $storyOrder) }}" method="POST" enctype="multipart/form-data" class="mt-3 space-y-2 border-t border-violet-100 pt-3">
                                @csrf
                                <input type="file" name="pdf_file" accept="application/pdf,.pdf" required class="block w-full rounded-xl border border-violet-100 bg-white text-xs file:ml-2 file:border-0 file:bg-violet-600 file:px-3 file:py-2 file:font-black file:text-white">
                                <input type="text" name="note" maxlength="1000" class="block w-full rounded-xl border-violet-100 bg-white text-xs" placeholder="ملاحظة الإصدار (اختياري)">
                                <button class="w-full rounded-xl bg-emerald-600 px-3 py-2.5 text-xs font-black text-white hover:bg-emerald-700">{{ $bookletPreview ? 'رفع إصدار مصحح' : 'رفع المعاينة' }}</button>
                            </form>
                        @endif
                    @endcan
                </article>
            @endforeach
        </div>
    </section>

    @once
        @push('scripts')
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('[data-order-preview-copy]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        navigator.clipboard?.writeText(button.dataset.orderPreviewCopy);
                        button.textContent = 'تم نسخ الرابط ✓';
                    });
                });
            });
            </script>
        @endpush
    @endonce
@endif
