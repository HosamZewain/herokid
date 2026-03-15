<x-front-layout>
    <div class="bg-gray-50 min-h-[70vh] py-10">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-8 gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">مرحباً، {{ auth()->user()->name }} 👋</h1>
                    <p class="text-slate-500 text-sm mt-1">هنا تجد جميع طلباتك ويمكنك متابعة حالتها.</p>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('stories.index') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold px-5 py-2.5 rounded-xl shadow-sm transition">
                        + طلب جديد
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-sm text-slate-500 hover:text-red-500 font-bold border border-slate-200 px-4 py-2.5 rounded-xl transition">
                            خروج
                        </button>
                    </form>
                </div>
            </div>

            {{-- Flash messages --}}
            @if(session('success'))
                <div class="bg-green-50 border border-green-300 text-green-800 px-5 py-4 rounded-xl mb-6 flex items-center gap-3">
                    <span class="text-xl">✅</span>
                    <span class="font-semibold">{{ session('success') }}</span>
                </div>
            @endif
            @if(session('error'))
                <div class="bg-red-50 border border-red-300 text-red-800 px-5 py-4 rounded-xl mb-6 flex items-center gap-3">
                    <span class="text-xl">❌</span>
                    <span class="font-semibold">{{ session('error') }}</span>
                </div>
            @endif

            {{-- Orders list --}}
            @if($orders->count() > 0)
                <div class="space-y-6">
                    @foreach($orders as $order)
                        @php
                            $latestPreview = $order->previews->sortByDesc('created_at')->first();
                            $statusColors = [
                                'new'               => 'bg-blue-100 text-blue-800',
                                'under_review'      => 'bg-yellow-100 text-yellow-800',
                                'generating'        => 'bg-purple-100 text-purple-800',
                                'preview_uploaded'  => 'bg-orange-100 text-orange-800',
                                'approved_for_print'=> 'bg-teal-100 text-teal-800',
                                'printing'          => 'bg-indigo-100 text-indigo-800',
                                'shipped'           => 'bg-cyan-100 text-cyan-800',
                                'delivered'         => 'bg-green-100 text-green-800',
                                'cancelled'         => 'bg-red-100 text-red-800',
                            ];
                            $colorClass = $statusColors[$order->status] ?? 'bg-gray-100 text-gray-800';
                            $steps = ['new', 'under_review', 'generating', 'preview_uploaded', 'approved_for_print', 'shipped', 'delivered'];
                            $stepLabels = ['طلب جديد', 'مراجعة', 'التوليد', 'Preview', 'موافقة', 'شحن', 'تسليم'];
                            $currentStep = array_search($order->status, $steps);
                        @endphp

                        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">

                            {{-- Order header bar --}}
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center px-6 py-4 border-b border-slate-100 bg-slate-50 gap-3">
                                <div class="flex items-center gap-4">
                                    <div>
                                        <span class="text-xs text-slate-400 block">رقم الطلب</span>
                                        <span class="font-extrabold text-slate-900 text-sm">#{{ $order->order_number }}</span>
                                    </div>
                                    <div class="hidden sm:block w-px h-8 bg-slate-200"></div>
                                    <div>
                                        <span class="text-xs text-slate-400 block">تاريخ الطلب</span>
                                        <span class="text-sm text-slate-700">{{ $order->created_at->format('d/m/Y') }}</span>
                                    </div>
                                </div>
                                <span class="px-3 py-1.5 rounded-full text-xs font-bold {{ $colorClass }}">
                                    {{ __('order_status.' . $order->status) }}
                                </span>
                            </div>

                            {{-- Order details grid --}}
                            <div class="px-6 py-5">
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-5">
                                    <div>
                                        <span class="text-xs text-slate-400 block mb-1">القصة</span>
                                        <span class="text-sm font-bold text-slate-800">{{ $order->story->title ?? 'غير متوفرة' }}</span>
                                    </div>
                                    <div>
                                        <span class="text-xs text-slate-400 block mb-1">للطفل</span>
                                        <span class="text-sm font-bold text-slate-800">{{ $order->child_name }}</span>
                                    </div>
                                    <div>
                                        <span class="text-xs text-slate-400 block mb-1">العمر</span>
                                        <span class="text-sm text-slate-700">{{ $order->child_age }} سنة</span>
                                    </div>
                                    <div>
                                        <span class="text-xs text-slate-400 block mb-1">السعر</span>
                                        <span class="text-sm font-bold text-indigo-600">{{ $order->story->price ?? '—' }} ج.م</span>
                                    </div>
                                </div>

                                {{-- Progress stepper --}}
                                @if($order->status !== 'cancelled')
                                <div class="mb-5 overflow-x-auto">
                                    <div class="flex items-center justify-between relative min-w-[360px]">
                                        <div class="absolute top-3 right-0 left-0 h-0.5 bg-slate-200 z-0 mx-4"></div>
                                        @foreach($steps as $i => $step)
                                            @php
                                                $isDone   = $currentStep !== false && $i <= $currentStep;
                                                $isActive = $currentStep !== false && $i === $currentStep;
                                                $dot = $isDone
                                                    ? 'bg-indigo-600 border-indigo-600 text-white'
                                                    : 'bg-white border-slate-300 text-slate-400';
                                            @endphp
                                            <div class="flex flex-col items-center z-10 flex-1">
                                                <div class="w-6 h-6 rounded-full border-2 flex items-center justify-center text-xs font-bold {{ $dot }} {{ $isActive ? 'ring-2 ring-indigo-300 ring-offset-1' : '' }}">
                                                    {{ $isDone ? '✓' : ($i + 1) }}
                                                </div>
                                                <span class="text-center text-slate-500 mt-1 hidden sm:block" style="font-size:10px">{{ $stepLabels[$i] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                @else
                                <div class="mb-5">
                                    <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-red-700 text-sm font-semibold">
                                        ❌ تم إلغاء هذا الطلب.
                                    </div>
                                </div>
                                @endif

                                {{-- ⭐ PREVIEW APPROVAL SECTION --}}
                                @if($order->status === 'preview_uploaded' && $latestPreview)
                                <div class="bg-orange-50 border border-orange-300 rounded-xl p-5 mb-4">
                                    <div class="flex items-start gap-3 mb-4">
                                        <span class="text-2xl">🎨</span>
                                        <div>
                                            <h3 class="font-extrabold text-orange-900 text-base">تصميم قصتك جاهز! راجعه وأعطنا موافقتك 🎉</h3>
                                            <p class="text-orange-700 text-sm mt-1">هذا هو التصميم الأولي لقصة <strong>{{ $order->child_name }}</strong>. راجع كل الصفحات بعناية. بعد موافقتك، نرسل للطباعة مباشرة ولا يمكن التراجع.</p>
                                        </div>
                                    </div>

                                    {{-- Preview file card --}}
                                    <div class="bg-white rounded-lg border border-orange-200 p-4 mb-4">
                                        <div class="flex items-center justify-between flex-wrap gap-3">
                                            <div class="flex items-center gap-3">
                                                <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center text-xl">
                                                    {{ str_ends_with($latestPreview->file_path, '.pdf') ? '📄' : '🖼️' }}
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-800 text-sm">ملف التصميم الأولي</p>
                                                    @if($latestPreview->note)
                                                        <p class="text-xs text-slate-500 mt-0.5">{{ $latestPreview->note }}</p>
                                                    @endif
                                                    <p class="text-xs text-slate-400 mt-0.5">رُفع في {{ $latestPreview->created_at->format('d/m/Y') }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Action buttons --}}
                                    <div class="flex flex-col sm:flex-row gap-3">
                                        <form action="{{ route('orders.approve-preview', $order) }}" method="POST" class="flex-1">
                                            @csrf
                                            <button type="submit"
                                                    onclick="return confirm('هل تأكدت من مراجعة جميع صفحات التصميم وتوافق على الطباعة؟ لا يمكن التراجع بعد الموافقة.')"
                                                    class="w-full bg-green-600 hover:bg-green-700 text-white font-extrabold py-3 px-6 rounded-xl text-sm shadow-sm transition">
                                                ✅ أوافق على التصميم — ابدأ الطباعة
                                            </button>
                                        </form>
                                        <a href="https://wa.me/201000000000?text={{ urlencode('مرحباً، لدي طلب تعديل على تصميم القصة. رقم الطلب: #' . $order->order_number) }}"
                                           target="_blank"
                                           class="flex-1 text-center bg-white hover:bg-green-50 text-green-700 font-bold py-3 px-6 rounded-xl text-sm border border-green-200 transition">
                                            💬 طلب تعديل عبر واتساب
                                        </a>
                                    </div>
                                </div>
                                @endif

                                {{-- Shipped notification --}}
                                @if($order->status === 'shipped')
                                <div class="bg-cyan-50 border border-cyan-200 rounded-xl p-4 mb-4 flex items-center gap-3">
                                    <span class="text-2xl">🚚</span>
                                    <div>
                                        <p class="font-bold text-cyan-900 text-sm">طلبك في الطريق إليك!</p>
                                        <p class="text-cyan-700 text-xs mt-0.5">يصلك خلال ١-٣ أيام عمل. تواصل معنا على واتساب لأي استفسار.</p>
                                    </div>
                                </div>
                                @endif

                                {{-- Delivered celebration --}}
                                @if($order->status === 'delivered')
                                <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-4 flex items-center gap-3">
                                    <span class="text-2xl">🎉</span>
                                    <div>
                                        <p class="font-bold text-green-900 text-sm">تم التوصيل! نتمنى أن تعجب {{ $order->child_name }} قصته!</p>
                                        <p class="text-green-700 text-xs mt-0.5">شاركنا صورته مع الكتاب على واتساب أو انستغرام ✨</p>
                                    </div>
                                </div>
                                @endif

                                {{-- Collapsible status log --}}
                                @if($order->statusLogs->count() > 0)
                                <div x-data="{ open: false }" class="mt-2">
                                    <button @click="open = !open"
                                            class="text-xs text-indigo-600 hover:text-indigo-800 font-bold flex items-center gap-1">
                                        <span x-text="open ? '▲ إخفاء سجل التحديثات' : '▼ عرض سجل التحديثات'">▼ عرض سجل التحديثات</span>
                                    </button>
                                    <div x-show="open" x-transition class="mt-3 space-y-2 border-r-2 border-indigo-100 pr-3" style="display:none">
                                        @foreach($order->statusLogs->sortByDesc('created_at') as $log)
                                            <div class="flex items-start gap-2 text-xs text-slate-600">
                                                <span class="text-slate-400 whitespace-nowrap">{{ $log->created_at->format('d/m H:i') }}</span>
                                                <span class="text-indigo-400 flex-shrink-0">•</span>
                                                <span>{{ __('order_status.' . $log->status) }}{{ $log->notes ? ' — ' . $log->notes : '' }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                @endif
                            </div>

                            {{-- Order footer --}}
                            <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex justify-between items-center text-xs">
                                <a href="https://wa.me/201000000000?text={{ urlencode('مرحباً، أريد الاستفسار عن طلبي رقم: #' . $order->order_number) }}"
                                   target="_blank"
                                   class="text-green-600 hover:text-green-800 font-bold">
                                    💬 تواصل معنا بشأن هذا الطلب
                                </a>
                                <span class="text-slate-400">
                                    آخر تحديث: {{ $order->updated_at->diffForHumans() }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>

            @else
                {{-- Empty state --}}
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 text-center py-20 px-8">
                    <div class="text-6xl mb-6">📚</div>
                    <h2 class="text-xl font-extrabold text-slate-800 mb-3">ليس لديك طلبات بعد</h2>
                    <p class="text-slate-500 mb-8 max-w-sm mx-auto">اختر قصة من مكتبتنا وحوّل طفلك إلى البطل الرئيسي في مغامرة لا تُنسى.</p>
                    <a href="{{ route('stories.index') }}" class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold px-8 py-3 rounded-xl shadow-sm transition">
                        تصفح القصص الآن
                    </a>
                </div>
            @endif

        </div>
    </div>
</x-front-layout>
