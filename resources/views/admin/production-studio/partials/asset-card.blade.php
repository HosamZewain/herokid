@php
    $assetTone = match ($asset->status) {
        'approved' => 'emerald',
        'rejected', 'failed' => 'red',
        'under_review', 'processing', 'queued' => 'indigo',
        default => 'gray',
    };
    $deletableGeneratedImage = in_array($asset->asset_type, ['character_sheet', 'scene_image', 'cover_image'], true)
        && is_string($asset->file_path)
        && str_starts_with($asset->file_path, 'production-studio/projects/'.$project->id.'/generated/');
    $identityCorrectionModels = collect($sceneGenerationModels ?? [])
        ->filter(fn ($model) => $model->provider?->driver === 'openai' && $model->supportsCapability('image_editing'));
    $identityReview = data_get($asset->metadata_json, 'identity_review');
    $identityDecision = data_get($identityReview, 'result.decision');
    $identityReviewStatus = data_get($identityReview, 'status');
    $identityBlocksApproval = in_array($identityReviewStatus, ['queued', 'processing'], true) || $identityDecision === 'fail';
    $sceneLabel = $asset->asset_type === 'scene_image' && $asset->scene
        ? 'المشهد '.$asset->scene->scene_number.' — '.($asset->scene->title ?: 'بدون عنوان')
        : null;
@endphp

