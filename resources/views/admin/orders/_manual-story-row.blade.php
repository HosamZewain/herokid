@php
    $row = $row ?? [];
    $rowNumber = is_numeric($index) ? ((int) $index + 1) : '#';
    $existingOrderId = $row['existing_order_id'] ?? null;
@endphp

<article class="rounded-3xl border border-violet-100 bg-violet-50/40 p-4 sm:p-6" data-story-row data-story-index="{{ $index }}">
    @if($existingOrderId)
        <input type="hidden" name="stories[{{ $index }}][existing_order_id]" value="{{ $existingOrderId }}">
    @endif
    <div class="mb-5 flex items-center justify-between gap-3">
        <button type="button" class="rounded-lg bg-red-50 px-3 py-2 text-xs font-black text-red-600 hover:bg-red-100" data-remove-story>حذف القصة</button>
        <div class="text-right">
            <h3 class="text-base font-black text-violet-950">القصة <span data-story-number>{{ $rowNumber }}</span></h3>
            @if($existingOrderId)<p class="mt-1 text-[10px] font-bold text-gray-500">{{ $row['order_number'] ?? '' }} — محفوظة بصورها وسجلها الحالي</p>@endif
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <div class="md:col-span-2">
            <label for="story-{{ $index }}" class="mb-1.5 block text-xs font-black text-gray-700">اختيار القصة *</label>
            <select id="story-{{ $index }}" name="stories[{{ $index }}][story_id]" required class="w-full rounded-xl border-gray-200 bg-white text-right text-sm" data-story-select>
                <option value="">اختر القصة</option>
                @foreach($stories as $story)
                    @php
                        $pricing = $storyPrices[$story->id];
                        $storyPriceCents = $existingOrderId && (string) ($row['story_id'] ?? '') === (string) $story->id && isset($row['story_price_cents'])
                            ? (int) $row['story_price_cents']
                            : (int) round($pricing['effective_price'] * 100);
                    @endphp
                    <option value="{{ $story->id }}" data-price-cents="{{ $storyPriceCents }}" @selected((string) ($row['story_id'] ?? '') === (string) $story->id)>
                        {{ $story->title }} — {{ format_money($storyPriceCents / 100) }}
                    </option>
                @endforeach
            </select>
            @if(is_numeric($index)) @error("stories.$index.story_id")<p class="mt-1 text-xs font-bold text-red-600">{{ $message }}</p>@enderror @endif
        </div>

        <div>
            <label for="child-name-{{ $index }}" class="mb-1.5 block text-xs font-black text-gray-700">اسم الطفل *</label>
            <input id="child-name-{{ $index }}" name="stories[{{ $index }}][child_name]" value="{{ $row['child_name'] ?? '' }}" required maxlength="100" class="w-full rounded-xl border-gray-200 text-right text-sm" data-child-name>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="child-age-{{ $index }}" class="mb-1.5 block text-xs font-black text-gray-700">العمر *</label>
                <select id="child-age-{{ $index }}" name="stories[{{ $index }}][child_age]" required class="w-full rounded-xl border-gray-200 bg-white text-right text-sm">
                    <option value="">اختر</option>
                    @foreach(\App\Support\StoryAgeOptions::forPersonalization() as $age)
                        <option value="{{ $age }}" @selected((string) ($row['child_age'] ?? '') === (string) $age)>{{ $age }} سنوات</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="child-gender-{{ $index }}" class="mb-1.5 block text-xs font-black text-gray-700">الجنس *</label>
                <select id="child-gender-{{ $index }}" name="stories[{{ $index }}][child_gender]" required class="w-full rounded-xl border-gray-200 bg-white text-right text-sm">
                    <option value="">اختر</option>
                    <option value="boy" @selected(($row['child_gender'] ?? '') === 'boy')>ولد</option>
                    <option value="girl" @selected(($row['child_gender'] ?? '') === 'girl')>بنت</option>
                </select>
            </div>
        </div>

        <div class="md:col-span-2">
            <label for="child-photos-{{ $index }}" class="mb-1.5 block text-xs font-black text-gray-700">
                {{ $existingOrderId ? 'إضافة صور جديدة للطفل (اختياري — حتى 3 صور)' : 'صور الطفل — صورتان أو 3 صور *' }}
            </label>
            <input id="child-photos-{{ $index }}" name="stories[{{ $index }}][photos][]" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" multiple @required(! $existingOrderId) class="block w-full rounded-xl border border-dashed border-violet-300 bg-white p-3 text-sm file:ml-3 file:rounded-lg file:border-0 file:bg-violet-600 file:px-4 file:py-2 file:font-black file:text-white" data-photo-input>
            <p class="mt-2 text-xs font-bold text-gray-500" data-photo-names>
                @if($existingOrderId)
                    الصور المحفوظة حاليًا: {{ (int) ($row['photo_count'] ?? 0) }}. لن تُحذف عند الحفظ، ويمكنك إرفاق صور إضافية.
                @else
                    لم يتم اختيار صور.
                @endif
            </p>
            @if(is_numeric($index)) @error("stories.$index.photos")<p class="mt-1 text-xs font-bold text-red-600">{{ $message }}</p>@enderror @endif
        </div>

        <div>
            <label for="interests-{{ $index }}" class="mb-1.5 block text-xs font-black text-gray-700">اهتمامات الطفل <span class="font-normal text-gray-400">اختياري</span></label>
            <textarea id="interests-{{ $index }}" name="stories[{{ $index }}][interests]" rows="2" class="w-full rounded-xl border-gray-200 text-right text-sm">{{ $row['interests'] ?? '' }}</textarea>
        </div>
        <div>
            <label for="gift-note-{{ $index }}" class="mb-1.5 block text-xs font-black text-gray-700">الإهداء <span class="font-normal text-gray-400">اختياري</span></label>
            <textarea id="gift-note-{{ $index }}" name="stories[{{ $index }}][gift_note]" rows="2" class="w-full rounded-xl border-gray-200 text-right text-sm">{{ $row['gift_note'] ?? '' }}</textarea>
        </div>
        <div class="md:col-span-2">
            <label for="parent-notes-{{ $index }}" class="mb-1.5 block text-xs font-black text-gray-700">ملاحظات العميل لهذه القصة <span class="font-normal text-gray-400">اختياري</span></label>
            <textarea id="parent-notes-{{ $index }}" name="stories[{{ $index }}][parent_notes]" rows="2" class="w-full rounded-xl border-gray-200 text-right text-sm">{{ $row['parent_notes'] ?? '' }}</textarea>
        </div>
    </div>
</article>
