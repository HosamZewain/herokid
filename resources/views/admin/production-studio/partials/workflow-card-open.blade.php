@php
    $warning = $warning ?? null;
    $statusTone = $statusTone ?? 'gray';
    $summary = $summary ?? null;
@endphp

<section id="{{ $id }}" class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm" data-studio-section="{{ $id }}">
    <button type="button"
            class="flex w-full flex-col gap-3 bg-white p-5 text-right transition hover:bg-gray-50 md:flex-row md:items-center md:justify-between"
            data-studio-section-toggle="{{ $id }}"
            aria-controls="{{ $id }}-panel"
            aria-expanded="true">
        <span class="flex-1">
            <span class="block text-lg font-black text-gray-950">{{ $title }}</span>
            <span class="mt-1 block text-sm leading-6 text-gray-500">{{ $description }}</span>
            @if($summary)
                <span class="mt-2 block text-xs font-bold text-gray-500">{{ $summary }}</span>
            @endif
        </span>
        <span class="flex flex-wrap items-center justify-end gap-2">
            @if($warning)
                @include('admin.production-studio.partials.status-badge', ['label' => $warning, 'tone' => 'amber'])
            @endif
            @include('admin.production-studio.partials.status-badge', ['label' => $status, 'tone' => $statusTone])
            <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-black text-gray-500" data-studio-section-icon>−</span>
        </span>
    </button>
    <div id="{{ $id }}-panel" class="border-t border-gray-100 p-5" data-studio-section-panel>
