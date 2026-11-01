@php($timing = \App\Services\CodexScenes\SceneTiming::describe($timingRun))
<p>دخل الطابور: {{ app_datetime($timingRun->created_at,'d/m/Y h:i:s A') }}</p>
<p>انتظار وتجهيز: <bdi>{{ $timing['wait'] }}</bdi></p>
@if($timing['start'])<p>بدأ التوليد: {{ app_datetime($timing['start'],'d/m/Y h:i:s A') }}</p>@endif
<p class="font-bold">{{ $timing['live'] ? 'الوقت المنقضي' : 'مدة التوليد' }}: <bdi>{{ $timing['duration'] }}</bdi> <span class="font-normal">({{ $timing['source'] === 'worker' ? 'قياس عامل التوليد' : 'تقريبي حسب رصد السيستم' }})</span></p>
@if($timing['end'])<p>انتهى: {{ app_datetime($timing['end'],'d/m/Y h:i:s A') }}</p>@endif
