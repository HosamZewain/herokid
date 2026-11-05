    @foreach($codexRuns as $codexRun)
        @if(isset($codexRun->book_pdf['repair_parent'])) @continue @endif
        <div class="mt-4 rounded-xl border bg-white p-3">
            <p class="text-xs font-bold">{{ $codexRun->production_kind === 'coloring_book' ? 'صفحات كتاب تلوين — منتج #'.$codexRun->order_item_id : 'مشاهد القصة' }}</p><p class="text-sm font-black">{{ \App\Models\CodexSceneRun::LABELS[$codexRun->status] ?? $codexRun->status }} · {{ count($codexRun->scenes ?? []) }} / {{ $codexRun->scene_count ?? 13 }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ app_datetime($codexRun->created_at,'d/m/Y h:i A') }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $codexRun->source === 'automatic' ? 'بدأ بواسطة Automation' : 'بدأ بطلب موظف' }}</p>
            <div class="mt-2 text-xs text-gray-600">@include('admin.orders._codex-scene-timing', ['timingRun' => $codexRun])</div>
            @if($codexRun->error_code && $codexRun->status !== 'completed')<p class="mt-2 text-sm text-amber-800">{{ \App\Models\CodexSceneRun::ERRORS[$codexRun->error_code] ?? 'تحتاج المحاولة مراجعة من الفريق.' }}</p>@endif
            @include('admin.orders._codex-scene-attempts', ['attemptRun' => $codexRun])
            @can('orders.production_prompt.manage')
            @if($codexRun->active_slot)
                <a href="#order-automation-controls" class="mt-2 inline-block rounded-lg border border-amber-300 px-3 py-2 text-xs font-bold text-amber-800">التحكم والإيقاف الآمن بعد الصفحة الجارية</a>
            @endif
            @endcan
            @if(count($codexRun->scenes ?? []) && $codexRun->production_kind !== 'coloring_book')
                <a href="{{ route('admin.orders.codex-scenes.download',[$order,$codexRun]) }}" class="mt-3 inline-block rounded-lg border px-3 py-2 text-xs font-bold text-indigo-900">تحميل الكل ({{ count($codexRun->scenes) }} / {{ $codexRun->scene_count ?? 13 }}) — ZIP</a>
            @endif
            @if(($codexRun->book_pdf['status'] ?? '') === 'ready' && ($codexRun->book_pdf['complete'] ?? false))
                <a href="{{ route('admin.orders.attachments.download', $codexRun->book_pdf['attachment_id']) }}" class="mt-3 inline-block rounded-lg border px-3 py-2 text-xs font-bold text-indigo-900">تحميل كتاب التلوين PDF {{ $codexRun->book_pdf['complete'] ? '— جميع الصفحات' : '— غير مكتمل، للمراجعة فقط' }}</a>
            @elseif(($codexRun->book_pdf['status'] ?? '') === 'failed')
                <p class="mt-2 text-sm font-bold text-red-700">تعذر تجميع PDF؛ الصور محفوظة. محاولات التجميع: {{ $codexRun->book_pdf['attempts'] }} / 3. لا تعِد توليد الصفحات.</p>
            @elseif($codexRun->production_kind === 'coloring_book')
                @php($bookRecovery = $codexRun->book_pdf['recovery'] ?? [])
                @if(($bookRecovery['status'] ?? '') === 'completed')
                    <p class="mt-2 font-bold text-green-800">تم استكمال هذا التشغيل؛ حمّل ملف PDF الكامل من أحدث تشغيل أو مرفقات الطلب.</p>
                @else
                    <p class="mt-2 text-sm font-bold text-amber-800">ملف الطباعة لم يجهز بعد. الصفحات المحفوظة {{ count($codexRun->scenes ?? []) }} من {{ $codexRun->scene_count }}. لن تُسلّم نسخة ناقصة للطباعة.</p>
                    @if(in_array($bookRecovery['status'] ?? '', ['queued', 'working']))<p class="mt-2 text-sm">الأوتوميشن يستكمل الصفحات الناقصة فقط؛ الصفحات المحفوظة لن تُعاد.</p>@endif
                    @if(($bookRecovery['status'] ?? '') === 'blocked')<p class="mt-2 text-sm text-red-700">{{ $bookRecovery['reason'] }}</p>@endif
                    @can('orders.automation.control')
                    @if($codexRun->status === 'needs_manual' && (!$bookRecovery || ($bookRecovery['status'] ?? '') === 'queued'))
                        <form method="POST" action="{{ route('admin.orders.codex-scenes.recover-book', [$order, $codexRun]) }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="expected_updated_at" value="{{ $codexRun->updated_at->toISOString() }}">
                            <button name="confirm" value="1" class="rounded-lg border px-3 py-2 text-sm font-bold text-indigo-900">استكمال الصفحات الناقصة فقط وتجهيز PDF كامل</button>
                        </form>
                    @endif
                    @endcan
                @endif
            @endif
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($codexRun->scenes ?? [] as $sceneNumber=>$sceneData)
                    <a href="{{ route('admin.orders.codex-scenes.image',[$order,$codexRun,$sceneNumber]) }}" target="_blank" rel="noopener" class="rounded-lg border p-2">
                        <img src="{{ route('admin.orders.codex-scenes.image',[$order,$codexRun,$sceneNumber, 'thumbnail' => 1]) }}" alt="مشهد {{ $sceneNumber }} — {{ $order->child_name }}" class="w-full rounded-lg" loading="lazy">
                        <span class="text-xs font-bold">مشهد {{ $sceneNumber }} · {{ $sceneData['width'] }} × {{ $sceneData['height'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
