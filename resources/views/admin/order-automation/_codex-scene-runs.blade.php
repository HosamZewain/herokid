<p class="mb-3 font-bold {{ $sceneHealth ? 'text-emerald-700' : 'text-amber-800' }}">{{ $sceneHealth ? 'عامل التوليد متصل' : 'عامل التوليد غير جاهز — راجع الاتصال بالاشتراك' }}</p>
@if(($sceneCounts['uncertain'] ?? 0) > 0)<p class="mb-3 rounded-lg bg-amber-50 p-3 text-amber-800">يوجد توليد يحتاج مراجعة؛ إرسال القصص التالية متوقف مؤقتًا. راجع النتيجة قبل إيقاف المحاولة من الأوردر.</p>@endif
<div class="mb-4 flex flex-wrap gap-3">@foreach(\App\Models\CodexSceneRun::LABELS as $key=>$label)<a href="{{ route('admin.order-automation.codex-scenes',['status'=>$key]) }}" class="rounded-lg border bg-white px-3 py-2 text-sm">{{ $label }}: <strong>{{ $sceneCounts[$key] ?? 0 }}</strong></a>@endforeach</div>
<div class="mb-4 rounded-xl border bg-white p-3 text-sm">
    <p class="font-bold">متوسط مدة توليد القصة المكتملة: <bdi>{{ $sceneTiming['average'] }}</bdi> · {{ $sceneTiming['count'] }} قصة بقياس عامل التوليد</p>
    <p class="mt-1 text-xs text-gray-500">ساعات:دقائق:ثوانٍ · التوليد والتحقق المحلي للمشاهد الـ13، دون انتظار الطابور أو مزامنة S3. المتوسط لكل القصص المكتملة، بصرف النظر عن فلتر الحالة. المحاولات المتوقفة لا تدخل المتوسط.</p>
    @if($sceneTiming['estimated_count'])<p class="mt-1 text-xs text-gray-500">المحاولات القديمة: متوسط تقريبي <bdi>{{ $sceneTiming['estimated_average'] }}</bdi> لـ{{ $sceneTiming['estimated_count'] }} قصة، حسب وقت رصد السيستم؛ لا تدخل المتوسط الدقيق.</p>@endif
</div>
<div class="overflow-x-auto rounded-xl border bg-white"><table class="w-full text-right text-sm"><thead><tr><th class="p-3">الأوردر / القصة / الطفل</th><th class="p-3">الحالة والمشاهد</th><th class="p-3">الوقت</th><th class="p-3">المتابعة</th></tr></thead><tbody>
@forelse($sceneRuns as $run)
<tr class="border-t"><td class="p-3"><p class="font-bold">{{ $run->order?->checkoutReference?->short_reference ?? $run->order?->order_number ?? '#'.$run->order_id }}</p><p>{{ $run->order?->story?->title }} — {{ $run->order?->child_name }}</p><p class="text-xs text-gray-500">قصة #{{ $run->order_id }} · عملية {{ substr($run->id,0,8) }} · {{ $run->source === 'automatic' ? 'Automation' : 'طلب موظف' }}</p></td>
<td class="p-3"><p>{{ $run->production_kind === 'coloring_book' ? 'كتاب تلوين — منتج #'.$run->order_item_id : 'قصة' }}</p>{{ \App\Models\CodexSceneRun::LABELS[$run->status] ?? $run->status }}<p class="mt-2 font-bold">{{ count($run->scenes ?? []) }} / {{ $run->scene_count ?? 13 }}</p>@if($run->error_code)<p class="text-xs text-red-700">{{ \App\Models\CodexSceneRun::ERRORS[$run->error_code] ?? 'تحتاج المحاولة مراجعة من الفريق.' }}</p>@endif @include('admin.orders._codex-scene-attempts', ['attemptRun' => $run])</td>
<td class="p-3">@include('admin.orders._codex-scene-timing', ['timingRun' => $run])<p class="text-xs text-gray-500">آخر تحديث: {{ app_datetime($run->updated_at,'h:i:s A') }}</p></td>
<td class="p-3"><a href="{{ route('admin.orders.show',$run->order_id) }}#codex-scenes-{{ $run->order_id }}" class="font-bold text-indigo-700 underline">فتح القصة والمشاهد</a>@can('orders.photos.view')@if(count($run->scenes ?? []))<p class="mt-2"><a href="{{ route('admin.orders.codex-scenes.download',[$run->order_id,$run]) }}" class="underline">تحميل المشاهد ZIP</a></p>@endif
@endcan</td></tr>
@empty<tr><td colspan="4" class="p-6">لا توجد قصص في هذه الحالة.</td></tr>@endforelse
</tbody></table></div>
<div class="mt-4">{{ $sceneRuns->links() }}</div>
