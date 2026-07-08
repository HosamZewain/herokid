<div class="rounded-xl border border-gray-100 bg-white p-3 text-right shadow-sm" data-studio-asset-card="{{ $asset->id }}">
    @if($asset->file_path)
        <a href="{{ route('admin.production-studio.assets.show', [$project, $asset]) }}" target="_blank" class="block overflow-hidden rounded-lg bg-gray-100">
            <img src="{{ route('admin.production-studio.assets.show', [$project, $asset]) }}" alt="{{ $asset->label }}" class="aspect-square w-full object-cover">
        </a>
    @endif
    <div class="mt-3 space-y-1">
        <p class="font-black text-gray-900">{{ $asset->label ?? $asset->asset_type }}</p>
        <p class="text-xs text-gray-500">v{{ $asset->version_number }} - {{ $asset->status }}</p>
        @if($asset->is_primary)
            <span class="inline-flex rounded-full bg-emerald-50 px-2 py-1 text-xs font-black text-emerald-700">Primary</span>
        @endif
        @if($asset->is_final)
            <span class="inline-flex rounded-full bg-indigo-50 px-2 py-1 text-xs font-black text-indigo-700">Final</span>
        @endif
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
        @can('production_studio.ai_approve')
            <form method="POST" action="{{ route('admin.production-studio.assets.approve', [$project, $asset]) }}" data-studio-ai-form>
                @csrf
                <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-black text-white">اعتماد</button>
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
    </div>
    <div data-studio-ai-feedback class="mt-3 hidden rounded-xl border p-3 text-xs font-bold"></div>
</div>
