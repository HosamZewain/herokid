<div class="space-y-2 text-right {{ ($detailed ?? false) ? '' : 'max-w-48' }}" data-order-marketing-source>
    @foreach($sources as $source)
        <div class="min-w-0">
            <span class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-black text-amber-700">{{ $source['label'] }}</span>
            @if(filled($source['ad_name']))
                <p class="mt-1 max-w-xs break-words text-xs font-bold text-gray-800" title="{{ $source['ad_name'] }}">الإعلان: {{ $source['ad_name'] }}</p>
            @elseif($source['type'] === 'meta_ad')
                <p class="mt-1 text-[10px] text-gray-500">اسم الإعلان غير مسجل</p>
            @endif
            @if(filled($source['campaign_name']) || filled($source['campaign']))
                <p class="mt-1 max-w-xs break-words text-[11px] text-gray-600">{{ $source['campaign_name'] ? 'الحملة:' : 'الحملة (UTM):' }} {{ $source['campaign_name'] ?: $source['campaign'] }}</p>
            @endif
            @if($detailed ?? false)
                <dl class="mt-2 grid grid-cols-1 gap-1 text-[11px] text-gray-600 sm:grid-cols-2">
                    @foreach([
                        'campaign_id' => 'معرف الحملة', 'adset_name' => 'مجموعة الإعلانات',
                        'adset_id' => 'معرف مجموعة الإعلانات', 'ad_id' => 'معرف الإعلان',
                        'source' => 'المصدر (UTM)', 'medium' => 'الوسيط (UTM)',
                        'campaign' => 'الحملة (UTM)', 'content' => 'المحتوى (UTM)', 'term' => 'الكلمة (UTM)',
                        'referrer_host' => 'موقع الإحالة', 'landing_site' => 'موقع الوصول',
                    ] as $field => $label)
                        @if(filled($source[$field]))
                            <div class="min-w-0 break-words"><dt class="inline font-bold">{{ $label }}:</dt> <dd class="inline"><bdi>{{ $source[$field] }}</bdi></dd></div>
                        @endif
                    @endforeach
                    @if($source['meta_click'])<div class="font-bold">تم تسجيل نقرة من ميتا (ليست دليلاً وحدها على إعلان)</div>@endif
                </dl>
            @endif
        </div>
    @endforeach
</div>
