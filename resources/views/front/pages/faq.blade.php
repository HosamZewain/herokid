<x-front-layout>

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">الأسئلة الشائعة عن قصص HeroKid المخصصة</x-slot>
<x-slot name="pageDescription">إجابات شاملة عن قصص HeroKid المخصصة: كيف يتم تصميم القصة بوجه طفلك، مدة التوصيل، أسعار الكتب، سياسة الاسترجاع، وكل ما تحتاج معرفته.</x-slot>

@if($faqs->count())
@push('schema')
<script type="application/ld+json">
{
  "@@context": "https://schema.org",
  "@@type": "FAQPage",
  "mainEntity": [
    @foreach($faqs as $faq)
    {
      "@@type": "Question",
      "name": "{{ addslashes($faq->question) }}",
      "acceptedAnswer": {
        "@@type": "Answer",
        "text": "{{ addslashes($faq->answer) }}"
      }
    }{{ !$loop->last ? ',' : '' }}
    @endforeach
  ]
}
</script>
@endpush
@endif

    <div class="bg-indigo-600 py-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-white">
            <h1 class="text-4xl font-extrabold mb-4">الأسئلة الشائعة</h1>
            <p class="text-xl text-indigo-100">كل ما تحتاج معرفته عن قصص HeroKid المخصصة.</p>
        </div>
    </div>

    <div class="bg-gray-50 py-12 min-h-[50vh]">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            
            @if($faqs->count() > 0)
                <div class="space-y-4">
                    @foreach($faqs as $faq)
                        <div class="bg-white border rounded-xl overflow-hidden shadow-sm" x-data="{ open: false }">
                            <button @click="open = !open" class="w-full text-right px-6 py-4 font-bold text-gray-900 flex justify-between items-center hover:bg-gray-50 focus:outline-none">
                                <span>{{ $faq->question }}</span>
                                <svg class="w-5 h-5 text-indigo-500 transform transition-transform duration-200" :class="{'rotate-180': open}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            <div x-show="open" class="px-6 pb-4 pt-2 text-gray-600 border-t border-gray-100 bg-gray-50" style="display: none;">
                                {{ $faq->answer }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-12 bg-white rounded-xl shadow-sm border border-gray-100">
                    <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-gray-500">لا توجد أسئلة مضافة حالياً.</p>
                </div>
            @endif

            <div class="mt-12 text-center">
                <p class="text-gray-600 mb-4">لم تجد إجابة لسؤالك؟</p>
                <a href="{{ route('contact') }}" class="inline-block bg-white border border-indigo-600 text-indigo-600 hover:bg-indigo-50 font-bold py-2 px-6 rounded-full transition">تواصل معنا</a>
            </div>

        </div>
    </div>
</x-front-layout>