<div class="rounded-xl border bg-white p-3 text-right shadow-sm {{ $asset->is_primary ? 'border-emerald-300 ring-2 ring-emerald-100' : 'border-gray-100' }}" data-studio-asset-card="{{ $asset->id }}">
    @if($asset->file_path)
        <a href="{{ route('admin.production-studio.assets.show', [$project, $asset]) }}" target="_blank" class="block overflow-hidden rounded-lg bg-gray-100">
            <img src="{{ route('admin.production-studio.assets.show', [$project, $asset]) }}" alt="{{ $asset->label }}" class="aspect-square w-full object-cover">
        </a>
    @endif
    <div class="mt-3 space-y-1">
        @if($sceneLabel)
            <p class="font-black text-gray-900">{{ $sceneLabel }}</p>
            <p class="text-xs font-bold text-gray-500">صورة المشهد — الإصدار v{{ $asset->version_number }}</p>
        @else
            <p class="font-black text-gray-900">{{ $asset->label ?? $asset->asset_type }}</p>
        @endif
        <div class="flex flex-wrap justify-end gap-2">
            @include('admin.production-studio.partials.status-badge', ['label' => 'v'.$asset->version_number, 'tone' => 'gray'])
            @include('admin.production-studio.partials.status-badge', ['label' => $asset->status, 'tone' => $assetTone])
        </div>
        @if($asset->generationJob)
            <p class="text-xs text-gray-500">
                {{ $asset->generationJob->provider?->public_name ?? 'AI' }} /
                {{ $asset->generationJob->model?->display_name ?? $asset->generationJob->generation_mode }}
            </p>
            <p class="text-xs text-gray-500">Mode: {{ $asset->generationJob->generation_mode }}</p>
        @endif
        @if($asset->asset_type === 'scene_image')
            @if(is_array($identityReview))
                <div class="mt-2 rounded-lg border p-2 text-xs font-bold {{ $identityDecision === 'pass' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : ($identityDecision === 'fail' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800') }}">
                    <p>فحص اتساق الهوية: {{ $identityDecision ?: $identityReviewStatus }}</p>
                    @if(data_get($identityReview, 'result.confidence') !== null)
                        <p>الثقة: {{ data_get($identityReview, 'result.confidence') }}%</p>
                    @endif
                    @if(data_get($identityReview, 'result.recommendation'))
                        <p class="mt-1">{{ data_get($identityReview, 'result.recommendation') }}</p>
                    @endif
                </div>
            @endif
            @can('production_studio.ai_retry')
                <form method="POST" action="{{ route('admin.production-studio.assets.identity-correction', [$project, $asset]) }}" data-studio-ai-form class="mt-2 grid w-full grid-cols-1 gap-2 rounded-lg border border-amber-200 bg-amber-50 p-2">
                    @csrf
                    <p class="text-xs font-black text-amber-900">تصحيح الهوية: يحافظ على المشهد ويحاول مطابقة وجه الطفل مع صورة الوجه الأساسية.</p>
                    <select name="model_code" required class="rounded-lg border-amber-200 text-xs font-bold">
                        @forelse($identityCorrectionModels as $model)
                            <option value="{{ $model->code }}">{{ $model->display_name }}</option>
                        @empty
                            <option value="">لا يوجد نموذج OpenAI متاح</option>
                        @endforelse
                    </select>
                    <select name="generation_quality" class="rounded-lg border-amber-200 text-xs font-bold">
                        <option value="high">Final · high</option>
                        <option value="medium">Draft · medium</option>
                    </select>
                    <input name="prompt_notes" class="rounded-lg border-amber-200 text-xs" placeholder="ملاحظة اختيارية عن اختلاف الوجه">
                    <button @disabled($identityCorrectionModels->isEmpty()) class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-black text-white disabled:bg-gray-300">تصحيح هوية الطفل</button>
                    <div data-studio-ai-feedback class="hidden rounded-lg border p-2 text-xs font-bold"></div>
                </form>
            @endcan
        @endif
        @if($asset->is_primary)
            <span class="inline-flex rounded-full bg-emerald-50 px-2 py-1 text-xs font-black text-emerald-700">الصورة المرجعية المعتمدة</span>
        @endif
        @if($asset->is_final)
            <span class="inline-flex rounded-full bg-indigo-50 px-2 py-1 text-xs font-black text-indigo-700">Final</span>
        @endif
        @if($asset->rejection_reason)
            <p class="rounded-lg bg-red-50 px-2 py-1 text-xs font-bold text-red-700">سبب الرفض: {{ $asset->rejection_reason }}</p>
        @endif
    </div>
    @if($asset->generationJob)
        <details class="mt-3 rounded-lg bg-gray-50 p-2 text-xs text-gray-600">
            <summary class="cursor-pointer font-black text-indigo-700">تفاصيل التوليد والمراجع</summary>
            <div class="mt-2 space-y-2">
                @can('production_studio.ai_view_costs')
                    <p>Cost: estimated ${{ $asset->generationJob->estimated_cost ?? '0.0000' }} / actual ${{ $asset->generationJob->actual_cost ?? '-' }}</p>
                @endcan
                <p>Quality: {{ data_get($asset->generationJob->provider_request_json, 'generation_quality', data_get($asset->generationJob->provider_request_json, 'model_settings.quality', 'medium')) }}</p>
                <p>Identity Lock: {{ data_get($asset->generationJob->provider_request_json, 'identity_lock') ? 'ON' : 'OFF' }}</p>
                <div>
                    <p class="font-black text-gray-800">References:</p>
                    @foreach(data_get($asset->generationJob->input_assets_json, 'reference_assets', []) as $reference)
                        <p>{{ data_get($reference, 'type') }} {{ data_get($reference, 'photo_index') !== null ? '#'.(((int) data_get($reference, 'photo_index')) + 1) : '' }} {{ data_get($reference, 'asset_id') ? 'asset #'.data_get($reference, 'asset_id') : '' }}</p>
                    @endforeach
                </div>
                <details>
                    <summary class="cursor-pointer font-black text-gray-800">Prompt snapshot</summary>
                    <pre dir="ltr" class="mt-2 max-h-56 overflow-auto rounded bg-white p-2 text-left">{{ $asset->generationJob->prompt_snapshot }}</pre>
                </details>
            </div>
        </details>
    @endif
    <div class="mt-3 flex flex-wrap gap-2">
        @can('production_studio.ai_approve')
            <form method="POST" action="{{ route('admin.production-studio.assets.approve', [$project, $asset]) }}" data-studio-ai-form>
                @csrf
                <button @disabled($identityBlocksApproval) title="{{ $identityBlocksApproval ? 'أكمل فحص أو تصحيح الهوية أولًا' : '' }}" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-black text-white disabled:cursor-not-allowed disabled:bg-gray-300">اعتماد</button>
            </form>
            <form method="POST" action="{{ route('admin.production-studio.assets.reject', [$project, $asset]) }}" data-studio-ai-form class="flex gap-1">
                @csrf
                <input name="rejection_reason" required class="w-28 rounded-lg border-gray-300 text-xs" placeholder="سبب الرفض">
                <button class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-black text-red-700">رفض</button>
            </form>
        @endcan
        @if($asset->generationJob)
            @can('production_studio.ai_retry')
                <form method="POST" action="{{ route('admin.production-studio.ai.retry', [$project, $asset->generationJob]) }}" data-studio-ai-form>
                    @csrf
                    <button class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-black text-gray-700">Retry</button>
                </form>
            @endcan
        @endif
        @if($deletableGeneratedImage)
            @can('production_studio.ai_approve')
                <form method="POST" action="{{ route('admin.production-studio.assets.delete', [$project, $asset]) }}" data-studio-ai-form data-confirm="سيتم حذف ملف الصورة المولدة نهائيًا ولا يمكن استعادته. هل تريد المتابعة؟">
                    @csrf
                    @method('DELETE')
                    <button class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-black text-red-700 hover:bg-red-50">حذف نهائي</button>
                </form>
            @endcan
        @endif
    </div>
    <div data-studio-ai-feedback class="mt-3 hidden rounded-xl border p-3 text-xs font-bold"></div>
</div>
