    @foreach($codexRuns as $codexRun)
        <div class="mt-4 rounded-xl border bg-white p-3">
            <p class="text-sm font-black">{{ \App\Models\CodexSceneRun::LABELS[$codexRun->status] ?? $codexRun->status }} · {{ count($codexRun->scenes ?? []) }} / 13</p>
            <p class="mt-1 text-xs text-gray-500">{{ app_datetime($codexRun->created_at,'d/m/Y h:i A') }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $codexRun->source === 'automatic' ? 'بدأ بواسطة Automation' : 'بدأ بطلب موظف' }}</p>
            <div class="mt-2 text-xs text-gray-600">@include('admin.orders._codex-scene-timing', ['timingRun' => $codexRun])</div>
            @if($codexRun->error_code)<p class="mt-2 text-sm text-amber-800">{{ \App\Models\CodexSceneRun::ERRORS[$codexRun->error_code] ?? 'تحتاج المحاولة مراجعة من الفريق.' }}</p>@endif
            @include('admin.orders._codex-scene-attempts', ['attemptRun' => $codexRun])
            @can('orders.production_prompt.manage')
            @if($codexRun->active_slot)
                <form method="POST" action="{{ route('admin.orders.codex-scenes.stop',[$order,$codexRun]) }}" class="mt-2">@csrf<button class="rounded-lg border border-red-200 px-3 py-2 text-xs font-bold text-red-700">إيقاف التوليد والاحتفاظ بالمشاهد</button></form>
            @endif
            @endcan
            @if(count($codexRun->scenes ?? []))
                <a href="{{ route('admin.orders.codex-scenes.download',[$order,$codexRun]) }}" class="mt-3 inline-block rounded-lg border px-3 py-2 text-xs font-bold text-indigo-900">تحميل الكل ({{ count($codexRun->scenes) }} / 13) — ZIP</a>
            @endif
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($codexRun->scenes ?? [] as $sceneNumber=>$sceneData)
                    <a href="{{ route('admin.orders.codex-scenes.image',[$order,$codexRun,$sceneNumber]) }}" target="_blank" rel="noopener" class="rounded-lg border p-2">
                        <img src="{{ route('admin.orders.codex-scenes.image',[$order,$codexRun,$sceneNumber]) }}" alt="مشهد {{ $sceneNumber }} — {{ $order->child_name }}" class="w-full rounded-lg" loading="lazy">
                        <span class="text-xs font-bold">مشهد {{ $sceneNumber }} · {{ $sceneData['width'] }} × {{ $sceneData['height'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
