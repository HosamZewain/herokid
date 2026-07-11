@php
    $statusTone = match ($job->status) {
        'completed', 'approved' => 'emerald',
        'failed', 'rejected' => 'red',
        'processing', 'queued' => 'indigo',
        default => 'gray',
    };
@endphp

<div class="rounded-xl bg-white p-3 text-sm ring-1 ring-gray-100" data-studio-job-row="{{ $job->id }}">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div class="text-right">
            <p class="font-black text-gray-900" data-studio-job-label>
                #{{ $job->id }} - {{ $job->job_type }}
                @if($job->scene)
                    — المشهد {{ $job->scene->scene_number }}: {{ $job->scene->title ?: 'بدون عنوان' }}
                @endif
                @if(data_get($job->output_metadata_json, 'asset_id') && $job->assets->first())
                    — v{{ $job->assets->first()->version_number }}
                @endif
            </p>
            <p class="mt-1 text-xs text-gray-500">{{ $job->model?->display_name ?? $job->generation_mode }} · {{ $job->created_at?->diffForHumans() }}</p>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
            @include('admin.production-studio.partials.status-badge', ['label' => $job->status, 'tone' => $statusTone])
            @can('production_studio.ai_view_costs')
                <span class="text-xs font-bold text-gray-500">estimated ${{ $job->estimated_cost ?? '0.0000' }} / actual ${{ $job->actual_cost ?? '-' }}</span>
            @endcan
        </div>
    </div>

    @if($job->error_message)
        <details class="mt-2 rounded-lg bg-red-50 p-2">
            <summary class="cursor-pointer text-xs font-black text-red-700">عرض سبب الفشل</summary>
            <p class="mt-2 text-xs font-bold text-red-700" data-studio-job-error>{{ $job->error_message }}</p>
        </details>
    @else
        <p data-studio-job-error class="mt-2 text-xs font-bold text-red-600"></p>
    @endif

    <details class="mt-2">
        <summary class="cursor-pointer text-xs font-black text-indigo-700">عرض prompt snapshot</summary>
        <pre dir="ltr" class="mt-2 max-h-56 overflow-auto rounded bg-slate-50 p-2 text-left text-xs">{{ $job->prompt_snapshot }}</pre>
    </details>
</div>
