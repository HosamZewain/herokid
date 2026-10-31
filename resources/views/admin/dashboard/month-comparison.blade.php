<section aria-labelledby="month-comparison-heading" data-month-comparison data-months="{{ json_encode($monthComparison, JSON_UNESCAPED_UNICODE) }}" class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><p class="text-xs font-black text-indigo-600">مقارنة آخر 6 شهور</p><h3 id="month-comparison-heading" class="mt-1 text-xl font-black text-slate-950" dir="ltr">Month Over Month</h3></div>
        <span class="rounded-full bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">الشهر الحالي حتى الآن — غير مكتمل</span>
    </div>
    <p class="mt-3 text-sm leading-7 text-slate-500">الأوردرات والقطع ومتوسط القيمة حسب شهر الشراء الأصلي بتوقيت القاهرة، بكل الحالات مثل إحصائيات الشهر. القطع تشمل الكميات والإضافات، والمتوسط بعد الخصومات وبدون الشحن.</p>
    <p class="mt-1 text-sm leading-7 text-slate-500">خط الشحن حسب تاريخ أول شحن موثّق خلال الشهر، حتى لو الأوردر اتعمل قبل كده؛ مرة لكل عملية شراء وحالة الشحن الحالية «تم الشحن» أو «تم التسليم»، بنفس قواعد تقرير الشحن. لذلك قد يزيد عدد المشحونة عن أوردرات الشهر.</p>
    @if($monthComparisonUndated > 0)<p class="mt-3 rounded-xl bg-amber-50 p-3 text-xs leading-6 text-amber-900">{{ arabic_number($monthComparisonUndated) }} عملية شراء مشحونة أو مسلّمة بدون تاريخ شحن موثّق على مستوى كل الفترات؛ غير موزعة على الشهور ولم يتم تخمين تاريخ لها.</p>@endif
    <div data-month-legend class="mt-5 flex flex-wrap gap-2" aria-label="إظهار أو إخفاء خطوط المقارنة"></div>
    <div data-month-plot class="mt-4 w-full" style="height:320px" aria-label="المقارنة الشهرية"></div>
    <div data-month-detail class="rounded-2xl bg-slate-50 p-4 text-sm" aria-live="polite" aria-atomic="true"></div>
    <p class="mt-3 text-xs text-slate-500">الأعداد على المحور الأيسر، ومتوسط القيمة بالجنيه على المحور الأيمن. اضغط اسم مؤشر لإخفائه أو إظهاره، واختَر شهرًا لعرض أرقامه.</p>
    <details class="mt-5"><summary class="cursor-pointer text-sm font-bold text-indigo-700">عرض الأرقام في جدول</summary>
        <div class="mt-3 overflow-x-auto"><table class="w-full whitespace-nowrap text-right text-sm"><caption class="sr-only">أرقام مقارنة الشهور — نفس بيانات الشارت</caption><thead><tr class="border-b text-slate-500"><th class="p-3">الشهر</th><th class="p-3">الأوردرات</th><th class="p-3">قطع القصص</th><th class="p-3">قطع المنتجات</th><th class="p-3">متوسط الأوردر بدون شحن</th><th class="p-3">اتشحنت خلال الشهر</th></tr></thead><tbody>
        @foreach($monthComparison as $month)<tr class="border-b border-slate-100"><th scope="row" class="p-3">{{ $month['label'] }}{{ $month['partial'] ? ' (حتى الآن)' : '' }}</th><td class="p-3">{{ arabic_number($month['checkouts']) }}</td><td class="p-3">{{ arabic_number($month['story_pieces']) }}</td><td class="p-3">{{ arabic_number($month['product_pieces']) }}</td><td class="p-3">{{ $month['average_order_cents'] === null ? '—' : format_report_money($month['average_order_cents'] / 100) }}</td><td class="p-3">{{ arabic_number($month['shipped_checkouts']) }}</td></tr>@endforeach
        </tbody></table></div>
    </details>
    <noscript><p class="mt-3 text-sm">فعّل JavaScript لعرض الشارت، أو افتح جدول الأرقام.</p></noscript>
</section>
