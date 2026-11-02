# مراجعة عينة قرارات HeroKid — Phase 1

**الحالة: في انتظار اعتمادك لعينة التقييم وتوقعاتها.** اعتماد العينة لا يفعّل التنفيذ ولا ينشر أي تغيير.

العينة: 37 Checkout فعليًا، 89 هدف قرار، التُقطت في 2 نوفمبر 2026 من إصدار `77ef1cc`. تمت القراءة داخل معاملة MySQL للقراءة فقط. لم تُنقل أسماء أو هواتف أو صور أو رسائل أو مبالغ أو روابط أو أرقام الطلبات الأصلية. المعرفات أدناه بديلة داخل كل حالة فقط.

التوقعات صيغت يدويًا من الحقائق وقواعد العمل، وليست إجابات مولّدة بالموديل. النتيجة الحالية باستخدام Mock تقيس اتساق الكود والتغطية؛ لا تثبت دقة Codex. شرط الاختبار الحقيقي: ≥95% صحة على العينة المعتمدة، صفر إخفاقات أمان حرجة غير محلولة، وعزل ومصادقة اشتراك مثبتان.

حالات التوليد الجديدة الناجحة نادرة في اللقطة لأن الأوتوميشن الحالي يلتقطها سريعًا. حالات البازل/المج/القصة الجاهزة، نقص الصور، A3، تعارض الدفعات والحقن الخبيث ستُختبر أيضًا بحالات مصنّعة منفصلة بوضوح. هذه ليست عينة إحصائية لقياس كل التشغيل.

| الحالة | السيناريو الفعلي | القرارات المتوقعة |
|---|---|---|
| real-01 | Manual hold with delivered status still active in lifecycle | MANUAL_HOLD |
| real-02 | Cancelled story retains manual hold | MANUAL_HOLD |
| real-03 | Two approved stories stopped under manual hold | MANUAL_HOLD |
| real-04 | Revision checkout with three independent products and preserved images | MANUAL_HOLD |
| real-05 | Two approved paid stories held pending human review | MANUAL_HOLD |
| real-06 | Running story with partial scenes | EXISTING_WORK_IN_PROGRESS |
| real-07 | Running story plus pending payment review | PAYMENT_REVIEW_REQUIRED؛ EXISTING_WORK_IN_PROGRESS |
| real-08 | Completed scenes awaiting staff output review | OUTPUT_REVIEW_AND_STUDIO |
| real-09 | Completed story awaiting Studio | OUTPUT_REVIEW_AND_STUDIO |
| real-10 | Completed story with preserved results | OUTPUT_REVIEW_AND_STUDIO |
| real-11 | Story already in production queue | EXISTING_WORK_IN_PROGRESS |
| real-12 | Queued story plus payment review | PAYMENT_REVIEW_REQUIRED؛ EXISTING_WORK_IN_PROGRESS |
| real-13 | Two approved book covers; interior awaits recorded payment | PAYMENT_REVIEW_REQUIRED؛ OUTPUT_REVIEW_AND_STUDIO ×2؛ RECORDED_PAYMENT_REQUIRED ×2 |
| real-14 | Identity change requested and pending payment review | CUSTOMER_REQUESTED_CHANGES ×2 |
| real-15 | Two completed stories and payment verification | PAYMENT_REVIEW_REQUIRED؛ OUTPUT_REVIEW_AND_STUDIO ×2 |
| real-16 | Completed story and payment verification | PAYMENT_REVIEW_REQUIRED؛ OUTPUT_REVIEW_AND_STUDIO |
| real-17 | New personalized image awaiting confirmation | CONFIRMATION_REQUIRED ×2 |
| real-18 | New image checkout awaiting confirmation | CONFIRMATION_REQUIRED ×2 |
| real-19 | New story awaiting confirmation | CONFIRMATION_REQUIRED ×2 |
| real-20 | New unsupported product plus confirmation | CONFIRMATION_REQUIRED؛ UNSUPPORTED_CAPABILITY |
| real-21 | Successful image plus blocked sibling attempt | OUTPUT_REVIEW_AND_STUDIO؛ PRESERVE_OUTPUT_REVIEW_ATTEMPT |
| real-22 | Customer requests identity changes | CUSTOMER_REQUESTED_CHANGES ×2 |
| real-23 | Mixed book covers with two uncertain external results | OUTPUT_REVIEW_AND_STUDIO؛ CUSTOMER_COVER_APPROVAL_REQUIRED ×3؛ EXTERNAL_RESULT_UNCERTAIN ×2 |
| real-24 | Three completed stories plus unsupported add-ons | PAYMENT_REVIEW_REQUIRED؛ OUTPUT_REVIEW_AND_STUDIO ×3؛ UNSUPPORTED_CAPABILITY ×3 |
| real-25 | Four stories and nine independent product units | PRESERVE_OUTPUT_REVIEW_ATTEMPT ×2؛ OUTPUT_REVIEW_AND_STUDIO ×2؛ READY_FOR_PRODUCTION_PREFLIGHT ×3؛ UNSUPPORTED_CAPABILITY ×6 |
| real-26 | Existing manual outputs and unsupported siblings | PRESERVE_OUTPUT_REVIEW_ATTEMPT؛ UNSUPPORTED_CAPABILITY ×3 |
| real-27 | Multi-component products with existing outputs | PRESERVE_OUTPUT_REVIEW_ATTEMPT ×3؛ UNSUPPORTED_CAPABILITY ×6 |
| real-28 | Mixed held checkout including completed story | MANUAL_HOLD |
| real-29 | Inactive mixed checkout retains history | LIFECYCLE_COMPLETE |
| real-30 | Cancelled multi-component product | LIFECYCLE_COMPLETE |
| real-31 | Cancelled product with historical outputs | LIFECYCLE_COMPLETE |
| real-32 | Delivered labels with active lifecycle and retained outputs | OUTPUT_REVIEW_AND_STUDIO ×3 |
| real-33 | Delivered product still active for remaining lifecycle | OUTPUT_REVIEW_AND_STUDIO |
| real-34 | Old approved unpaid story awaiting customer | RECORDED_PAYMENT_REQUIRED |
| real-35 | Old story with missing identity workflow | IDENTITY_WORKFLOW_REQUIRED |
| real-36 | Old delivered-label story outside production stage | EXECUTOR_OR_STAGE_UNAVAILABLE |
| real-37 | Second delivered-label story outside production stage | EXECUTOR_OR_STAGE_UNAVAILABLE |

تفسير القرارات: انتظار الموافقة أو دفعة فعلية يظل انتظارًا. النتائج الموجودة/المتوقفة تُراجع بشريًا، ولا تُعاد تلقائيًا. المحاولة غير المؤكدة تُصعّد. المنتجات غير المدعومة تحتاج موظفًا. `READY_FOR_PRODUCTION_PREFLIGHT` يعني اقتراح فحص أهلية الخدمة الأصلية؛ لا يعني أن الإنتاج بدأ أو أن كل فحوص الملفات/المزوّد نجحت.

الطلبات التي تحمل تسمية delivered قد تظل نشطة حسب سجل دورة التشغيل الحالي. لم نغيّر تفسير دورة التشغيل. تظل أرقام التقييم منفصلة عن نتائج Codex الحقيقية، وسيُعرض اختلاف الاقتراح مع الأوتوميشن الحالي بدل إخفائه.
