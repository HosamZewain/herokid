<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            تفاصيل الطلب #{{ $order->order_number }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-700 px-5 py-4 rounded-xl font-bold flex items-center gap-2">
                ✅ {{ session('success') }}
            </div>
            @endif

            <!-- Top Bar -->
            <div class="flex items-center justify-between">
                <a href="{{ route('admin.orders.index') }}" class="text-gray-600 border border-gray-200 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-bold flex items-center gap-1">
                    ← العودة للطلبات
                </a>
                @php
                    $statusColors = [
                        'new'                => 'bg-orange-100 text-orange-700 border-orange-200',
                        'under_review'       => 'bg-blue-100 text-blue-700 border-blue-200',
                        'generating'         => 'bg-purple-100 text-purple-700 border-purple-200',
                        'preview_uploaded'   => 'bg-indigo-100 text-indigo-700 border-indigo-200',
                        'approved_for_print' => 'bg-teal-100 text-teal-700 border-teal-200',
                        'printing'           => 'bg-yellow-100 text-yellow-700 border-yellow-200',
                        'shipped'            => 'bg-sky-100 text-sky-700 border-sky-200',
                        'delivered'          => 'bg-green-100 text-green-700 border-green-200',
                        'cancelled'          => 'bg-red-100 text-red-700 border-red-200',
                    ];
                    $statusColor = $statusColors[$order->status] ?? 'bg-gray-100 text-gray-700 border-gray-200';
                @endphp
                <span class="px-4 py-2 rounded-full text-sm font-extrabold border {{ $statusColor }}">
                    {{ __('order_status.' . $order->status) }}
                </span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Left Column: Details -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- Child & Story Info -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold mb-5 text-right border-b pb-3">بيانات الطفل والقصة</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-right">
                            <div class="space-y-3">
                                <div><span class="font-bold text-gray-600">اسم ولي الأمر:</span> <span class="text-gray-900">{{ $order->parent_name ?? ($order->user->name ?? 'زائر') }}</span></div>
                                <div><span class="font-bold text-gray-600">البريد الإلكتروني:</span> <span class="text-gray-900 dir-ltr text-left block">{{ $order->delivery_details['email'] ?? ($order->user->email ?? '-') }}</span></div>
                                <div><span class="font-bold text-gray-600">الهاتف / واتساب:</span>
                                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $order->delivery_details['phone'] ?? '') }}" target="_blank" class="text-green-600 font-bold hover:underline dir-ltr">{{ $order->delivery_details['phone'] ?? '-' }}</a>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div><span class="font-bold text-gray-600">اسم الطفل:</span> <span class="text-gray-900 font-bold">{{ $order->child_name }}</span></div>
                                <div><span class="font-bold text-gray-600">العمر / الجنس:</span> <span>{{ $order->child_age }} سنة / {{ $order->child_gender == 'boy' ? '👦 ولد' : '👧 بنت' }}</span></div>
                                <div><span class="font-bold text-gray-600">القصة:</span> <span class="text-indigo-600 font-bold">{{ $order->story->title ?? '-' }}</span></div>
                                <div><span class="font-bold text-gray-600">اللغة:</span> <span>{{ $order->language == 'ar' ? '🇸🇦 عربي' : '🇬🇧 English' }}</span></div>
                            </div>
                        </div>
                        @if($order->interests || $order->parent_notes || $order->gift_note || $order->lesson)
                        <div class="mt-5 pt-5 border-t space-y-2 text-sm text-right">
                            @if($order->lesson) <div><span class="font-bold text-gray-600">الدرس:</span> {{ $order->lesson }}</div> @endif
                            @if($order->interests) <div><span class="font-bold text-gray-600">الاهتمامات:</span> {{ $order->interests }}</div> @endif
                            @if($order->gift_note) <div><span class="font-bold text-gray-600">الإهداء:</span> <em class="text-gray-700">"{{ $order->gift_note }}"</em></div> @endif
                            @if($order->parent_notes) <div><span class="font-bold text-gray-600">ملاحظات ولي الأمر:</span> {{ $order->parent_notes }}</div> @endif
                        </div>
                        @endif
                    </div>

                    <!-- Delivery Info -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold mb-4 text-right border-b pb-3">عنوان التوصيل</h3>
                        <p class="text-sm text-right text-gray-700">
                            {{ $order->delivery_details['governorate'] ?? '-' }} /
                            {{ $order->delivery_details['city'] ?? '-' }}<br>
                            {{ $order->delivery_details['address'] ?? '-' }}
                        </p>
                    </div>

                    <!-- Child Photos -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold mb-4 text-right border-b pb-3">📸 صور الطفل المرفقة</h3>
                        @if($order->uploaded_photos && count($order->uploaded_photos) > 0)
                        <div class="grid grid-cols-3 md:grid-cols-5 gap-3">
                            @foreach($order->uploaded_photos as $photo)
                            <div class="relative group">
                                <a href="{{ route('admin.orders.show', $order) }}" class="block">
                                    <div class="aspect-square bg-gray-100 rounded-xl overflow-hidden">
                                        <div class="w-full h-full flex items-center justify-center text-gray-400 text-xs font-bold p-2 text-center">
                                            📷 صورة {{ $loop->iteration }}
                                        </div>
                                    </div>
                                </a>
                            </div>
                            @endforeach
                        </div>
                        <p class="text-xs text-gray-400 mt-3 text-right">{{ count($order->uploaded_photos) }} صورة مرفقة (محفوظة بشكل آمن)</p>
                        @else
                        <p class="text-gray-400 text-sm text-right">لا توجد صور مرفقة.</p>
                        @endif
                    </div>

                    <!-- Status History -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold mb-4 text-right border-b pb-3">سجل تاريخ الحالات</h3>
                        @if($order->statusLogs->count())
                        <div class="space-y-3">
                            @foreach($order->statusLogs->sortByDesc('created_at') as $log)
                            <div class="flex items-start gap-3 text-right">
                                <div class="text-xs text-gray-400 flex-shrink-0 mt-1 w-24 text-left">{{ $log->created_at->format('d/m/Y') }}</div>
                                <div class="flex-grow">
                                    <span class="text-sm font-bold text-gray-800">{{ __('order_status.' . $log->status) }}</span>
                                    @if($log->notes) <p class="text-xs text-gray-500 mt-0.5">{{ $log->notes }}</p> @endif
                                </div>
                                <div class="w-3 h-3 bg-indigo-400 rounded-full mt-1.5 flex-shrink-0"></div>
                            </div>
                            @endforeach
                        </div>
                        @else
                        <p class="text-gray-400 text-sm text-right">لا يوجد سجل حتى الآن.</p>
                        @endif
                    </div>

                    <!-- Uploaded Previews -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold mb-4 text-right border-b pb-3">🎨 التصميمات المرفوعة (Previews)</h3>
                        @if($order->previews->count())
                        <div class="space-y-3">
                            @foreach($order->previews as $preview)
                            <div class="flex items-center justify-between bg-slate-50 rounded-xl px-4 py-3 text-right">
                                <span class="text-xs text-gray-400">{{ $preview->created_at->format('d/m/Y H:i') }}</span>
                                <div>
                                    <p class="text-sm font-bold text-gray-800">تصميم #{{ $loop->iteration }}</p>
                                    @if($preview->note) <p class="text-xs text-gray-500">{{ $preview->note }}</p> @endif
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @else
                        <p class="text-gray-400 text-sm text-right">لم يتم رفع أي تصميم بعد.</p>
                        @endif
                    </div>
                </div>

                <!-- Right Column: Actions -->
                <div class="space-y-6">

                    <!-- Update Status -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-base font-bold mb-4 text-right">تحديث حالة الطلب</h3>
                        <form action="{{ route('admin.orders.update', $order) }}" method="POST" class="space-y-4">
                            @csrf
                            @method('PATCH')
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5 text-right">الحالة</label>
                                <select name="status" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-xl shadow-sm text-right">
                                    <option value="new" @selected($order->status == 'new')>📦 طلب جديد</option>
                                    <option value="under_review" @selected($order->status == 'under_review')>🔍 قيد المراجعة</option>
                                    <option value="generating" @selected($order->status == 'generating')>🤖 جاري التوليد</option>
                                    <option value="preview_uploaded" @selected($order->status == 'preview_uploaded')>👁️ تصميم تم رفعه</option>
                                    <option value="approved_for_print" @selected($order->status == 'approved_for_print')>✅ موافق للطباعة</option>
                                    <option value="printing" @selected($order->status == 'printing')>🖨️ جاري الطباعة</option>
                                    <option value="shipped" @selected($order->status == 'shipped')>🚚 تم الشحن</option>
                                    <option value="delivered" @selected($order->status == 'delivered')>🏠 تم التوصيل</option>
                                    <option value="cancelled" @selected($order->status == 'cancelled')>❌ ملغي</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5 text-right">ملاحظة داخلية (اختياري)</label>
                                <textarea name="admin_notes" rows="3" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-xl shadow-sm text-right text-sm"
                                    placeholder="ملاحظة تضاف لسجل الحالة...">{{ old('admin_notes') }}</textarea>
                            </div>
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl transition">
                                تحديث الحالة
                            </button>
                        </form>
                    </div>

                    <!-- Upload Preview -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-base font-bold mb-4 text-right">رفع تصميم للعميل (Preview)</h3>
                        <form action="{{ route('admin.orders.upload-preview', $order) }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                            @csrf
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5 text-right">ملف التصميم (PDF أو صورة)</label>
                                <input type="file" name="preview_file" accept="image/*,.pdf" required
                                    class="block w-full text-sm text-gray-500 file:ml-2 file:py-2 file:px-4 file:rounded-xl file:border-0 file:font-bold file:bg-indigo-600 file:text-white hover:file:bg-indigo-700 cursor-pointer">
                                <x-input-error :messages="$errors->get('preview_file')" class="mt-1"/>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5 text-right">ملاحظة للعميل (اختياري)</label>
                                <textarea name="preview_note" rows="2" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-xl shadow-sm text-right text-sm"
                                    placeholder="أي تعليمات للعميل..."></textarea>
                            </div>
                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition">
                                رفع التصميم وتحديث الحالة
                            </button>
                        </form>
                        <p class="text-xs text-gray-400 mt-2 text-right">سيتم تحديث حالة الطلب تلقائياً إلى "تصميم تم رفعه"</p>
                    </div>

                    <!-- WhatsApp Quick Link -->
                    @if(isset($order->delivery_details['phone']))
                    <div class="bg-green-50 rounded-2xl border border-green-100 p-5">
                        <h3 class="font-bold text-green-800 text-sm mb-3 text-right">التواصل مع العميل</h3>
                        <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $order->delivery_details['phone']) }}?text=مرحبا، بخصوص طلبك {{ $order->order_number }}"
                            target="_blank"
                            class="w-full flex items-center justify-center gap-2 bg-green-500 hover:bg-green-600 text-white font-bold py-2.5 rounded-xl transition text-sm">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            واتساب العميل
                        </a>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
