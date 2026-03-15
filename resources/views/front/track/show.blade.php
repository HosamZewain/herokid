<x-front-layout>
    <div class="bg-gray-50 py-12 min-h-[70vh]">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <div class="mb-6">
                <a href="{{ route('track.index') }}" class="text-indigo-600 hover:text-indigo-800 flex items-center gap-2 font-medium text-sm">
                    <svg class="w-4 h-4 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    تتبع طلب آخر
                </a>
            </div>

            <div class="bg-white rounded-3xl shadow-lg border border-gray-100 overflow-hidden">
                <div class="bg-indigo-600 text-white p-6 md:p-8 flex flex-col md:flex-row justify-between items-start md:items-center">
                    <div>
                        <h1 class="text-2xl font-bold mb-2">طلب رقم {{ $order->order_number }}</h1>
                        <p class="text-indigo-200">الطفل البطل: {{ $order->child_name }} | القصة: {{ $order->story->title }}</p>
                    </div>
                    <div class="mt-4 md:mt-0 bg-white/20 px-4 py-2 rounded-full font-bold">
                        تاريخ الطلب: {{ $order->created_at->format('Y/m/d') }}
                    </div>
                </div>

                <div class="p-6 md:p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-6">تحديثات حالة الطلب</h2>
                    
                    <div class="space-y-8 relative before:absolute before:inset-0 before:ml-5 before:-translate-x-px md:before:mx-auto md:before:translate-x-0 before:h-full before:w-0.5 before:bg-gradient-to-b before:from-transparent before:via-gray-300 before:to-transparent">
                        @foreach($order->statusLogs()->latest()->get() as $log)
                            <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group is-active">
                                <div class="flex items-center justify-center w-10 h-10 rounded-full border border-white bg-white text-indigo-500 shadow shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2 z-10 font-bold">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                </div>
                                
                                <div class="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] bg-gray-50 p-4 rounded-xl border border-gray-100 shadow-sm">
                                    <div class="flex items-center justify-between space-x-2 rtl:space-x-reverse mb-1">
                                        <div class="font-bold text-gray-900">{{ __('order_status.' . $log->status) }}</div>
                                        <time class="text-xs font-medium text-gray-500">{{ $log->created_at->diffForHumans() }}</time>
                                    </div>
                                    <div class="text-gray-600 text-sm">
                                        {{ $log->notes ?? 'تم تحديث حالة الطلب' }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                </div>
            </div>

        </div>
    </div>
</x-front-layout>
