@if(config('admin_assistant.enabled') && auth()->user()?->is_active && !auth()->user()?->agent_api_enabled && auth()->user()?->hasAnyPermission(['orders.view', 'sales_reports.view']))
<div data-admin-assistant data-ask="{{ route('admin.assistant.ask') }}" data-access="{{ route('admin.assistant.access') }}">
    <button type="button" data-assistant-open class="assistant-launch" aria-haspopup="dialog" aria-controls="admin-assistant-dialog">✦ <span>اسأل Auto Pilot</span></button>
    <dialog id="admin-assistant-dialog" class="assistant-dialog" aria-labelledby="assistant-title" dir="rtl">
        <header class="assistant-header"><div><span class="assistant-eyebrow">HEROKID / ASSISTANT</span><h2 id="assistant-title">مساعد Auto Pilot</h2><p>إجابة من بيانات النظام، في حدود صلاحياتك.</p></div><button type="button" data-assistant-close aria-label="إغلاق المساعد">×</button></header>
        <div class="assistant-context"><span>◉ قراءة وتشخيص</span><span data-assistant-context>الصفحة الحالية</span></div>
        <div data-assistant-messages class="assistant-messages" role="log" aria-live="polite" aria-relevant="additions text">
            <div class="assistant-welcome"><h3>نبدأ بإيه؟</h3><p>اسأل عن أوردر، سبب توقفه، أو أرقام تقرير متاح لك. كل إجابة معها مصدرها.</p></div>
        </div>
        <div class="assistant-suggestions"><button type="button" data-assistant-suggest="اشرحلي الصفحة الحالية">اشرح الصفحة</button><button type="button" data-assistant-suggest="إيه الطلبات النشطة في نطاقي؟">الطلبات النشطة</button><button type="button" data-assistant-reset>محادثة جديدة</button></div>
        <form data-assistant-form class="assistant-form"><label class="sr-only" for="assistant-question">سؤالك</label><textarea id="assistant-question" name="question" maxlength="2000" rows="2" placeholder="مثلاً: الأوردر HK11-117 واقف ليه؟" required></textarea><button type="submit" data-assistant-submit>إرسال ←</button></form>
        <p class="assistant-footnote" data-assistant-status role="status">لا يرسل رسائل للعملاء أو يشغّل الأوردرات من الشات في هذه المرحلة.</p>
    </dialog>
</div>
@endif
