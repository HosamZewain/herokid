@php($tagInputId = 'order-tag-'.$mode.'-'.$group['representative_id'])

<div class="flex flex-wrap items-start gap-1" data-order-list-tag-manager="{{ $group['representative_id'] }}">
    <span class="contents" data-order-list-tag-list>
        @forelse($group['tags'] as $tag)
            <a href="{{ route('admin.orders.index', array_merge(request()->except('page'), ['tag_id' => $tag->id])) }}" class="rounded-full bg-fuchsia-50 px-2.5 py-1 text-[10px] font-black text-fuchsia-700 hover:bg-fuchsia-100">#{{ $tag->name }}</a>
        @empty
            <span class="text-[10px] font-bold text-gray-300" data-order-list-tags-empty>—</span>
        @endforelse
    </span>

    @if(!$group['trashed'])
        <details data-order-list-tag-add-panel>
            <summary class="grid h-6 w-6 cursor-pointer list-none place-items-center rounded-full border border-fuchsia-200 bg-white text-sm font-black leading-none text-fuchsia-700 hover:bg-fuchsia-50 [&::-webkit-details-marker]:hidden" aria-label="إضافة علامة" title="إضافة علامة">+</summary>
            <div class="mt-2 w-48 rounded-xl border border-fuchsia-100 bg-white p-2 shadow-lg">
                <form method="POST" action="{{ route('admin.orders.groups.tags.store', $group['representative_id']) }}" data-order-list-tag-add>
                    @csrf
                    <label for="{{ $tagInputId }}" class="sr-only">إضافة علامة</label>
                    <input id="{{ $tagInputId }}" name="tag" type="text" list="{{ $tagInputId }}-suggestions" value="" maxlength="40" autocomplete="off" placeholder="اكتب واضغط Enter" class="min-h-9 w-full rounded-lg border-gray-200 px-2 text-right text-xs" data-order-list-tag-input>
                    <datalist id="{{ $tagInputId }}-suggestions" data-order-list-tag-suggestions>
                        @foreach($filterTags as $availableTag)
                            @unless($group['tags']->contains('id', $availableTag->id))<option value="{{ $availableTag->name }}"></option>@endunless
                        @endforeach
                    </datalist>
                </form>
            </div>
        </details>
    @endif

    <span class="hidden text-[10px] font-black" data-order-list-tag-feedback aria-live="polite"></span>
</div>
