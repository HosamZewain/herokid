# علاج بطء صور المعاينة والإدارة — 5 نوفمبر 2026

مرجع البداية المراجع على AWS وGitHub: b4de4e60018535e61827e90e16143b48e2c81590. تنفيذ معزول codex/preview-media-latency-20261104، مع حفظ تعديلات الموبايل/الواجهة في النسخة الرئيسية.

المالك أكد استمرار البطء بعد تحسين polling والصلاحيات. قياس الرابط المرسل manifest60: HTML200/TTFB0.368ثانية/8284بايت. المتصفح المعزول الفعلي: DOM0.487ثانية، أول صورة49.9ثانية، load50.2ثانية. خمس صور متتابعة من عميلHTTP آخر: TTFB8.9–13.6ثانية،250–363KB لكل صورة محمية. نافذةNginx تضمنت نقل صور أصلية2–3MB في15–24ثانية، رغم أن upstreamبعضها أقلمن0.5ثانية؛ هذه مشكلة نقل منفصلة عن الرسم.

فحصCLIقراءة فقط للصورة الأولى نفسها: availability10ms،S3get+SHA271ms/2434092بايت، watermark7913ms. المرشح على نفس الأصل: S3get272ms،watermark1175ms. ظهرت تحذيراتFontconfigفيالأداتين؛ لا نمحوها أو نعتبرها أعطالطلب. اختبارصورةاصطناعية1400×1400علىPHP8.3:220→125ms؛ معاينة بصرية تؤكد التكرارالقطري للعلامة المائية.

التغيير:

- رسم بلاطةعلامة مائية صغيرة وتكرارها على الصورة، بدل مئاتالرسمات على الصورةالكاملة وخارجحدودها. تبقى JPEGبحد1400وجودة80، علامةمائيةإجباريةوشروطالخصوصيةكماهي.
- إعادةاستخدام نسخةJPEGالمحمية بخاصيةSHAللأصلوإعداداتالعلامةوالنسخة؛ تحقق أصلmanifestبالـSHAوصلاحيةالرابط/الـgallery/الطلب فيكلGETقبلالكاش. لاتخزينعام أو تغييرأجلروابط أو اعتماد/إرسال. الكاشمحليخاص تحتorder-thumbnails/protected،كتابةمؤقتةثمrename،وتنظيف7أيامبالمهمةالحالية؛الأصولوS3والتاريخمحفوظون.
- كروتالمشاهدومحاولاتالهويةتستخدم400pxthumbnail،الفتحالمباشرللأصلدونتغيير. صلاحياتوصلةالطلب/المحاولةقبلالكاش،304خاصمشروطبإعادةالتحقق،ولا مساراتجديدة.
- حذفحسابmetadataالمكررلنفسthumbnail،وعدّاداتالأوتوميشن تقرأحقولOrderالعدديةدونhydrationلكلنماذجالتاريخ. الاختبارالقديمكان110بدل50فيالنسخةالحيةأيضًا؛ أُصلحالمسارفعليًا بدلإضعافالاختبار. لاكاشصلاحياتأوعداداتبينالمستخدمين.

النشر المطلوبكودوقوالبفقط: لامهاجراتأوenv/config/routes/مواردAWSأوإعادةتشغيلعمال أوتوليدتاريخي. بناءlockfileمعالحفاظعلىالأصولالقديمة؛ viewcache/FPMفقط. قسمrunbookالمؤرخ سيحملSHAوالتحققونقطةالاسترجاعبعدالنشر.

## التحقق المعزول

PHP 8.3.3: نجح 238 اختبارًا / 2782 تأكيدًا على كل من SQLite وMySQL 8.4، مع HTTP خارجي ممنوع ووسائط وبيانات اصطناعية. تغطي الاختبارات الروابط القديمة والموقعة، إلغاء الإتاحة بعد تجهيز الكاش، رفض أصل تغيرت بصمته، تغير إعداد العلامة، هوية/مشاهد مصغرة مع 304 ومنع الوصول المتقاطع وسحب الصلاحيات، والفلاتر والعدادات والدفعات والاستئناف. نجح Pint وdiff والبناء من lockfile؛ manifest والأصول لم تتغير وكل الأصول القديمة محفوظة. فشل اختبار hydration في أول جولة وثبت نفس الفشل على baseline؛ أصلحنا تحميل الحقول لا الاختبار، والجولة النهائية كاملة ناجحة على القاعدتين.

## Initial publication and cold-render follow-up

Application 2d5e5d707240a2723a3601762c278459b456508f published on AWS at 2026-11-04T22:08:25Z (5 November Cairo). Recovery /home/ubuntu/herokid-before-preview-latency-20261105. All source hashes and private config/environment/routes/manifest bytes verified; only Blade cache/FPM reload, worker PIDs preserved. Eight services active, scheduler success, failed_jobs83 unchanged. Initial80HTTP requests/zero5xx, zero new Laravel/Nginx errors. Exact preview browser: cold load14.955s/all5images15.449s; repeat load3.071s/all5images3.555s. Cold rendering therefore still needs improvement despite warm-cache gain; no blanket subsecond claim.

Follow-up renders the text once as a stamp, copies it into the repeating tile, and limits nested Imagick threads to1 during this render only, restoring the original resource limit even on failure. Cache renderer version4 separates the new derivative. Same original read-only CLI candidate: source216ms, render759ms. Synthetic visual QA shows the mandatory repeated diagonal text. PHP8.3 full SQLite/MySQL regression each238tests/2782assertions passed after stamp change; final resource-limit/corrupt-image regressions each9tests/121assertions passed. No infrastructure/config/worker change is needed; a scoped FPM refresh only. Final live evidence follows in the runbook.
